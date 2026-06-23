<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Parallel specialist research fan-out for Authority-tier articles.
 *
 * What: Dispatches N specialist LLM research agents in parallel (via
 *       PRAutoBlogger_Research_Batch / curl_multi), each researching a
 *       different angle of the article topic. Before dispatch, reserves
 *       the SUMMED worst-case cost of the entire batch from the per-run
 *       cost governor — a single atomic write so concurrent writers
 *       cannot slip past the per-run ceiling between check and call.
 *       Quorum check (⌈N/2⌉+1 usable results required) guards against
 *       thin research proceeding on partial failures. Invalid or
 *       schema-mismatched agent results are excluded and never silently
 *       passed. Failing agents mark their run_stages rows as failed.
 * Who triggers it: PRAutoBlogger_Authority_Pipeline (Phase 2b.4, the
 *       tier router). NOT wired into the Economy (generate_single_pass)
 *       path — additive only, not live until P2b.4.
 * Dependencies: PRAutoBlogger_Cost_Governor (reserve-before-dispatch),
 *       PRAutoBlogger_Research_Batch (curl_multi execution),
 *       PRAutoBlogger_Run_Stage_State (per-agent stage rows),
 *       PRAutoBlogger_Prompt_Registry (research.system prompt),
 *       PRAutoBlogger_Json_Extractor (JSON schema validation),
 *       PRAutoBlogger_Cost_Tracker (log_api_call), Logger.
 *
 * @see providers/interface-research-fanout.php   — Interface this class implements.
 * @see core/class-research-batch.php             — curl_multi execution delegate.
 * @see core/class-research-judge.php             — Consumes these results (curate).
 * @see ARCHITECTURE.md                           — Phase 2b data flow.
 */
class PRAutoBlogger_Research_Fanout implements PRAutoBlogger_Research_Fanout_Interface {

	/** Maximum configurable agent count. */
	private const MAX_AGENTS = 5;

	/** Minimum configurable agent count. */
	private const MIN_AGENTS = 1;

	/** Specialist roles in priority order; first N are used per run. */
	private const SPECIALIST_ROLES = array(
		'mechanisms',
		'clinical',
		'safety',
		'comparison',
		'practical',
	);

	/** @var PRAutoBlogger_Research_Batch curl_multi execution delegate. */
	private PRAutoBlogger_Research_Batch $batch;

	/**
	 * @param PRAutoBlogger_Research_Batch|null $batch Optional batch override (tests).
	 */
	public function __construct( ?PRAutoBlogger_Research_Batch $batch = null ) {
		$this->batch = $batch ?? new PRAutoBlogger_Research_Batch();
	}

