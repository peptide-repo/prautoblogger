<?php
declare(strict_types=1);

/**
 * Contract for parallel specialist research fan-out.
 *
 * @see core/class-research-fanout.php — Primary implementation.
 * @see ARCHITECTURE.md                — Phase 2b design.
 */
interface PRAutoBlogger_Research_Fanout_Interface {

    /**
     * Dispatch N specialist research agents in parallel and collect results.
     *
     * @param string                $run_id      Pipeline run UUID.
     * @param string                $item_key    Article item key for stage scoping.
     * @param PRAutoBlogger_Article_Idea $idea   The idea being researched.
     * @param PRAutoBlogger_Cost_Tracker $cost_tracker Pipeline cost tracker.
     * @return array<int, array{sources: array<int, array{url: string, title: string, excerpt: string, relevance: float}>, agent_role: string}> Results indexed by agent slot (0..N-1). Empty array means quorum not met (run should hold).
     */
    public function dispatch(
        string $run_id,
        string $item_key,
        PRAutoBlogger_Article_Idea $idea,
        PRAutoBlogger_Cost_Tracker $cost_tracker
    ): array;
}
