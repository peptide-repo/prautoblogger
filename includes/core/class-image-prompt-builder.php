<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Builds image prompts from article content or source data.
 *
 * Uses a cheap LLM (via OpenRouter) to distill article content into a concise
 * 1-2 sentence editorial topic/mechanism summary, then substitutes that summary
 * into the admin-editable editorial style template (the {{ topic_summary }}
 * slot) to produce a text-free image-gen prompt. Falls back to rule-based
 * synthesis if the LLM call fails, so image generation never blocks on a
 * prompt-rewriting outage.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during content generation run.
 * Dependencies: PRAutoBlogger_OpenRouter_Provider (LLM call),
 *               PRAutoBlogger_Image_Template_Filler (template substitution),
 *               PRAutoBlogger_Cost_Tracker (logs prompt-rewrite cost),
 *               PRAutoBlogger_Logger (diagnostics).
 *
 * @see core/class-image-pipeline.php — Consumes build_article_prompt() and build_source_prompt().
 * @see providers/class-open-router-provider.php — LLM provider used for rewriting.
 * @see ARCHITECTURE.md              — Image generation data flow.
 */
class PRAutoBlogger_Image_Prompt_Builder {

	/**
	 * Default system prompt that teaches the LLM how to write image-gen
	 * prompts. Exposed as a `public const` so the admin-settings layer can
	 * use it as the default value for `prautoblogger_image_prompt_instructions`.
	 * The option, when non-empty, wins at call time (see rewrite_via_llm).
	 *
	 * Editorial pivot (v0.16.0): the model emits a concise topic/mechanism
	 * summary as the SCENE — concrete, single centered focal subject, no text,
	 * no people-as-gag, no logos — which the prompt builder substitutes into
	 * the editorial style template's {{ topic_summary }} slot. The CAPTION line
	 * is preserved unchanged for the existing HTML-caption-below-image path.
	 */
	public const REWRITER_SYSTEM_PROMPT = <<<'PROMPT'
You write subject descriptions for a text-free editorial science illustration about peptides, supplements, or biohacking.

Given an article title and summary, output TWO parts separated by a blank line:

SCENE: A concise 1-2 sentence description of the article's core topic or mechanism, written as the SUBJECT of a single editorial illustration. Name ONE concrete, centered focal subject and the few supporting visual elements around it (e.g. a labelled-free vial and dropper, a stylised receptor on a cell membrane, a molecular chain, a dosing syringe, an organ cross-section). Favor a clear central focal point with margin around it so the image crops well. This is image-gen direction, not prose.

CAPTION: A short, informative caption line (under 15 words) for display as HTML text below the image. Plain and editorial, not a joke.

Rules:
- The SCENE must depict the article's actual subject/mechanism, not a generic science scene.
- NO text, words, captions, speech bubbles, labels, or logos described in the SCENE — the illustration is text-free; text is rendered separately.
- NO people used as a visual gag and NO cartoon/comic framing. A human anatomical element (a cell, an organ, a hand holding a vial) is fine if it is the legitimate subject.
- Do NOT personify molecules, peptides, hormones, or proteins (no "smiling molecule"); depict them as clean stylised diagrams or structures.
- Keep the staging simple with one clear focal subject, readable at small sizes.
- Output ONLY the scene and caption. No preamble, no explanation.

Example output format:
A single glass peptide vial on a clean surface with a fine dropper above it, a faint stylised molecular chain arcing behind as a backdrop, centered with generous negative space.

A concentrated look at how the compound is prepared before use.
PROMPT;

	/**
	 * Max tokens for the rewriter response. Enough room for the 1-2 sentence
	 * topic/mechanism summary plus the short editorial caption line.
	 */
	private const REWRITER_MAX_TOKENS = 180;

	/**
	 * OpenRouter provider for LLM calls. Null until first use.
	 *
	 * @var PRAutoBlogger_OpenRouter_Provider|null
	 */
	private ?PRAutoBlogger_OpenRouter_Provider $llm = null;

	/**
	 * Opik trace context for instrumentation.
	 *
	 * @var PRAutoBlogger_Opik_Trace_Context|null
	 */
	private ?PRAutoBlogger_Opik_Trace_Context $trace_context = null;

	/**
	 * Constructor.
	 *
	 * @param PRAutoBlogger_Opik_Trace_Context|null $trace_context Optional trace context for instrumentation.
	 */
	public function __construct( ?PRAutoBlogger_Opik_Trace_Context $trace_context = null ) {
		$this->trace_context = $trace_context;
	}

	/**
	 * Build a visual prompt from finished article content.
	 *
	 * Tries LLM rewriting first; falls back to rule-based synthesis on
	 * failure. Splits scene (the topic summary, for image gen) from caption
	 * (HTML below the image) and substitutes the scene into the editorial
	 * style template's {{ topic_summary }} slot.
	 *
	 * @param array{post_title?: string, post_content?: string, suggested_title?: string} $article_data
	 * @return array{prompt: string, caption: string}
	 */
	public function build_article_prompt( array $article_data ): array {
		$title      = $article_data['post_title'] ?? $article_data['suggested_title'] ?? 'Product';
		$content    = $article_data['post_content'] ?? '';
		$first_para = PRAutoBlogger_Image_Scene_Parser::extract_first_paragraph( $content );

		$parsed = $this->rewrite_via_llm( $title, $first_para );

		return array(
			'prompt'  => PRAutoBlogger_Image_Template_Filler::fill( $parsed['scene'] ),
			'caption' => $parsed['caption'],
		);
	}

