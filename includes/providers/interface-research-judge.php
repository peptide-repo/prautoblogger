<?php
declare(strict_types=1);

/**
 * Contract for the research judge that curates fan-out results.
 *
 * @see core/class-research-judge.php — Primary implementation.
 * @see ARCHITECTURE.md               — Phase 2b curate stage.
 */
interface PRAutoBlogger_Research_Judge_Interface {

    /**
     * Deduplicate and score the fan-out results; write keep/discard decisions
     * to run_sources; return kept sources for the draft stage.
     *
     * @param string $run_id   Run UUID.
     * @param string $item_key Article-scoped item key.
     * @param array<int, array{sources: array<int, array{url: string, title: string, excerpt: string, relevance: float}>, agent_role: string}> $fanout_results Agent results from Research_Fanout::dispatch().
     * @return array<int, array{url: string, title: string, excerpt: string, relevance: float, quality_score: float}> Kept, deduplicated, scored sources.
     */
    public function curate(
        string $run_id,
        string $item_key,
        array $fanout_results
    ): array;
}
