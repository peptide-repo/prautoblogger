<?php
declare(strict_types=1);

/**
 * Parses LLM output into scene/caption pairs and synthesises fallback prompts
 * from article titles when the LLM call fails.
 *
 * Who calls it: PRAutoBlogger_Image_Prompt_Builder (static calls).
 * Dependencies: None.
 */
class PRAutoBlogger_Image_Scene_Parser {

	/**
	 * Parse the LLM response into separate scene and caption parts.
	 *
	 * Expected format from the LLM:
	 *   Scene description text here.
	 *
	 *   "Caption punchline here."
	 *
	 * Falls back gracefully: if no blank-line separator is found, the entire
	 * response becomes the scene with an empty caption.
	 *
	 * @param string $raw Raw LLM response text.
	 * @return array{scene: string, caption: string}
	 */
	public static function parse_scene_and_caption( string $raw ): array {
		// Split on blank line (the format the system prompt requests).
		$parts = preg_split( '/\n\s*\n/', $raw, 2 );

		if ( count( $parts ) >= 2 ) {
			$scene   = trim( $parts[0] );
			$caption = trim( $parts[1] );
			// Strip surrounding quotes from caption — the LLM wraps it in quotes.
			$caption = trim( $caption, '"\'' );
			return array(
				'scene'   => $scene,
				'caption' => $caption,
			);
		}

		// No blank-line separator found — treat entire response as scene.
		return array(
			'scene'   => trim( $raw ),
			'caption' => '',
		);
	}

	/**
	 * Extract the first paragraph from HTML content.
	 *
	 * Removes all tags and captures the text up to the first double-newline
	 * or 200 characters, whichever comes first.
	 *
	 * @param string $html HTML content.
	 * @return string Plain text of the first paragraph.
	 */
	public static function extract_first_paragraph( string $html ): string {
		$text  = wp_strip_all_tags( $html );
		$paras = preg_split( '/\n\n+/', $text );
		$para  = isset( $paras[0] ) ? trim( $paras[0] ) : '';

		if ( strlen( $para ) > 200 ) {
			$para = substr( $para, 0, 200 ) . '...';
		}

		return $para;
	}

	/**
	 * Rule-based fallback when LLM rewriting is unavailable.
	 *
	 * Kept deliberately simple — this only runs if OpenRouter is down. Produces
	 * a text-free editorial-illustration subject (not a comic) so the prompt
	 * builder always has a non-empty topic summary to substitute into the
	 * style template, never shipping a blank prompt to the provider (brief A6).
	 *
	 * @param string $title           Main heading / topic.
	 * @param string $supporting_text Additional context.
	 * @return array{scene: string, caption: string} Scene for image gen, caption for HTML.
	 */
	public static function synthesize_visual_concepts_fallback( string $title, string $supporting_text ): array {
		// Fallback produces a simple editorial-illustration subject when the LLM is unavailable.
		$scene = sprintf(
			'A clean, centered editorial science illustration representing the topic of %s, with a single clear focal subject and generous negative space.',
			$title
		);

		return array(
			'scene'   => trim( $scene ),
			'caption' => '',
		);
	}
}
