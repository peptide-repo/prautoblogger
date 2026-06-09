<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Fills the admin-editable editorial image-prompt template with the
 * rewriter-produced topic summary, replacing the old "scene + style suffix"
 * concatenation.
 *
 * The template (option `prautoblogger_image_style_template`, default
 * PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE) contains exactly one
 * `{{ topic_summary }}` token. This class:
 *   - resolves the active template (admin override wins; blank → default),
 *   - sanitises and length-clamps the topic summary (brief A5),
 *   - substitutes the token, and
 *   - degrades safely (brief A5/A6) so a blank or token-only prompt is never
 *     emitted to the image provider.
 *
 * Who calls it: PRAutoBlogger_Image_Prompt_Builder (static call from each of
 *               build_article_prompt / build_source_prompt / build_fallback_prompt).
 * Dependencies: PRAutoBlogger_Logger (diagnostics only).
 *
 * @see core/class-image-prompt-builder.php — Sole caller.
 * @see ARCHITECTURE.md                      — Image generation data flow.
 */
class PRAutoBlogger_Image_Template_Filler {

	/** The single substitution token the template must contain. */
	public const TOKEN = '{{ topic_summary }}';

	/**
	 * Max characters retained from the rewriter topic summary before it is
	 * substituted into the template. Keeps the final provider prompt bounded
	 * even if the rewriter ignores its length guidance.
	 */
	public const MAX_SUMMARY_CHARS = 320;

	/**
	 * Build the final image-generation prompt from a rewriter scene.
	 *
	 * @param string $scene Raw topic/mechanism summary from the rewriter (the
	 *                      "scene" half of the scene/caption contract).
	 * @return string Provider-ready prompt; never empty.
	 */
	public static function fill( string $scene ): string {
		$template = self::resolve_template();
		$summary  = self::sanitize_summary( $scene );

		// Brief A6: never ship a blank or token-only prompt. If the rewriter
		// produced nothing usable, return the template with the token removed
		// only as a last resort — but the caller guards this by passing a
		// rule-based fallback scene, so an empty summary here is unexpected.
		if ( '' === $summary ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Editorial prompt fill received an empty topic summary; emitting style-only prompt.',
				'image_template_filler'
			);
			return self::strip_token( $template );
		}

		$token_count = substr_count( $template, self::TOKEN );

		if ( 1 === $token_count ) {
			return trim( str_replace( self::TOKEN, $summary, $template ) );
		}

		// Brief A5: admin removed or duplicated the token. Fall back to
		// appending the summary to the style text and log a warning rather
		// than shipping a malformed prompt.
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'Image style template has %d "%s" tokens (expected 1); appending summary instead of substituting.',
				$token_count,
				self::TOKEN
			),
			'image_template_filler'
		);

		$base = self::strip_token( $template );
		return trim( $base . ' ' . $summary );
	}

	/**
	 * Sanitise and validate the admin-submitted style template on save.
	 *
	 * Multi-line, so sanitised with sanitize_textarea_field() (not
	 * sanitize_text_field(), which would strip newlines). Per brief A5 the
	 * template must contain exactly one TOKEN; otherwise the previous value is
	 * kept and a settings error is surfaced. A blank submission falls back to
	 * the editorial default so image generation can never be bricked.
	 *
	 * @param string $value Submitted template.
	 * @return string Sanitised, validated template.
	 */
	public static function sanitize_for_save( string $value ): string {
		$clean = sanitize_textarea_field( $value );

		if ( '' === trim( $clean ) ) {
			return PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE;
		}

		$token_count = substr_count( $clean, self::TOKEN );
		if ( 1 !== $token_count ) {
			add_settings_error(
				'prautoblogger_image_style_template',
				'prautoblogger_image_style_template_token',
				sprintf(
					/* translators: 1: required token, 2: number of tokens found. */
					esc_html__( 'The Style Template must contain exactly one %1$s token (found %2$d). Keeping the previous value.', 'prautoblogger' ),
					esc_html( self::TOKEN ),
					(int) $token_count
				)
			);
			return (string) get_option( 'prautoblogger_image_style_template', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE );
		}

		return $clean;
	}

	/**
	 * Resolve the active template: admin override wins; blank → default.
	 *
	 * @return string Non-empty template string.
	 */
	private static function resolve_template(): string {
		$override = (string) get_option( 'prautoblogger_image_style_template', '' );
		if ( '' !== trim( $override ) ) {
			return $override;
		}
		return PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE;
	}

	/**
	 * Strip control characters and clamp length of the rewriter summary
	 * (brief A5). Whitespace runs (including newlines) are collapsed to single
	 * spaces so the summary sits cleanly inside the template.
	 *
	 * @param string $scene Raw rewriter scene.
	 * @return string Sanitised, length-clamped summary (possibly empty).
	 */
	private static function sanitize_summary( string $scene ): string {
		// Remove control characters (C0/C1) except via whitespace collapse below.
		$clean = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $scene );
		if ( null === $clean ) {
			// preg_replace can return null on bad UTF-8; degrade conservatively.
			$clean = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $scene );
			$clean = is_string( $clean ) ? $clean : '';
		}

		// Collapse whitespace runs to single spaces.
		$collapsed = preg_replace( '/\s+/u', ' ', $clean );
		$clean     = is_string( $collapsed ) ? $collapsed : $clean;
		$clean     = trim( $clean );

		if ( '' === $clean ) {
			return '';
		}

		// Clamp length (multibyte-aware where available).
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $clean ) > self::MAX_SUMMARY_CHARS ) {
				$clean = rtrim( mb_substr( $clean, 0, self::MAX_SUMMARY_CHARS ) ) . '...';
			}
		} elseif ( strlen( $clean ) > self::MAX_SUMMARY_CHARS ) {
			$clean = rtrim( substr( $clean, 0, self::MAX_SUMMARY_CHARS ) ) . '...';
		}

		return $clean;
	}

	/**
	 * Remove every occurrence of the token from a template, tidying the
	 * leftover whitespace so the residual style text is still well-formed.
	 *
	 * @param string $template Template possibly containing the token.
	 * @return string Token-free, trimmed template.
	 */
	private static function strip_token( string $template ): string {
		$stripped = str_replace( self::TOKEN, '', $template );
		$stripped = preg_replace( '/[ \t]+/', ' ', $stripped );
		$stripped = is_string( $stripped ) ? $stripped : $template;
		return trim( $stripped );
	}
}
