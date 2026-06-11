<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Orchestrates deterministic image composition with a capability ladder.
 *
 * Sits between the provider's raw bytes and the media sideloader. Probes the
 * environment once (cached in an option keyed by a PHP/extension fingerprint,
 * so it auto-invalidates when the host changes — Hostinger extension state
 * has flipped before), then renders per the ladder: (1) Imagick usable →
 * full branded compositing; (2) GD via WP editor → resize-only variants, no
 * branding/text, WARNING once; (3) no image library → pass-through, WARNING
 * once. compose() never throws and always returns a featured-first non-empty
 * list, so a composition failure can never block a publish. Local CPU only:
 * $0, no provider call; duration is logged on the image_composer channel.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline after batch results + NSFW retry,
 *               before sideloading.
 * Dependencies: Image_Composer_Imagick (rung 1), Image_Composer_Editor (rung 2),
 *               Image_Composer_Canvas (probe), Image_Composer_Layout, Logger.
 *
 * @see core/interface-image-composer.php — The contract.
 * @see core/class-image-pipeline.php     — Caller.
 * @see core/class-image-attacher.php     — Persists returned variants.
 * @see ARCHITECTURE.md                   — Decision #21.
 */
class PRAutoBlogger_Image_Composer implements PRAutoBlogger_Image_Composer_Interface {

	/** Option keys (prautoblogger_ prefix keeps the uninstall sweep effective). */
	public const OPTION_ENABLED      = 'prautoblogger_image_compose_enabled';
	public const OPTION_VARIANTS     = 'prautoblogger_image_compose_variants';
	public const OPTION_MARK_ENABLED = 'prautoblogger_image_featured_mark_enabled';
	public const OPTION_CAPABILITY   = 'prautoblogger_image_compose_capability';

	/** Capability rungs. */
	public const CAP_IMAGICK = 'imagick';
	public const CAP_GD      = 'gd';
	public const CAP_NONE    = 'none';

	/** @var PRAutoBlogger_Image_Composer_Imagick|null Lazily built; injectable for tests. */
	private ?PRAutoBlogger_Image_Composer_Imagick $renderer;

	/** @var bool Degradation WARNING fired this run (once per PHP process). */
	private static bool $warned_this_run = false;

	/**
	 * Construct, optionally injecting a renderer (tests).
	 * @param PRAutoBlogger_Image_Composer_Imagick|null $renderer Optional renderer override.
	 */
	public function __construct( ?PRAutoBlogger_Image_Composer_Imagick $renderer = null ) {
		$this->renderer = $renderer;
	}

	/**
	 * Compose final variants for one generated image. See the interface for
	 * the full contract; this implementation never throws.
	 * Side effects: reads options, may cache the capability probe, logs.
	 *
	 * @param array $image_data Provider result (bytes, mime_type, width, height, ...).
	 * @param array $context    {post_id, caption, title, slot}.
	 * @return array<int, array{bytes: string, mime_type: string, width: int, height: int, role: string}>
	 */
	public function compose( array $image_data, array $context ): array {
		$passthrough = array( $this->passthrough_variant( $image_data ) );

		if ( '' === $passthrough[0]['bytes'] || '1' !== (string) get_option( self::OPTION_ENABLED, '1' ) ) {
			return $passthrough;
		}

		$started    = microtime( true );
		$bytes      = $passthrough[0]['bytes'];
		$capability = $this->resolve_capability();
		$caption    = (string) ( $context['caption'] ?? '' );
		$roles      = ( 'image_a' === (string) ( $context['slot'] ?? 'image_a' ) )
			? $this->configured_variants()
			: array();

		if ( self::CAP_IMAGICK === $capability ) {
			$variants = $this->compose_via_imagick( $bytes, $caption, $roles, $passthrough[0] );
		} elseif ( self::CAP_GD === $capability ) {
			$this->warn_once( 'Imagick unavailable — emitting resize-only variants without branding or caption.' );
			$editor   = new PRAutoBlogger_Image_Composer_Editor();
			$variants = array_merge( $passthrough, $editor->compose_variants( $bytes, $roles, $this->layout() ) );
		} else {
			$this->warn_once( 'No usable image library — base image passed through unbranded.' );
			$variants = $passthrough;
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Composed %d variant(s) for post %d in %dms ($0, local, capability: %s).',
				count( $variants ),
				(int) ( $context['post_id'] ?? 0 ),
				(int) round( ( microtime( true ) - $started ) * 1000 ),
				$capability
			),
			'image_composer'
		);

