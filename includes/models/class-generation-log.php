<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Value object for an API call log entry (cost tracking).
 *
 * Triggered by: Cost_Tracker creates these; Metrics_Page displays them.
 * Dependencies: None — pure data object.
 *
 * @see core/class-cost-tracker.php    — Writes these to prab_generation_log table.
 * @see admin/class-metrics-page.php   — Reads and displays these.
 */
class PRAutoBlogger_Generation_Log {

	private int $id;
	private ?int $post_id;
	private ?string $run_id;
	private string $stage;
	private string $provider;
	private string $model;
	private int $prompt_tokens;
	private int $completion_tokens;
	private float $estimated_cost;
	private ?string $request_json;
	private string $response_status;
	private ?string $error_message;
	private ?string $agent_role;
	private ?string $prompt_version;
	private string $created_at;

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data ) {
		$this->id                = (int) ( $data['id'] ?? 0 );
		$this->post_id           = isset( $data['post_id'] ) ? (int) $data['post_id'] : null;
		$this->run_id            = $data['run_id'] ?? null;
		$this->stage             = $data['stage'] ?? '';
		$this->provider          = $data['provider'] ?? '';
		$this->model             = $data['model'] ?? '';
		$this->prompt_tokens     = (int) ( $data['prompt_tokens'] ?? 0 );
		$this->completion_tokens = (int) ( $data['completion_tokens'] ?? 0 );
		$this->estimated_cost    = (float) ( $data['estimated_cost'] ?? 0.0 );
		$this->request_json      = $data['request_json'] ?? null;
		$this->response_status   = $data['response_status'] ?? 'success';
		$this->error_message     = $data['error_message'] ?? null;
		$this->agent_role        = $data['agent_role'] ?? null;
		$this->prompt_version    = isset( $data['prompt_version'] ) ? (string) $data['prompt_version'] : null;
		$this->created_at        = $data['created_at'] ?? current_time( 'mysql' );
	}

	public function get_id(): int {
		return $this->id; }
	public function get_post_id(): ?int {
		return $this->post_id; }
	public function get_run_id(): ?string {
		return $this->run_id; }
	public function get_stage(): string {
		return $this->stage; }
	public function get_provider(): string {
		return $this->provider; }
	public function get_model(): string {
		return $this->model; }
	public function get_prompt_tokens(): int {
		return $this->prompt_tokens; }
	public function get_completion_tokens(): int {
		return $this->completion_tokens; }
	public function get_estimated_cost(): float {
		return $this->estimated_cost; }
	public function get_request_json(): ?string {
		return $this->request_json; }
	public function get_response_status(): string {
		return $this->response_status; }
	public function get_error_message(): ?string {
		return $this->error_message; }
	public function get_agent_role(): ?string {
		return $this->agent_role; }
	public function get_prompt_version(): ?string {
		return $this->prompt_version; }
	public function get_created_at(): string {
		return $this->created_at; }

	/**
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		return array(
			'post_id'           => $this->post_id,
			'run_id'            => $this->run_id,
			'stage'             => $this->stage,
			'provider'          => $this->provider,
			'model'             => $this->model,
			'prompt_tokens'     => $this->prompt_tokens,
			'completion_tokens' => $this->completion_tokens,
			'estimated_cost'    => $this->estimated_cost,
			'request_json'      => $this->request_json,
			'response_status'   => $this->response_status,
			'error_message'     => $this->error_message,
			'agent_role'        => $this->agent_role,
			'prompt_version'    => $this->prompt_version,
			'created_at'        => $this->created_at,
		);
	}
}
