<?php
/**
 * Tests for PRAutoBlogger_Image_Composer_Layout.
 *
 * Validates the caption clamp (word wrap, hard budgets, ellipsis, multibyte)
 * and the shape of the layout defaults consumed by the renderers.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;

class ImageComposerLayoutTest extends BaseTestCase {

	/**
	 * Empty and whitespace-only captions clamp to no lines at all.
	 */
	public function test_empty_caption_returns_no_lines(): void {
		$this->assertSame( [], \PRAutoBlogger_Image_Composer_Layout::clamp_caption( '', 52, 2 ) );
		$this->assertSame( [], \PRAutoBlogger_Image_Composer_Layout::clamp_caption( "  \n\t ", 52, 2 ) );
	}

	/**
	 * A single short word stays on one line, untouched.
	 */
	public function test_single_word_caption(): void {
		$this->assertSame(
			[ 'Collagen' ],
			\PRAutoBlogger_Image_Composer_Layout::clamp_caption( 'Collagen', 52, 2 )
		);
	}

	/**
	 * A caption exactly at the per-line budget fills the line with no
	 * ellipsis and no spill.
	 */
	public function test_caption_exactly_at_budget_is_not_truncated(): void {
		$caption = 'abcde fghij'; // 11 chars, budget 11.
		$lines   = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( $caption, 11, 1 );

		$this->assertSame( [ 'abcde fghij' ], $lines );
	}

	/**
	 * A caption exactly filling max_lines is kept in full (boundary: the
	 * ellipsis only appears when content is actually dropped).
	 */
	public function test_caption_exactly_filling_max_lines_keeps_all_words(): void {
		$lines = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( 'aa bb cc dd', 5, 2 );

		$this->assertSame( [ 'aa bb', 'cc dd' ], $lines );
	}

	/**
	 * 3× the budget gets cut at max_lines with a trailing ellipsis, and no
	 * line ever exceeds the per-line budget.
	 */
	public function test_overflowing_caption_is_ellipsized_within_budget(): void {
		$caption = 'Collagen fragments may aid joint repair according to early trials in athletes';
		$lines   = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( $caption, 20, 2 );

		$this->assertCount( 2, $lines );
		$this->assertStringEndsWith( \PRAutoBlogger_Image_Composer_Layout::ELLIPSIS, $lines[1] );
		foreach ( $lines as $line ) {
			$this->assertLessThanOrEqual( 20, mb_strlen( $line ) );
		}
	}

	/**
	 * Words wrap at word boundaries — a word never straddles two lines when
	 * it fits on the next one.
	 */
	public function test_wraps_at_word_boundaries(): void {
		$lines = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( 'peptide bonds form chains', 13, 3 );

		$this->assertSame( [ 'peptide bonds', 'form chains' ], $lines );
	}

	/**
	 * A single word longer than a full line is hard-split instead of
	 * overflowing the canvas.
	 */
	public function test_overlong_single_word_is_hard_split(): void {
		$lines = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( 'abcdefghij', 4, 3 );

		$this->assertSame( [ 'abcd', 'efgh', 'ij' ], $lines );
	}

	/**
	 * Multibyte captions are measured in characters, not bytes, and the
	 * ellipsis path keeps each line within the budget.
	 */
	public function test_multibyte_caption_counts_characters_not_bytes(): void {
		$lines = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( 'ペプチドは結合組織を強化する可能性がある', 6, 2 );

		$this->assertCount( 2, $lines );
		foreach ( $lines as $line ) {
			$this->assertLessThanOrEqual( 6, mb_strlen( $line ) );
		}
		$this->assertStringEndsWith( \PRAutoBlogger_Image_Composer_Layout::ELLIPSIS, $lines[1] );
	}

	/**
	 * Interior whitespace runs (newlines, tabs, doubles) collapse to single
	 * spaces before wrapping.
	 */
	public function test_whitespace_is_collapsed_before_wrapping(): void {
		$lines = \PRAutoBlogger_Image_Composer_Layout::clamp_caption( "joint \n\t repair", 20, 2 );

		$this->assertSame( [ 'joint repair' ], $lines );
	}

	/**
	 * Layout defaults carry every key the renderers consume, with the
	 * spec'd headline geometry (band 120, square slice 569, footer 96).
	 */
	public function test_defaults_shape_matches_renderer_contract(): void {
		$defaults = \PRAutoBlogger_Image_Composer_Layout::defaults();

		$this->assertSame( [ 'featured', 'og', 'square' ], array_keys( $defaults ) );

		$this->assertSame( 28, $defaults['featured']['mark_height'] );
		$this->assertSame( 0.55, $defaults['featured']['mark_opacity'] );

		$this->assertSame( 1200, $defaults['og']['width'] );
		$this->assertSame( 630, $defaults['og']['height'] );
		$this->assertSame( 120, $defaults['og']['band_height'] );
		$this->assertSame( 2, $defaults['og']['caption_max_lines'] );

		$this->assertSame( 1080, $defaults['square']['width'] );
		$this->assertSame( 1080, $defaults['square']['height'] );
		$this->assertSame( 569, $defaults['square']['slice_height'] );
		$this->assertSame( 96, $defaults['square']['footer_height'] );
		$this->assertSame( 3, $defaults['square']['caption_max_lines'] );

		foreach ( [ 'og', 'square' ] as $role ) {
			foreach ( [ 'caption_font', 'caption_size', 'caption_color', 'caption_chars_per_line', 'caption_line_height' ] as $key ) {
				$this->assertArrayHasKey( $key, $defaults[ $role ], "$role missing $key" );
			}
		}
	}
}
