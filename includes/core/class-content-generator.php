<?php
declare(strict_types=1);

/**
 * Writer agent — generates blog post content from article ideas.
 *
 * Supports: single_pass (one LLM call) or multi_step (outline → draft → polish).
 * Opik instrumentation: spans per LLM call when feature-flag enabled.
 * v0.18.0: prompt copy renders from the versioned prompt registry (with
 * byte-identical in-code fallback); every log row carries the run-pinned
 * prompt_version. The four stage methods share one execute_stage() helper —
 * span lifecycle, LLM dispatch, and cost logging are identical across stages.
 *
 * Triggered by: PRAutoBlogger_Article_Worker.
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, Content_Prompts.
 */
class PRAutoBlogger_Content_Generator {

	private PRAutoBlogger_LLM_Provider_Interface $llm;
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/** @var string|null Run UUID for stage-state checkpointing (null = off). */
	private ?string $run_id = null;

	/** @var string|null Stage item key scoping this article within the run. */
	private ?string $item_key = null;

	public function __construct(
		PRAutoBlogger_LLM_Provider_Interface $llm,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	) {
		$this->llm          = $llm;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Enable per-stage state checkpointing for one article item (v0.18.0).
	 *
	 * When set, every LLM stage (outline/draft/polish or the single-pass
	 * draft) records pending->running->done state and snapshots its output;
	 * a re-entered done stage returns the snapshot WITHOUT a new LLM call
	 * (never re-charged). When unset (default), behavior is exactly
	 * pre-v0.18.0 — used by callers outside a run (e.g. eval mode).
	 *
	 * @param string|null $run_id   Run UUID, or null to disable.
	 * @param string|null $item_key Item key from Run_Stage_State::item_key_for_idea().
	 * @return $this
	 */
	public function set_run_item( ?string $run_id, ?string $item_key ): self {
		$this->run_id   = $run_id;
		$this->item_key = $item_key;
		return $this;
	}

	/**
	 * Generate a blog post from an article idea.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea      The scored idea to generate content for.
	 * @param bool                       $eval_mode True to suppress publish/image side effects.
	 * @return string Generated HTML content.
	 */
	public function generate( PRAutoBlogger_Article_Idea $idea, bool $eval_mode = false ): string {
		// In eval mode, suppress publishing and image generation side effects.
		define( 'PRAUTOBLOGGER_EVAL_MODE', $eval_mode );

		$mode = get_option( 'prautoblogger_writing_pipeline', 'multi_step' );

		$request = new PRAutoBlogger_Content_Request(
			$idea,
			$mode,
			get_option( 'prautoblogger_tone', 'informational' ),
			absint( get_option( 'prautoblogger_min_word_count', 800 ) ),
			absint( get_option( 'prautoblogger_max_word_count', 2000 ) ),
			get_option( 'prautoblogger_niche_description', '' ),
			json_decode( get_option( 'prautoblogger_topic_exclusions', '[]' ), true ) ?? array(),
			get_option( 'prautoblogger_writing_instructions', '' )
		);

		return 'single_pass' === $mode
			? $this->generate_single_pass( $request )
			: $this->generate_multi_step( $request );
	}

	/**
	 * Single-pass: one LLM call produces complete article (Economy tier).
	 *
	 * The log row's stage stays 'draft' (historical contract: a draft row
	 * without an outline row marks a single-pass run) but the prompt key
	 * is passed explicitly — the stage map would resolve 'draft' to
	 * 'content.draft'.
	 *
	 * @param PRAutoBlogger_Content_Request $request Generation request.
	 * @return string Generated HTML content.
	 */
	private function generate_single_pass( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		return $this->execute_stage(
			'draft',
			'single_pass_generation',
			PRAutoBlogger_Content_Prompts::build_single_pass( $request ),
			$request,
			$model,
			array(
				'temperature' => 0.7,
				'max_tokens'  => 4000,
			),
			'content.single_pass'
		);
	}

	/**
	 * Multi-step: outline → draft → polish.
	 *
	 * @param PRAutoBlogger_Content_Request $request Generation request.
	 * @return string Generated HTML content.
	 */
	private function generate_multi_step( PRAutoBlogger_Content_Request $request ): string {
		$model = get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );

		$outline = $this->execute_stage(
			'outline',
			'outline_generation',
			PRAutoBlogger_Content_Prompts::build_outline( $request ),
			$request,
			$model,
			array(
				'temperature' => 0.5,
				'max_tokens'  => 1500,
			)
		);

		$draft = $this->execute_stage(
			'draft',
			'draft_generation',
			PRAutoBlogger_Content_Prompts::build_draft( $request, $outline ),
			$request,
			$model,
			array(
				'temperature' => 0.7,
				'max_tokens'  => 4000,
			)
		);

		return $this->execute_stage(
			'polish',
			'polish_generation',
			PRAutoBlogger_Content_Prompts::build_polish( $draft ),
			$request,
			$model,
			array(
				'temperature' => 0.4,
				'max_tokens'  => 4000,
			)
		);
	}

