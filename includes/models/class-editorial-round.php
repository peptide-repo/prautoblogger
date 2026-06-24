<?php
declare(strict_types=1);

/**
 * Immutable value object representing one round of the editorial loop.
 *
 * Captures the editor's critique and the writer's response for that
 * round so the audit trail can reconstruct the full loop history.
 *
 * Created by: PRAutoBlogger_Editorial_Loop per round.
 * Persisted by: PRAutoBlogger_Audit_Writer::record_editorial_round().
 *
 * @see core/class-editorial-loop.php      — Produces these objects.
 * @see core/class-audit-writer.php        — Persists them to run_decisions.
 * @see ARCHITECTURE.md                    — Phase 2b editorial loop design.
 */
class PRAutoBlogger_Editorial_Round {

	/** @var int Round number (1-based). */
	private int $round_number;

	/** @var string Editor's critique / notes for this round. */
	private string $editor_notes;

	/** @var string Editor's verdict for this round ('approved'|'revised'|'rejected'). */
	private string $editor_verdict;

	/** @var string Content produced by the writer after this round (empty on approval without revision). */
	private string $revised_content;

	/** @var float Editor quality score for this round (0.0–1.0). */
	private float $quality_score;

	/** @var float Editor SEO score for this round (0.0–1.0). */
	private float $seo_score;

	/**
	 * @param int    $round_number    1-based round index.
	 * @param string $editor_notes    Critique notes from the chief editor.
	 * @param string $editor_verdict  'approved', 'revised', or 'rejected'.
	 * @param string $revised_content Writer's revised content ('' if approved first time).
	 * @param float  $quality_score   Editor quality score (0.0–1.0).
	 * @param float  $seo_score       Editor SEO score (0.0–1.0).
	 */
	public function __construct(
		int $round_number,
		string $editor_notes,
		string $editor_verdict,
		string $revised_content,
		float $quality_score,
		float $seo_score
	) {
		$this->round_number    = $round_number;
		$this->editor_notes    = $editor_notes;
		$this->editor_verdict  = $editor_verdict;
		$this->revised_content = $revised_content;
		$this->quality_score   = $quality_score;
		$this->seo_score       = $seo_score;
	}

	/** @return int Round number (1-based). */
	public function get_round_number(): int {
		return $this->round_number;
	}

	/** @return string Critique notes. */
	public function get_editor_notes(): string {
		return $this->editor_notes;
	}

	/** @return string 'approved', 'revised', or 'rejected'. */
	public function get_editor_verdict(): string {
		return $this->editor_verdict;
	}

	/** @return string Writer's revised content for this round. */
	public function get_revised_content(): string {
		return $this->revised_content;
	}

	/** @return float Quality score (0.0–1.0). */
	public function get_quality_score(): float {
		return $this->quality_score;
	}

	/** @return float SEO score (0.0–1.0). */
	public function get_seo_score(): float {
		return $this->seo_score;
	}

	/**
	 * Serialise round to array for JSON snapshot / audit persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'round_number'    => $this->round_number,
			'editor_notes'    => $this->editor_notes,
			'editor_verdict'  => $this->editor_verdict,
			'revised_content' => $this->revised_content,
			'quality_score'   => $this->quality_score,
			'seo_score'       => $this->seo_score,
		);
	}
}
