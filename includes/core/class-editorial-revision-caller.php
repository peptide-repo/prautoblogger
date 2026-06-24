<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Writer-revision step for the Authority editorial loop.
 *
 * What: Extracted from Editorial_Loop (300-line split, v0.29.0). Holds the
 *       single-round writer revision logic — builds the revision prompts,
 *       dispatches the LLM call, logs cost, and returns the revised HTML.
 *       Editorial_Loop delegates to this class whenever the chief editor
 *       returns a non-approved verdict and no inline revision was provided.
 * Who triggers it: PRAutoBlogger_Editorial_Loop::run_writer_revision().
 * Dependencies: PRAutoBlogger_LLM_Provider_Interface (writer model call),
 *       PRAutoBlogger_Cost_Tracker (log_api_call), PRAutoBlogger_Content_Prompts
 *       (build_revision_system / build_revision_user), PRAutoBlogger_Run_Stage_State
 *       (stage lifecycle), PRAutoBlogger_Logger.
 *
 * @see core/class-editorial-loop.php    — Orchestrator; creates this object.
 * @see core/class-content-prompts.php   — Prompt builders for revision calls.
 * @see ARCHITECTURE.md                  — Phase 2b editorial loop design.
 */
class PRAutoBlogger_Editorial_Revision_Caller {

	/** @var PRAutoBlogger_LLM_Provider_Interface LLM provider for the writer model. */
	private PRAutoBlogger_LLM_Provider_Interface $writer_llm;

	/** @var PRAutoBlogger_Cost_Tracker Shared cost tracker. */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * @param PRAutoBlogger_LLM_Provider_Interface $writer_llm   LLM provider for revision calls.
	 * @param PRAutoBlogger_Cost_Tracker           $cost_tracker Pipeline cost tracker.
	 */
	public function __construct(
		PRAutoBlogger_LLM_Provider_Interface $writer_llm,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	) {
		$this->writer_llm   = $writer_llm;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Ask the writer LLM to revise the current draft given the editor's critique.
	 *
	 * Dispatches a targeted revision call via the injected writer_llm provider.
	 * Cost is reserved before dispatch via the existing OpenRouter provider
	 * seam (Cost_Governor::open_chat_reservation() is called inside
	 * send_chat_completion) and logged via the shared cost tracker.
	 * Returns the prior draft unchanged on empty LLM response (never silent
	 * pass of an empty revision).
	 *
	 * Side effects: one OpenRouter API call, one generation_log row, two
	 *   run_stages transitions (start→done for role='writer').
	 *
	 * @param int                        $round        1-based round number (for logging and stage snapshot).
	 * @param string                     $run_id       Pipeline run UUID.
	 * @param string                     $item_key     Article-scoped stage item key.
	 * @param string                     $current      Current draft HTML to revise.
	 * @param PRAutoBlogger_Article_Idea $idea         Article idea (for topic/title context).
	 * @param string                     $editor_notes Chief editor critique to address.
	 * @return string Revised HTML content, or $current if the LLM returned empty.
	 */
	public function call(
		int $round,
		string $run_id,
		string $item_key,
		string $current,
		PRAutoBlogger_Article_Idea $idea,
		string $editor_notes
	): string {
		PRAutoBlogger_Run_Stage_State::start( $run_id, 'editorial', 'writer', $item_key );

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Editorial revision: round %d — dispatching writer revision.', $round ),
			'editorial-loop'
		);

		$model    = (string) get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => PRAutoBlogger_Content_Prompts::build_revision_system( $idea ),
			),
			array(
				'role'    => 'user',
				'content' => PRAutoBlogger_Content_Prompts::build_revision_user( $current, $editor_notes ),
			),
		);

		$response = $this->writer_llm->send_chat_completion(
			$messages,
			$model,
			array(
				'temperature' => 0.5,
				'max_tokens'  => 5000,
				'stage'       => 'editorial',
				'prompt_key'  => 'content.revision',
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'editorial',
			$this->writer_llm->get_provider_name(),
			(string) ( $response['model'] ?? $model ),
			(int) ( $response['prompt_tokens'] ?? 0 ),
			(int) ( $response['completion_tokens'] ?? 0 ),
			'success',
			'',
			null,
			'content.revision'
		);

		$revised = trim( (string) ( $response['content'] ?? '' ) );
		if ( '' === $revised ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf(
					'Editorial revision: round %d writer returned empty content; retaining prior draft.',
					$round
				),
				'editorial-loop'
			);
			$revised = $current;
		}

		PRAutoBlogger_Run_Stage_State::done(
			$run_id,
			'editorial',
			'writer',
			$item_key,
			(string) wp_json_encode( array( 'round' => $round ) )
		);

		return $revised;
	}
}