	/**
	 * Dispatch N specialist research agents in parallel and collect results.
	 *
	 * Returns an indexed array of per-agent result maps. If fewer than
	 * quorum (⌈N/2⌉+1) agents return usable results, returns an empty
	 * array — the caller should HOLD the run rather than proceeding on
	 * thin research.
	 *
	 * Side effects: curl_multi HTTP calls to OpenRouter, run_stages DB
	 *       writes, cost-governor reservation + settlement, Logger calls.
	 *
	 * @param string                     $run_id       Run UUID.
	 * @param string                     $item_key     Article-scoped item key.
	 * @param PRAutoBlogger_Article_Idea $idea         The idea being researched.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Pipeline cost tracker.
	 * @return array<int, array{sources: array<int, array{url: string, title: string, excerpt: string, relevance: float}>, agent_role: string}> Per-agent results, or empty when quorum not met.
	 */
	public function dispatch(
		string $run_id,
		string $item_key,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): array {
		$n       = $this->resolve_agent_count();
		$model   = $this->resolve_model();
		$quorum  = (int) ceil( $n / 2 ) + 1;
		$roles   = array_slice( self::SPECIALIST_ROLES, 0, $n );
		$topic   = $idea->get_topic();
		$title   = $idea->get_suggested_title();

		$messages_per_agent = $this->build_agent_messages( $topic, $title, $roles );
		$options            = array(
			'temperature'     => 0.3,
			'max_tokens'      => 3000,
			'response_format' => array( 'type' => 'json_object' ),
		);

		// Reserve the SUMMED worst-case cost of all N agents before dispatch.
		$per_estimate   = PRAutoBlogger_Cost_Governor::estimate_chat_cost( $model, $messages_per_agent[0], $options );
		$batch_estimate = $per_estimate * $n;
		$reservation    = PRAutoBlogger_Cost_Governor::open_amount_reservation(
			$batch_estimate,
			sprintf( 'research_fanout:n=%d:%s', $n, $model )
		);

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Research fan-out: dispatching %d agents (quorum=%d) for "%s". Batch reserve: $%.4f.',
				$n,
				$quorum,
				mb_substr( $topic, 0, 60 ),
				$batch_estimate
			),
			'research-fanout'
		);

		foreach ( $roles as $role ) {
			PRAutoBlogger_Run_Stage_State::start( $run_id, 'research', 'researcher:' . $role, $item_key );
		}

		$raw_results   = $this->batch->execute( $model, $messages_per_agent, $options, $roles );
		$valid_results = array();
		$actual_total  = 0.0;

		foreach ( $raw_results as $idx => $raw ) {
			$role = $roles[ $idx ];
			if ( isset( $raw['error'] ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf( 'Research agent "%s" failed: %s', $role, $raw['error'] ),
					'research-fanout'
				);
				PRAutoBlogger_Run_Stage_State::fail( $run_id, 'research', 'researcher:' . $role, $item_key );
				continue;
			}

			$parsed  = PRAutoBlogger_Json_Extractor::decode( $raw['content'] ?? '' );
			$sources = $this->validate_sources( $parsed );

			if ( null === $sources ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf( 'Research agent "%s" returned invalid schema; excluding.', $role ),
					'research-fanout'
				);
				PRAutoBlogger_Run_Stage_State::fail( $run_id, 'research', 'researcher:' . $role, $item_key );
				continue;
			}

			$actual_cost   = (float) ( $raw['actual_cost'] ?? 0.0 );
			$actual_total += $actual_cost;

			$cost_tracker->log_api_call(
				null,
				'research',
				'openrouter',
				$raw['model'] ?? $model,
				(int) ( $raw['prompt_tokens'] ?? 0 ),
				(int) ( $raw['completion_tokens'] ?? 0 )
			);

			PRAutoBlogger_Run_Stage_State::done(
				$run_id,
				'research',
				'researcher:' . $role,
				$item_key,
				wp_json_encode( array( 'source_count' => count( $sources ) ) ),
				$actual_cost
			);

			$valid_results[] = array(
				'sources'    => $sources,
				'agent_role' => 'researcher:' . $role,
			);
		}

		PRAutoBlogger_Cost_Governor::settle( $reservation, $actual_total );

		$valid_count = count( $valid_results );
		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Research fan-out complete: %d/%d agents usable (quorum=%d).',
				$valid_count,
				$n,
				$quorum
			),
			'research-fanout'
		);

		if ( $valid_count < $quorum ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Research quorum not met (%d/%d). Run should hold.', $valid_count, $quorum ),
				'research-fanout'
			);
			return array();
		}

		return $valid_results;
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Read the configured agent count, clamped to [MIN_AGENTS, MAX_AGENTS].
	 *
	 * @return int
	 */
	private function resolve_agent_count(): int {
		$n = (int) get_option( 'prautoblogger_research_agent_count', 3 );
		return max( self::MIN_AGENTS, min( self::MAX_AGENTS, $n ) );
	}

	/**
	 * Read the configured research model.
	 *
	 * @return string
	 */
	private function resolve_model(): string {
		return (string) get_option( 'prautoblogger_research_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
	}

	/**
	 * Build the per-agent message sets (system + specialist user prompt).
	 *
	 * @param string   $topic Topic text.
	 * @param string   $title Suggested article title.
	 * @param string[] $roles Specialist role names.
	 * @return array<int, array<int, array{role: string, content: string}>>
	 */
	private function build_agent_messages( string $topic, string $title, array $roles ): array {
		$system = PRAutoBlogger_Prompt_Registry::render( 'research.system' );
		$sets   = array();
		foreach ( $roles as $role ) {
			$sets[] = array(
				array( 'role' => 'system', 'content' => $system ),
				array(
					'role'    => 'user',
					'content' => sprintf(
						"Topic: %s\nProposed title: %s\n\nSpecialist focus: %s\n\n" .
						"Find 3-6 high-quality sources for this topic from a '%s' perspective. " .
						"Return JSON: {\"sources\":[{\"url\":\"\",\"title\":\"\",\"excerpt\":\"\",\"relevance\":0.0}]}",
						$topic,
						$title,
						$role,
						$role
					),
				),
			);
		}
		return $sets;
	}

	/**
	 * Validate and normalise the parsed sources from one agent's response.
	 *
	 * Schema: `{sources: [{url, title, excerpt, relevance}]}`. Any entry
	 * missing both url and title is silently skipped. Returns null when
	 * the top-level schema is missing or sources is empty after filtering.
	 *
	 * @param array<string, mixed>|null $parsed Decoded JSON from one agent.
	 * @return array<int, array{url: string, title: string, excerpt: string, relevance: float}>|null
	 *
	 * Side effects: none.
	 */
	private function validate_sources( ?array $parsed ): ?array {
		if ( ! is_array( $parsed ) || ! isset( $parsed['sources'] ) || ! is_array( $parsed['sources'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $parsed['sources'] as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$url       = sanitize_url( (string) ( $s['url'] ?? '' ) );
			$title     = sanitize_text_field( (string) ( $s['title'] ?? '' ) );
			$excerpt   = sanitize_textarea_field( (string) ( $s['excerpt'] ?? '' ) );
			$relevance = max( 0.0, min( 1.0, (float) ( $s['relevance'] ?? 0.0 ) ) );
			if ( '' === $url && '' === $title ) {
				continue;
			}
			$out[] = array(
				'url'       => $url,
				'title'     => $title,
				'excerpt'   => $excerpt,
				'relevance' => $relevance,
			);
		}
		return count( $out ) > 0 ? $out : null;
	}
}
