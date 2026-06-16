<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Shared keyword-extraction utility for the scoring and dedup pipeline.
 *
 * Extracts meaningful lowercase tokens from free text by stripping stop-words.
 * Used by both PRAutoBlogger_Idea_Scorer and PRAutoBlogger_Semantic_Dedup as the
 * fallback path when embedding similarity is unavailable.
 *
 * What: Static utility; no state.
 * Who triggers: PRAutoBlogger_Idea_Scorer, PRAutoBlogger_Semantic_Dedup.
 * Dependencies: none.
 *
 * @see core/class-idea-scorer.php    -- Primary consumer (scoring pipeline).
 * @see core/class-semantic-dedup.php -- Secondary consumer (embedding fallback).
 */
class PRAutoBlogger_Keyword_Extractor {

	/**
	 * Common English stop-words filtered out before keyword matching.
	 *
	 * @var string[]
	 */
	private const STOPWORDS = array(
		'a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were',
		'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as',
		'it', 'its', 'this', 'that', 'your', 'you', 'how', 'what', 'when',
		'why', 'where', 'which', 'who', 'do', 'does', 'did', 'not', 'no',
		'can', 'will', 'should', 'could', 'would', 'may', 'might', 'have',
		'has', 'had', 'be', 'been', 'being', 'about', 'into', 'through',
		'during', 'before', 'after', 'above', 'below', 'between', 'same',
		'up', 'down', 'out', 'off', 'over', 'under', 'again', 'then', 'here',
		'there', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
		'other', 'some', 'such', 'than', 'too', 'very', 'just', 'also',
		'only', 'own', 'so', 'if', 'while', 'because', 'until', 'vs',
		'versus', 'guide', 'complete', 'ultimate', 'best', 'top', 'new',
		'first', 'need', 'know', 'everything',
	);

	/**
	 * Extract meaningful keywords from text.
	 *
	 * Lowercases input, splits on non-alphanumeric characters, filters
	 * stop-words and single-character tokens, and de-duplicates.
	 *
	 * @param string $text Input text.
	 * @return string[] Unique lowercase keyword tokens.
	 */
	public static function extract( string $text ): array {
		$text  = strtolower( $text );
		$words = preg_split( '/[^a-z0-9-]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_filter(
			$words,
			static function ( string $w ): bool {
				return strlen( $w ) >= 2 && ! in_array( $w, self::STOPWORDS, true );
			}
		);

		return array_values( array_unique( $words ) );
	}
}
