<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Orchestrates image generation for published articles.
 *
 * Generates two images per article (A/B): Image A (article-driven) as featured
 * image, Image B (source-driven) in post meta. Each is independently fallible.
 * Since v0.17.0 every result passes through the deterministic image composer
 * (branded variants for A, corner mark for B) before sideloading; composition
 * degrades gracefully and never blocks a publish.
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner after publisher creates the post.
 * Dependencies: Image provider, Prompt builder, Composer, Attacher, Cost tracker, Logger.
 *
 * @see core/class-pipeline-runner.php       — Calls this after publisher.
 * @see core/class-image-prompt-builder.php  — Prompt generation (scene + caption).
 * @see core/class-image-composer.php        — Deterministic variant composition.
 * @see core/class-image-attacher.php        — Sideloading + variant meta-linking.
 */
class PRAutoBlogger_Image_Pipeline {

	/**
	 * Default image dimensions (landscape). 1200×632 is the closest
	 * 8-aligned pair to the standard OG image size (1200×630). Some
	 * providers require dimensions divisible by 8.
	 */
	private const DEFAULT_WIDTH  = 1200;
	private const DEFAULT_HEIGHT = 632;

	/** @var PRAutoBlogger_Image_Provider_Interface Image gen provider. */
	private PRAutoBlogger_Image_Provider_Interface $provider;
	/** @var PRAutoBlogger_Image_Prompt_Builder Builds scene + caption from article data. */
	private PRAutoBlogger_Image_Prompt_Builder $prompt_builder;
	/** @var PRAutoBlogger_Image_Media_Sideloader Downloads images into WP media library. */
	private PRAutoBlogger_Image_Media_Sideloader $sideloader;
	/** @var PRAutoBlogger_Cost_Tracker Logs image generation spend. */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;
	/** @var PRAutoBlogger_Image_Composer_Interface Composes branded variants pre-sideload. */
	private PRAutoBlogger_Image_Composer_Interface $composer;
	/** @var PRAutoBlogger_Image_Attacher Sideloads + meta-links results and variants. */
	private PRAutoBlogger_Image_Attacher $attacher;
	/** @var PRAutoBlogger_Opik_Trace_Context|null Optional tracing context. */
	private ?PRAutoBlogger_Opik_Trace_Context $trace_context;

	/**
	 * Construct with dependencies. When no provider is injected, the
	 * `prautoblogger_image_provider` setting picks the concrete class
	 * ('runware' or 'openrouter').
	 *
	 * @param PRAutoBlogger_Image_Provider_Interface|null $provider Optional provider override.
	 * @param PRAutoBlogger_Cost_Tracker|null             $cost_tracker Optional cost tracker.
	 * @param PRAutoBlogger_Opik_Trace_Context|null       $trace_context Optional tracing context.
	 * @param PRAutoBlogger_Image_Composer_Interface|null $composer Optional composer override.
	 */
	public function __construct(
		?PRAutoBlogger_Image_Provider_Interface $provider = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null,
		?PRAutoBlogger_Opik_Trace_Context $trace_context = null,
		?PRAutoBlogger_Image_Composer_Interface $composer = null
	) {
		$this->provider       = $provider ?? self::create_default_provider();
		$this->prompt_builder = new PRAutoBlogger_Image_Prompt_Builder( $trace_context );
		$this->sideloader     = new PRAutoBlogger_Image_Media_Sideloader();
		$this->cost_tracker   = $cost_tracker ?? new PRAutoBlogger_Cost_Tracker();
		$this->composer       = $composer ?? new PRAutoBlogger_Image_Composer();
		$this->attacher       = new PRAutoBlogger_Image_Attacher( $this->sideloader, $this->cost_tracker );
		$this->trace_context  = $trace_context;
	}