		return $variants;
	}

	/**
	 * Full compositing rung. Each render is individually guarded: a featured
	 * failure degrades to pass-through, a variant failure skips that variant.
	 *
	 * @param string $bytes       Base image bytes.
	 * @param string $caption     Editorial caption for OG/square.
	 * @param array  $roles       Variant roles to render ('og', 'square').
	 * @param array  $passthrough Pass-through featured variant (fallback).
	 * @return array<int, array<string, mixed>> Featured-first variant list.
	 */
	private function compose_via_imagick( string $bytes, string $caption, array $roles, array $passthrough ): array {
		$variants = array();

		if ( '1' === (string) get_option( self::OPTION_MARK_ENABLED, '1' ) ) {
			$featured   = $this->try_render( 'featured', $bytes, $caption );
			$variants[] = null === $featured ? $passthrough : $featured;
		} else {
			$variants[] = $passthrough;
		}

		foreach ( $roles as $role ) {
			$variant = $this->try_render( $role, $bytes, $caption );
			if ( null !== $variant ) {
				$variants[] = $variant;
			}
		}

		return $variants;
	}

	/**
	 * Render one role via the Imagick renderer. Any throwable or malformed
	 * result (empty bytes) maps to one per-run WARNING + null (caller degrades).
	 * @param string $role    'featured', 'og', or 'square'.
	 * @param string $bytes   Base image bytes.
	 * @param string $caption Editorial caption.
	 * @return array<string, mixed>|null Variant payload or null on failure.
	 */
	private function try_render( string $role, string $bytes, string $caption ): ?array {
		try {
			$renderer = $this->renderer();
			if ( 'og' === $role ) {
				$variant = $renderer->compose_og( $bytes, $caption );
			} elseif ( 'square' === $role ) {
				$variant = $renderer->compose_square( $bytes, $caption );
			} else {
				$variant = $renderer->compose_featured( $bytes );
			}

			if ( '' === (string) ( $variant['bytes'] ?? '' ) ) {
				$this->warn_once( sprintf( 'Imagick render for %s variant returned no bytes — degrading.', $role ) );
				return null;
			}
			$variant['role'] = $role;

			return $variant;
		} catch ( \Throwable $e ) {
			$this->warn_once( sprintf( 'Imagick render failed for %s variant: %s', $role, $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Resolve the capability rung: cached probe + override filter. The
	 * same-named filter lets tests/ops force a rung (e.g. 'none').
	 *
	 * @return string One of the CAP_* constants.
	 */
	private function resolve_capability(): string {
		$fingerprint = md5(
			PHP_VERSION . '|' . ( extension_loaded( 'imagick' ) ? 'im1' : 'im0' ) . '|' . ( extension_loaded( 'gd' ) ? 'gd1' : 'gd0' )
		);

		$cached = get_option( self::OPTION_CAPABILITY, array() );
		if ( is_array( $cached ) && ( $cached['fingerprint'] ?? '' ) === $fingerprint && isset( $cached['capability'] ) ) {
			$capability = (string) $cached['capability'];
		} else {
			$capability = $this->probe_capability();
			update_option(
				self::OPTION_CAPABILITY,
				array(
					'fingerprint' => $fingerprint,
					'capability'  => $capability,
				),
				false
			);
		}

		$capability = (string) apply_filters( 'prautoblogger_image_compose_capability', $capability );

		return in_array( $capability, array( self::CAP_IMAGICK, self::CAP_GD, self::CAP_NONE ), true )
			? $capability
			: self::CAP_NONE;
	}

	/**
	 * Probe the host for the best available rung (uncached).
	 * @return string One of the CAP_* constants.
	 */
	private function probe_capability(): string {
		if ( PRAutoBlogger_Image_Composer_Canvas::is_imagick_usable( $this->fonts_dir() . 'Poppins-Bold.ttf' ) ) {
			return self::CAP_IMAGICK;
		}

		$png_crop = array(
			'mime_type' => 'image/png',
			'methods'   => array( 'resize', 'crop' ),
		);
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( $png_crop ) ) {
			return self::CAP_GD;
		}

		return self::CAP_NONE;
	}

	/**
	 * Lazily build the Imagick renderer with filtered layout + asset paths.
	 * @return PRAutoBlogger_Image_Composer_Imagick
	 */
	private function renderer(): PRAutoBlogger_Image_Composer_Imagick {
		if ( null === $this->renderer ) {
			$this->renderer = new PRAutoBlogger_Image_Composer_Imagick(
				$this->layout(),
				dirname( __DIR__, 2 ) . '/assets/brand/',
				$this->fonts_dir()
			);
		}
		return $this->renderer;
	}

	/**
	 * Layout geometry: pure defaults behind the documented override filter.
	 * @return array<string, array<string, mixed>>
	 */
	private function layout(): array {
		return (array) apply_filters( 'prautoblogger_image_compose_layout', PRAutoBlogger_Image_Composer_Layout::defaults() );
	}

	/**
	 * Bundled font directory (path-derived, no constants — unit-test safe).
	 * @return string Absolute path with trailing slash.
	 */
	private function fonts_dir(): string {
		return dirname( __DIR__, 2 ) . '/assets/fonts/';
	}

	/**
	 * Variant roles from settings, whitelisted at point of use.
	 * @return string[] Subset of ['og', 'square'], deduplicated, in config order.
	 */
	private function configured_variants(): array {
		$raw   = (string) get_option( self::OPTION_VARIANTS, 'og,square' );
		$roles = array();
		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = strtolower( trim( $piece ) );
			if ( in_array( $piece, array( 'og', 'square' ), true ) && ! in_array( $piece, $roles, true ) ) {
				$roles[] = $piece;
			}
		}
		return $roles;
	}

	/**
	 * Build the pass-through featured variant from the provider result.
	 * @param array $image_data Provider result.
	 * @return array{bytes: string, mime_type: string, width: int, height: int, role: string}
	 */
	private function passthrough_variant( array $image_data ): array {
		return array(
			'bytes'     => (string) ( $image_data['bytes'] ?? '' ),
			'mime_type' => (string) ( $image_data['mime_type'] ?? 'image/png' ),
			'width'     => (int) ( $image_data['width'] ?? 0 ),
			'height'    => (int) ( $image_data['height'] ?? 0 ),
			'role'      => 'featured',
		);
	}

	/**
	 * Log a degradation WARNING once per run (one row is enough for ops).
	 * @param string $message Warning text.
	 */
	private function warn_once( string $message ): void {
		if ( self::$warned_this_run ) {
			return;
		}
		self::$warned_this_run = true;
		PRAutoBlogger_Logger::instance()->warning( $message, 'image_composer' );
	}

	/** Reset per-run state (unit tests only). */
	public static function reset_run_state(): void {
		self::$warned_this_run = false;
	}
}
