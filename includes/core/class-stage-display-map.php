<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Display map for every generation_log / run_stages `stage` value, old and new.
 *
 * What: Single source of truth that renders historical stage values
 *       (analysis, outline, draft, polish, review, llm_research, image_a,
 *       image_b, image_prompt_rewrite, opik_eval_judge), the Pipeline v2
 *       vocabulary (research, curate, draft, editorial, seo, publish), and
 *       any unknown value (humanized fallback) coherently. Also maps each
 *       stage to its default agent role and primary prompt-registry key so
 *       audit stamping never hardcodes those associations at call sites.
 *       The `stage` column is VARCHAR — this PHP map is the vocabulary,
 *       there is no SQL enum.
 * Who triggers it: Cost_Tracker (stamping), audit/metrics rendering, Run_State.
 * Dependencies: none — pure static lookup.
 *
 * @see core/class-cost-tracker.php    — Derives agent_role / prompt key when not passed.
 * @see core/class-prompt-registry.php — Owns the prompt keys referenced here.
 * @see ARCHITECTURE.md                — Stage vocabulary (canonical edit-stage name: polish).
 */
class PRAutoBlogger_Stage_Display_Map {

	/**
	 * Stage definitions: label (translated lazily), default agent role,
	 * and the primary prompt-registry key the stage renders with (null
	 * when the stage has no registry-managed prompt).
	 *
	 * @var array<string, array{label: string, role: ?string, prompt_key: ?string}>
	 */
	private const MAP = array(
		// ── Historical vocabulary (rows that exist on prod) ─────────────
		'analysis'             => array(
			'label'      => 'Topic Analysis',
			'role'       => 'analyst',
			'prompt_key' => 'analysis.system',
		),
		'outline'              => array(
			'label'      => 'Outline',
			'role'       => 'writer',
			'prompt_key' => 'content.outline',
		),
		'draft'                => array(
			'label'      => 'Draft',
			'role'       => 'writer',
			'prompt_key' => 'content.draft',
		),
		'polish'               => array(
			'label'      => 'Polish',
			'role'       => 'writer',
			'prompt_key' => 'content.polish',
		),
		'review'               => array(
			'label'      => 'Editorial Review',
			'role'       => 'editor',
			'prompt_key' => 'editor.system',
		),
		'llm_research'         => array(
			'label'      => 'LLM Research',
			'role'       => 'researcher',
			'prompt_key' => 'research.system',
		),
		'image_a'              => array(
			'label'      => 'Image A',
			'role'       => 'illustrator',
			'prompt_key' => 'image.style_template',
		),
		'image_b'              => array(
			'label'      => 'Image B',
			'role'       => 'illustrator',
			'prompt_key' => 'image.style_template',
		),
		'image_prompt_rewrite' => array(
			'label'      => 'Image Prompt Rewrite',
			'role'       => 'illustrator',
			'prompt_key' => 'image.rewriter_system',
		),
		'opik_eval_judge'      => array(
			'label'      => 'Opik Eval Judge',
			'role'       => 'judge',
			'prompt_key' => null,
		),
		// ── Pipeline v2 vocabulary (new runs may write these) ───────────
		'research'             => array(
			'label'      => 'Research',
			'role'       => 'researcher',
			'prompt_key' => 'research.system',
		),
		'curate'               => array(
			'label'      => 'Curate',
			'role'       => 'curator',
			'prompt_key' => null,
		),
		'editorial'            => array(
			'label'      => 'Editorial Loop',
			'role'       => 'editor',
			'prompt_key' => null,
		),
		'seo'                  => array(
			'label'      => 'SEO',
			'role'       => 'seo',
			'prompt_key' => null,
		),
		'publish'              => array(
			'label'      => 'Publish',
			'role'       => 'publisher',
			'prompt_key' => null,
		),
	);

	/**
	 * Human-readable label for a stage value. Unknown stages are humanized
	 * (underscores to spaces, words capitalized) so the audit view never
	 * renders blank for a value this map predates or postdates.
	 *
	 * @param string $stage Raw stage value from the database.
	 * @return string Translated display label.
	 */
	public static function label( string $stage ): string {
		if ( isset( self::MAP[ $stage ] ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- labels are a fixed const set.
			return __( self::MAP[ $stage ]['label'], 'prautoblogger' );
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $stage ) );
	}

	/**
	 * Default agent role recorded for a stage when the call site does not
	 * pass one explicitly. Null for unknown stages (column stays NULL).
	 *
	 * @param string $stage Raw stage value.
	 * @return string|null Agent role slug, or null when unknown.
	 */
	public static function default_agent_role( string $stage ): ?string {
		return self::MAP[ $stage ]['role'] ?? null;
	}

	/**
	 * Primary prompt-registry key a stage renders with. Used to resolve the
	 * pinned prompt_version stamped on generation_log rows when the call
	 * site does not pass an explicit key (e.g. single-pass passes
	 * 'content.single_pass' for its 'draft' row).
	 *
	 * @param string $stage Raw stage value.
	 * @return string|null Registry key, or null when the stage has none.
	 */
	public static function default_prompt_key( string $stage ): ?string {
		return self::MAP[ $stage ]['prompt_key'] ?? null;
	}

	/**
	 * Whether the stage value is part of the known vocabulary.
	 *
	 * @param string $stage Raw stage value.
	 * @return bool True when the stage is a known historical or v2 value.
	 */
	public static function is_known( string $stage ): bool {
		return isset( self::MAP[ $stage ] );
	}

	/**
	 * The full vocabulary, for audit listings and tests.
	 *
	 * @return array<string, array{label: string, role: ?string, prompt_key: ?string}>
	 */
	public static function all(): array {
		return self::MAP;
	}
}
