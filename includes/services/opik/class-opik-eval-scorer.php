<?php
declare(strict_types=1);

/**
 * LLM-as-judge scorer for eval content.
 *
 * Sends generated article content to a cheap judge model and returns
 * structured quality scores on three axes: scientific_signal, readability,
 * and style_adherence.
 *
 * Judge model: google/gemini-flash-1.5 via OpenRouter (configurable).
 *
 * Triggered by: PRAutoBlogger_Opik_Eval_Runner.
 * Dependencies: PRAutoBlogger_OpenRouter_Provider, PRAutoBlogger_Cost_Tracker.
 */
class PRAutoBlogger_Opik_Eval_Scorer {

	/**
	 * Default judge model. Cheap and fast, good at JSON output.
	 * Configurable via PRAUTOBLOGGER_OPIK_JUDGE_MODEL constant.
	 */
	private const DEFAULT_JUDGE_MODEL = 'google/gemini-flash-1.5';

	/**
	 * @var PRAutoBlogger_OpenRouter_Provider LLM provider for judge.
	 */
	private PRAutoBlogger_OpenRouter_Provider $provider;

	/**
	 * @var PRAutoBlogger_Cost_Tracker Cost tracker for eval judge calls.
	 */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * Constructor.
	 *
	 * @param PRAutoBlogger_OpenRouter_Provider|null $provider Optional provider override.
	 * @param PRAutoBlogger_Cost_Tracker|null        $cost_tracker Optional cost tracker.
	 */
	public function __construct(
		?PRAutoBlogger_OpenRouter_Provider $provider = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	) {
		$this->provider      = $provider ?? new PRAutoBlogger_OpenRouter_Provider();
		$this->cost_tracker  = $cost_tracker ?? new PRAutoBlogger_Cost_Tracker();
	}

	/**
	 * Score generated content on three axes.
	 *
	 * @param string $title Article title.
	 * @param string $content Generated article HTML/text content.
	 *
	 * @return array{
	 *     scientific_signal: int,
	 *     readability: int,
	 *     style_adherence: int,
	 *     rationale: string,
	 *     judge_model: string,
	 *     tokens: int,
	 *     cost_usd: float
	 * }
	 */
	public function score( string $title, string $content ): array {
		$system_prompt = $this->get_system_prompt();
		$user_message  = $this->build_user_message( $title, $content );
		$model         = $this->get_judge_model();

		try {
			$response = $this->provider->send_chat_completion(
				array(
					array(
						'role'    => 'system',
						'content' => $system_prompt,
					),
					array(
						'role'    => 'user',
						'content' => $user_message,
					),
				),
				$model,
				array(
					'temperature' => 0.1,
					'max_tokens'  => 200,
					'stage'       => 'opik_eval_judge',
				)
			);

			$raw_json = trim( $response['content'] ?? '' );

			// Log judge call cost.
			$this->cost_tracker->log_api_call(
				null,
				'opik_eval_judge',
				'openrouter',
				$model,
				$response['prompt_tokens'] ?? 0,
				$response['completion_tokens'] ?? 0
			);

			$total_tokens = ( $response['prompt_tokens'] ?? 0 ) + ( $response['completion_tokens'] ?? 0 );
			$cost         = $this->provider->estimate_cost( $model, $response['prompt_tokens'] ?? 0, $response['completion_tokens'] ?? 0 );

			// Parse JSON response.
			$data = json_decode( $raw_json, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			return array(
				'scientific_signal' => $this->clamp_score( $data['scientific_signal'] ?? 5 ),
				'readability'       => $this->clamp_score( $data['readability'] ?? 5 ),
				'style_adherence'   => $this->clamp_score( $data['style_adherence'] ?? 5 ),
				'rationale'         => $data['rationale'] ?? 'Judge call failed to parse.',
				'judge_model'       => $model,
				'tokens'            => $total_tokens,
				'cost_usd'          => $cost,
			);
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Eval scorer failed: %s', $e->getMessage() ),
				'opik_eval'
			);

			// Return neutral scores on failure.
			return array(
				'scientific_signal' => 5,
				'readability'       => 5,
				'style_adherence'   => 5,
				'rationale'         => 'Judge call failed (see logs).',
				'judge_model'       => $model,
				'tokens'            => 0,
				'cost_usd'          => 0.0,
			);
		}
	}

	/**
	 * Get the judge system prompt.
	 *
	 * @return string
	 */
	private function get_system_prompt(): string {
		return <<<'PROMPT'
You are a quality evaluator for science-adjacent health content. Score the following article on three axes. Respond ONLY with a JSON object — no explanation, no markdown.

Format:
{"scientific_signal": <0-10>, "readability": <0-10>, "style_adherence": <0-10>, "rationale": "<one sentence>"}
PROMPT;
	}

	/**
	 * Build the user message for the judge.
	 *
	 * @param string $title Article title.
	 * @param string $content Article content.
	 *
	 * @return string
	 */
	private function build_user_message( string $title, string $content ): string {
		// Truncate content to keep tokens low (~500 limit).
		$max_len = 2000;
		if ( strlen( $content ) > $max_len ) {
			$content = substr( $content, 0, $max_len ) . '...';
		}

		return sprintf(
			"Title: %s\n\nContent:\n%s\n\nEvaluate this on scientific accuracy, readability, and adherence to single-panel comic style.",
			esc_attr( $title ),
			$content
		);
	}

	/**
	 * Clamp a score to 0-10 range.
	 *
	 * @param mixed $score
	 *
	 * @return int
	 */
	private function clamp_score( $score ): int {
		$val = (int) $score;
		return max( 0, min( 10, $val ) );
	}

	/**
	 * Get the judge model, configurable via constant.
	 *
	 * @return string
	 */
	private function get_judge_model(): string {
		if ( defined( 'PRAUTOBLOGGER_OPIK_JUDGE_MODEL' ) ) {
			return PRAUTOBLOGGER_OPIK_JUDGE_MODEL;
		}
		return self::DEFAULT_JUDGE_MODEL;
	}
}