	/**
	 * Instantiate the image provider based on the admin setting. Defaults
	 * to Runware on unrecognised values (CF was removed in v0.10.0).
	 *
	 * @return PRAutoBlogger_Image_Provider_Interface
	 */
	private static function create_default_provider(): PRAutoBlogger_Image_Provider_Interface {
		$provider_id = (string) get_option( 'prautoblogger_image_provider', PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER );
		if ( 'openrouter' === $provider_id ) {
			return new PRAutoBlogger_OpenRouter_Image_Provider();
		}
		return new PRAutoBlogger_Runware_Image_Provider();
	}

	/**
	 * Generate and attach images to a published post.
	 *
	 * Builds all prompts upfront, fires a single batch call (parallel via
	 * curl_multi on OpenRouter), then processes results. Wall-clock time
	 * equals the slowest image, not the sum — solving the Image B timeout.
	 *
	 * @param int        $post_id      Post ID to attach images to.
	 * @param array      $article_data Article title + content.
	 * @param array|null $source_data  Optional source data for Image B.
	 *
	 * @return array{image_a_id?: int, image_b_id?: int, cost_usd: float, errors: string[]}
	 */
	public function generate_and_attach_images(
		int $post_id,
		array $article_data,
		?array $source_data = null
	): array {
		$result = array(
			'cost_usd' => 0.0,
			'errors'   => array(),
		);

		if ( ! get_option( 'prautoblogger_image_enabled' ) ) {
			PRAutoBlogger_Logger::instance()->info( 'Image generation disabled in settings.', 'image_pipeline' );
			return $result;
		}

		// Check whether Image B is enabled in settings.
		$image_b_enabled = get_option( 'prautoblogger_image_b_enabled', '1' );
		$has_source_data = null !== $source_data && ! empty( $source_data );
		$generate_b      = $has_source_data && '1' === $image_b_enabled;

		// Budget pre-check: estimate cost for all images before any HTTP calls.
		$image_count    = $generate_b ? 2 : 1;
		$estimated_cost = $this->provider->estimate_cost( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT ) * $image_count;
		if ( $this->cost_tracker->would_exceed_budget( $estimated_cost ) ) {
			$result['errors'][] = 'Image generation would exceed monthly budget.';
			return $result;
		}

		// Build all prompts upfront (LLM calls happen here, sequentially).
		$article_prompt = $this->prompt_builder->build_article_prompt( $article_data );
		$batch_requests = array(
			'image_a' => array(
				'prompt' => $article_prompt['prompt'],
				'width'  => self::DEFAULT_WIDTH,
				'height' => self::DEFAULT_HEIGHT,
			),
		);
		$captions       = array( 'image_a' => $article_prompt['caption'] );

		$source_prompt = null;
		if ( $generate_b ) {
			$source_prompt             = $this->prompt_builder->build_source_prompt( $source_data );
			$batch_requests['image_b'] = array(
				'prompt' => $source_prompt['prompt'],
				'width'  => self::DEFAULT_WIDTH,
				'height' => self::DEFAULT_HEIGHT,
			);
			$captions['image_b']       = $source_prompt['caption'];
		}

		// Fire all image generation requests in parallel.
		try {
			$batch_results = $this->provider->generate_image_batch( $batch_requests );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Batch generation failed: ' . $e->getMessage(), 'image_pipeline' );
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		// Single-shot retry for any slot the provider flagged as NSFW-blocked.
		if ( PRAutoBlogger_Image_NSFW_Retry::is_enabled() ) {
			( new PRAutoBlogger_Image_NSFW_Retry( $this->provider, $this->prompt_builder ) )
				->retry_blocked_slots( $post_id, $batch_requests, $batch_results, $captions, $article_data, $source_data );
		}

		$title = (string) ( $article_data['post_title'] ?? $article_data['suggested_title'] ?? '' );

		// Process Image A result.
		$this->process_slot( 'image_a', $post_id, $batch_results, $captions, $title, $result );

		// Process Image B result (if requested).
		if ( isset( $batch_results['image_b'] ) ) {
			$this->process_slot( 'image_b', $post_id, $batch_results, $captions, $title, $result );
		} elseif ( null === $source_data || empty( $source_data ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Image B skipped for post %d: no source data.', $post_id ),
				'image_pipeline'
			);
		}

		return $result;
	}

	/**
	 * Process one slot's batch result: compose, sideload, link to the post.
	 *
	 * Image A becomes the featured image (role meta, caption prepend, and
	 * composed OG/square variants meta-linked); Image B is stored in post
	 * meta with a corner mark only. Alt text is the editorial caption
	 * (v0.17.0 fix — the model name used to leak here).
	 *
	 * @param string $slot          'image_a' or 'image_b'.
	 * @param int    $post_id       Post ID.
	 * @param array  $batch_results Keyed results from generate_image_batch().
	 * @param array  $captions      Keyed captions from prompt builder.
	 * @param string $title         Post title (composer context).
	 * @param array  $result        Pipeline result array (modified by reference).
	 */
	private function process_slot( string $slot, int $post_id, array $batch_results, array $captions, string $title, array &$result ): void {
		$label      = 'image_a' === $slot ? 'Image A' : 'Image B';
		$image_data = $batch_results[ $slot ] ?? null;

		if ( ! $image_data || isset( $image_data['error'] ) ) {
			$err_msg            = $image_data['error'] ?? sprintf( '%s missing from batch results.', $label );
			$result['errors'][] = $err_msg;
			// Log at WARNING so the path is visible even if the outer catch in the
			// caller doesn't fire. See thread image-mime-bug Issue 2.
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( '%s generation failed for post %d: %s', $label, $post_id, $err_msg ),
				'image_pipeline'
			);
			return;
		}

		$caption  = (string) ( $captions[ $slot ] ?? '' );
		$variants = $this->compose_variants( $image_data, $post_id, $caption, $title, $slot );
		$featured = array_merge( $image_data, array_shift( $variants ) );

		$attachment_id = $this->attacher->sideload_and_log( $featured, $post_id, $slot, $label, $caption );
		if ( is_wp_error( $attachment_id ) ) {
			$result['errors'][] = $attachment_id->get_error_message();
			return;
		}

		$result[ $slot . '_id' ] = $attachment_id;
		$result['cost_usd']     += $image_data['cost_usd'];

		if ( '' !== $caption ) {
			update_post_meta( $attachment_id, '_prautoblogger_image_caption', $caption );
		}

		if ( 'image_a' === $slot ) {
			set_post_thumbnail( $post_id, $attachment_id );
			update_post_meta( $attachment_id, '_prautoblogger_image_role', 'featured' );
			if ( '' !== $caption ) {
				$this->attacher->prepend_caption_to_post( $post_id, $attachment_id, $caption );
			}
			$this->attacher->attach_variants( $variants, $post_id, $attachment_id, $caption, (string) ( $image_data['model'] ?? 'unknown' ) );
		} else {
			update_post_meta( $post_id, '_prautoblogger_image_b_id', $attachment_id );
		}
	}

	/**
	 * Run the composer with a hard safety net: any failure (composer bugs
	 * included — its own internals already degrade) falls back to passing
	 * the base image through, so composition can never fail a publish.
	 *
	 * @param array  $image_data Provider result for one slot.
	 * @param int    $post_id    Post ID (composer context).
	 * @param string $caption    Editorial caption (composer context).
	 * @param string $title      Post title (composer context).
	 * @param string $slot       'image_a' or 'image_b'.
	 * @return array<int, array<string, mixed>> Featured-first variant list (never empty).
	 */
	private function compose_variants( array $image_data, int $post_id, string $caption, string $title, string $slot ): array {
		try {
			$variants = $this->composer->compose(
				$image_data,
				array(
					'post_id' => $post_id,
					'caption' => $caption,
					'title'   => $title,
					'slot'    => $slot,
				)
			);
			if ( ! empty( $variants ) ) {
				return $variants;
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Image composition failed, publishing original image: ' . $e->getMessage(),
				'image_composer'
			);
		}

		return array( array_merge( $image_data, array( 'role' => 'featured' ) ) );
	}
}