	/**
	 * Build a visual prompt from source Reddit thread data. Tries LLM
	 * rewriting first; falls back to rule-based synthesis on failure.
	 *
	 * @param array{title?: string, selftext?: string, comments?: string[]} $source_data
	 * @return array{prompt: string, caption: string}
	 */
	public function build_source_prompt( array $source_data ): array {
		$title    = $source_data['title'] ?? 'Reddit Discussion';
		$comments = $source_data['comments'] ?? array();
		$context  = is_array( $comments ) && ! empty( $comments ) ? $comments[0] : '';

		$parsed = $this->rewrite_via_llm( $title, $context );

		return array(
			'prompt'  => PRAutoBlogger_Image_Template_Filler::fill( $parsed['scene'] ),
			'caption' => $parsed['caption'],
		);
	}

	/**
	 * Use a cheap LLM to distill title + context into a visual scene + caption.
	 *
	 * The LLM response contains a SCENE line and a CAPTION line separated by a
	 * blank line. This method parses them apart so the scene drives image gen
	 * (no text baked in) and the caption is inserted as HTML below the image.
	 *
	 * Falls back to rule-based synthesis if the LLM call fails for any
	 * reason (network, auth, timeout, unexpected response shape).
	 *
	 * Side effects: one OpenRouter API call; one cost-tracker log entry.
	 *
	 * @param string $title   Article or thread title.
	 * @param string $context First paragraph or top comment.
	 * @return array{scene: string, caption: string} Scene for image gen, caption for HTML.
	 */
	private function rewrite_via_llm( string $title, string $context ): array {
		$title   = trim( sanitize_text_field( $title ) );
		$context = trim( sanitize_text_field( $context ) );

		// Truncate context to keep prompt tokens low.
		if ( strlen( $context ) > 300 ) {
			$context = substr( $context, 0, 300 ) . '...';
		}

		$user_message = "Article title: {$title}";
		if ( '' !== $context ) {
			$user_message .= "\n\nSummary: {$context}";
		}

		$span_id = null;

		try {
			if ( null !== $this->trace_context ) {
				$span_id = $this->trace_context->start_span(
					array(
						'name'     => 'image_prompt_rewrite',
						'type'     => 'llm',
						'model'    => get_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL ),
						'provider' => 'openrouter',
						'input'    => array(
							'title'   => $title,
							'context' => substr( $context, 0, 200 ),
						),
					)
				);
			}

			$llm    = $this->get_llm_provider();
			$model  = get_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
			$system = $this->resolve_system_prompt();

			$result = $llm->send_chat_completion(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user_message,
					),
				),
				$model,
				array(
					'temperature' => 0.7,
					'max_tokens'  => self::REWRITER_MAX_TOKENS,
					'stage'       => 'image_prompt_rewrite',
				)
			);

			$raw = trim( $result['content'] ?? '' );

			// Log the rewrite cost so it shows in the analytics dashboard.
			( new PRAutoBlogger_Cost_Tracker() )->log_api_call(
				null,
				'image_prompt_rewrite',
				'openrouter',
				$model,
				$result['prompt_tokens'] ?? 0,
				$result['completion_tokens'] ?? 0
			);

			$cost = $llm->estimate_cost(
				$model,
				$result['prompt_tokens'] ?? 0,
				$result['completion_tokens'] ?? 0
			);

			PRAutoBlogger_Logger::instance()->debug(
				sprintf( 'Image prompt rewritten (%d→%d chars, $%.6f): %s', strlen( $user_message ), strlen( $raw ), $cost, substr( $raw, 0, 120 ) ),
				'image_prompt_builder'
			);

			if ( null !== $span_id && null !== $this->trace_context ) {
				$this->trace_context->end_span(
					$span_id,
					array(
						'output' => array(
							'raw_response' => substr( $raw, 0, 200 ),
						),
						'usage'  => array(
							'prompt_tokens'     => $result['prompt_tokens'] ?? 0,
							'completion_tokens' => $result['completion_tokens'] ?? 0,
							'total_tokens'      => ( $result['prompt_tokens'] ?? 0 ) + ( $result['completion_tokens'] ?? 0 ),
						),
					)
				);
			}

			if ( '' !== $raw ) {
				return PRAutoBlogger_Image_Scene_Parser::parse_scene_and_caption( $raw );
			}
		} catch ( \Throwable $e ) {
			// LLM failure is not fatal — fall back to rule-based synthesis.
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Image prompt LLM rewrite %s, using fallback: %s', get_class( $e ), $e->getMessage() ),
				'image_prompt_builder'
			);
		}

		return PRAutoBlogger_Image_Scene_Parser::synthesize_visual_concepts_fallback( $title, $context );
	}

	/**
	 * Public entry into the rule-based fallback, used by NSFW retry to
	 * rebuild a provider-safe prompt from just the article title. Matches
	 * the return shape of build_article_prompt() / build_source_prompt().
	 *
	 * @param string $title Article or source title.
	 * @return array{prompt: string, caption: string}
	 */
	public function build_fallback_prompt( string $title ): array {
		$parsed = PRAutoBlogger_Image_Scene_Parser::synthesize_visual_concepts_fallback( $title, '' );
		return array(
			'prompt'  => PRAutoBlogger_Image_Template_Filler::fill( $parsed['scene'] ),
			'caption' => $parsed['caption'],
		);
	}

	/** Lazy-load the OpenRouter provider. */
	private function get_llm_provider(): PRAutoBlogger_OpenRouter_Provider {
		if ( null === $this->llm ) {
			$this->llm = new PRAutoBlogger_OpenRouter_Provider();
		}
		return $this->llm;
	}

	/** Admin option wins; blank falls back to REWRITER_SYSTEM_PROMPT. */
	private function resolve_system_prompt(): string {
		$override = (string) get_option( 'prautoblogger_image_prompt_instructions', '' );
		return '' !== trim( $override ) ? $override : self::REWRITER_SYSTEM_PROMPT;
	}
}