	/**
	 * Run one writing stage: Opik span → LLM call → span close → cost log.
	 *
	 * Consolidates the per-stage boilerplate that was previously duplicated
	 * across the four stage methods (v0.18.0). The system prompt is rebuilt
	 * per stage exactly as before.
	 *
	 * Side effects: one OpenRouter API call, one Opik span (when tracing),
	 * one generation_log row.
	 *
	 * @param string                        $stage       Log stage value ('outline'|'draft'|'polish').
	 * @param string                        $span_name   Opik span name.
	 * @param string                        $user_prompt Rendered user prompt for the stage.
	 * @param PRAutoBlogger_Content_Request $request     Generation request (system prompt input).
	 * @param string                        $model       Model identifier.
	 * @param array<string, mixed>          $options     LLM options (temperature, max_tokens).
	 * @param string|null                   $prompt_key  Explicit registry key for prompt_version
	 *                                                   stamping (null = derive from stage).
	 * @return string Stage output content.
	 */
	private function execute_stage(
		string $stage,
		string $span_name,
		string $user_prompt,
		PRAutoBlogger_Content_Request $request,
		string $model,
		array $options,
		?string $prompt_key = null
	): string {
		// Idempotent resume: a done stage returns its snapshot, no LLM call.
		if ( null !== $this->run_id && null !== $this->item_key ) {
			$cached = PRAutoBlogger_Run_Stage_State::get_output( $this->run_id, $stage, '', $this->item_key );
			if ( null !== $cached ) {
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Reusing completed %s stage output (idempotent resume).', $stage ),
					'pipeline'
				);
				return $cached;
			}
			PRAutoBlogger_Run_Stage_State::start( $this->run_id, $stage, '', $this->item_key );
		}

		$ctx = PRAutoBlogger_Opik_Trace_Context::current();

		$span_id = $ctx->start_span(
			array(
				'name'     => $span_name,
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => PRAutoBlogger_Content_Prompts::build_system( $request ),
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$model,
			$options
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			$stage,
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens'],
			'success',
			'',
			null,
			$prompt_key
		);

		if ( null !== $this->run_id && null !== $this->item_key ) {
			PRAutoBlogger_Run_Stage_State::done(
				$this->run_id,
				$stage,
				'',
				$this->item_key,
				(string) $response['content'],
				$this->llm->estimate_cost( $response['model'], (int) $response['prompt_tokens'], (int) $response['completion_tokens'] )
			);
		}

		return $response['content'];
	}

	/**
	 * Generate content in eval mode (no publishing, no image generation).
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate content for.
	 * @return string Generated HTML content.
	 */
	public function generate_eval( PRAutoBlogger_Article_Idea $idea ): string {
		return $this->generate( $idea, true );
	}
}
