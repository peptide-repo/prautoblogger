<?php
/**
 * Tests for PRAutoBlogger_Prompt_Registry (read side).
 *
 * Locks the two load-bearing contracts of the Phase-1 prompt registry:
 * (1) the in-code fallback renders BYTE-IDENTICAL output to the historical
 * hardcoded prompt builders when the table is unavailable, and (2) token
 * substitution uses the exact `{{ name }}` syntax.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class PromptRegistryTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		// No $wpdb in scope: the registry must read as "unavailable" and
		// fall back to the in-code defaults without touching the database.
		unset( $GLOBALS['wpdb'] );
		\PRAutoBlogger_Prompt_Registry::flush_cache();
	}

	protected function tearDown(): void {
		\PRAutoBlogger_Prompt_Registry::flush_cache();
		parent::tearDown();
	}

	/**
	 * Token filling: exact `{{ name }}` placeholders, multiple occurrences,
	 * unknown tokens left intact.
	 */
	public function test_fill_substitutes_tokens(): void {
		$body = 'A {{ one }} B {{ two }} C {{ one }} D {{ missing }}';
		$out  = \PRAutoBlogger_Prompt_Registry::fill(
			$body,
			array(
				'one' => 'X',
				'two' => 'Y',
			)
		);
		$this->assertSame( 'A X B Y C X D {{ missing }}', $out );
	}

	/**
	 * With no table available, render() must produce the same single-pass
	 * prompt the v0.16.0 sprintf produced, byte for byte.
	 */
	public function test_single_pass_fallback_matches_historical_output(): void {
		$rendered = \PRAutoBlogger_Prompt_Registry::render(
			'content.single_pass',
			array(
				'title'        => 'My Title',
				'topic'        => 'My Topic',
				'article_type' => 'guide',
				'key_points'   => implode( "\n- ", array( 'p1', 'p2' ) ),
				'keywords'     => implode( ', ', array( 'k1', 'k2' ) ),
				'min_words'    => '800',
				'max_words'    => '2000',
			)
		);

		$historical = sprintf(
			"Write a complete blog post in HTML format.\n\n" .
			"Title: %s\nTopic: %s\nType: %s\n\nKey points:\n- %s\n\n" .
			"Keywords: %s\n\n" .
			"Requirements:\n" .
			"- %d-%d words\n" .
			"- Proper HTML (h2, h3, p, ul/li)\n" .
			"- Engaging intro, strong conclusion with CTA\n" .
			"- Do NOT include the title or <html>/<body> tags\n" .
			"- Output HTML only, no markdown or commentary\n" .
			'- Follow EVERY formatting and structural requirement from your system prompt style guide',
			'My Title',
			'My Topic',
			'guide',
			implode( "\n- ", array( 'p1', 'p2' ) ),
			implode( ', ', array( 'k1', 'k2' ) ),
			800,
			2000
		);

		$this->assertSame( $historical, $rendered );
	}

	/**
	 * Outline fallback matches the historical sprintf output byte for byte.
	 */
	public function test_outline_fallback_matches_historical_output(): void {
		$rendered = \PRAutoBlogger_Prompt_Registry::render(
			'content.outline',
			array(
				'title'        => 'T',
				'topic'        => 'Top',
				'article_type' => 'guide',
				'key_points'   => implode( "\n- ", array( 'a', 'b' ) ),
				'keywords'     => 'k1, k2',
				'min_words'    => '800',
				'max_words'    => '2000',
			)
		);

		$historical = sprintf(
			"Create a detailed outline for a blog post titled: \"%s\"\n\n" .
			"Topic: %s\nArticle type: %s\n\nKey points to cover:\n%s\n\n" .
			"Target keywords: %s\n\n" .
			'The outline should have 4-6 main sections with bullet points under each. ' .
			'Include an introduction hook and a conclusion with a call to action. ' .
			"Word count target: %d-%d words.\n\n" .
			'Plan the structure to satisfy EVERY requirement in your system prompt style guide.',
			'T',
			'Top',
			'guide',
			implode( "\n- ", array( 'a', 'b' ) ),
			'k1, k2',
			800,
			2000
		);

		$this->assertSame( $historical, $rendered );
	}

	/**
	 * Polish fallback matches the historical concatenation byte for byte.
	 */
	public function test_polish_fallback_matches_historical_output(): void {
		$rendered = \PRAutoBlogger_Prompt_Registry::render(
			'content.polish',
			array( 'draft' => '<p>Hello</p>' )
		);

		$historical = "Review and polish this blog post draft. Improve:\n" .
			"1. Flow and readability\n" .
			"2. SEO optimization (headings, keyword placement)\n" .
			"3. Engagement (hooks, transitions, call-to-action)\n" .
			"4. Accuracy and clarity\n" .
			"5. Remove any filler or redundant sentences\n\n" .
			'IMPORTANT: Preserve all bullet points, numbered lists, hyperlinks, and ' .
			'structural elements from the draft. Do NOT flatten lists into prose or ' .
			'remove links. Ensure every requirement from your system prompt style ' .
			"guide is satisfied in the final output.\n\n" .
			"Return the polished HTML content only. Do not add commentary.\n\n" .
			"DRAFT:\n" . '<p>Hello</p>';

		$this->assertSame( $historical, $rendered );
	}

	/**
	 * Editor system fallback matches the historical builder for both the
	 * empty and the populated instructions paths.
	 */
	public function test_editor_system_fallback_matches_historical_output(): void {
		$expected_plain = 'You are a senior blog editor specializing in peptides content'
			. ". Review article drafts before publication.\n\n"
			. "Evaluate on: QUALITY, ACCURACY, SEO, COMPLETENESS, READABILITY.\n"
			. "Respond with JSON: {\n"
			. '  "verdict": "approved" | "revised" | "rejected",' . "\n"
			. '  "quality_score": 0.0-1.0,' . "\n"
			. '  "seo_score": 0.0-1.0,' . "\n"
			. '  "issues": ["issue1", "issue2"],' . "\n"
			. '  "notes": "Editorial notes",' . "\n"
			. '  "revised_content": "Full revised HTML if revised, null otherwise"' . "\n"
			. "}\n\n"
			. "Rules:\n"
			. "- APPROVE if quality_score >= 0.7 and seo_score >= 0.6\n"
			. "- REVISE if fixable issues exist — provide full revised HTML\n"
			. "- REJECT if fundamentally flawed\n"
			. "- Preserve formatting, links, lists when revising\n";

		$rendered = \PRAutoBlogger_Prompt_Registry::render(
			'editor.system',
			array(
				'niche_clause'       => ' specializing in peptides content',
				'instructions_block' => '',
			)
		);
		$this->assertSame( $expected_plain, $rendered );

		$rendered_with = \PRAutoBlogger_Prompt_Registry::render(
			'editor.system',
			array(
				'niche_clause'       => '',
				'instructions_block' => "\nAdditional instructions:\nBe terse.\n",
			)
		);
		$this->assertStringEndsWith(
			"- Preserve formatting, links, lists when revising\n\nAdditional instructions:\nBe terse.\n",
			$rendered_with
		);
	}

	/**
	 * Every registry key advertised in the plan has a default body, and the
	 * stage map's prompt keys all resolve to a known key.
	 */
	public function test_defs_cover_all_planned_keys(): void {
		$defs = \PRAutoBlogger_Prompt_Registry::defs();
		$keys = array(
			'content.system',
			'content.single_pass',
			'content.outline',
			'content.draft',
			'content.polish',
			'analysis.system',
			'analysis.user',
			'editor.system',
			'editor.review',
			'research.system',
			'image.rewriter_system',
			'image.style_template',
		);
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $defs, "Missing registry def for '{$key}'" );
			$this->assertNotSame( '', $defs[ $key ]['body'] );
		}

		foreach ( \PRAutoBlogger_Stage_Display_Map::all() as $stage => $def ) {
			if ( null !== $def['prompt_key'] ) {
				$this->assertArrayHasKey(
					$def['prompt_key'],
					$defs,
					"Stage '{$stage}' maps to unknown prompt key '{$def['prompt_key']}'"
				);
			}
		}
	}

	/**
	 * pins_for_run is empty (not an error) when no run / table is present.
	 */
	public function test_pins_empty_without_table(): void {
		$this->assertSame( array(), \PRAutoBlogger_Prompt_Registry::pins_for_run( null ) );
		$this->assertSame( array(), \PRAutoBlogger_Prompt_Registry::pins_for_run( 'run-x' ) );
	}
}
