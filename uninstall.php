<?php
/**
 * phpcs:ignore Generic.PHP.RequireStrictTypes.MissingDeclaration -- strict_types must precede docblock
 *
 * Fired when the plugin is uninstalled (deleted) from WordPress.
 *
 * Removes ALL plugin data: custom tables, options, post meta, transients, and cron events.
 * This is the nuclear option — only runs when the user explicitly deletes the plugin.
 *
 * @see class-deactivator.php — Lighter cleanup on deactivation (preserves data).
 */
declare(strict_types=1);

// Abort if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
|--------------------------------------------------------------------------
| 1. Drop Custom Tables
|--------------------------------------------------------------------------
*/

$prefix = $wpdb->prefix . 'prautoblogger_';
$tables = array(
	$prefix . 'source_data',
	$prefix . 'analysis_results',
	$prefix . 'generation_log',
	$prefix . 'content_scores',
	$prefix . 'event_log',
	// Pipeline v2 substrate (v0.18.0 / db 1.2.0).
	$prefix . 'prompts',
	$prefix . 'run_sources',
	$prefix . 'run_decisions',
	$prefix . 'runs',
	$prefix . 'run_stages',
	// Edit + re-run input versions (v0.20.0 / db 1.3.0).
	$prefix . 'stage_inputs',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are hardcoded above, not user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

/*
|--------------------------------------------------------------------------
| 2. Delete All Plugin Options
|--------------------------------------------------------------------------
*/

$options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'prautoblogger\_%'"
);

foreach ( $options as $option ) {
	delete_option( $option );
}

/*
|--------------------------------------------------------------------------
| 3. Delete All Plugin Post Meta
|--------------------------------------------------------------------------
*/

$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_prautoblogger\\_%'"
);
// P2b.3 (v0.30.0): SEO stage writes _prab_* keys (different prefix — covered separately).
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_prab\\_%'"
);

/*
|--------------------------------------------------------------------------
| 4. Delete All Plugin Transients
|--------------------------------------------------------------------------
*/

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_prautoblogger\\_%' OR option_name LIKE '\\_transient\\_timeout\\_prautoblogger\\_%'"
);

/*
|--------------------------------------------------------------------------
| 5. Clear Any Remaining Cron Events
|--------------------------------------------------------------------------
*/

$hooks = array(
	'prautoblogger_daily_generation',
	'prautoblogger_collect_metrics',
	'prautoblogger_reap_orphan_research_rows',
	'prautoblogger_sync_runware_models',
	'prautoblogger_manual_generation',
	'prautoblogger_generate_queued_article',
	'prautoblogger_generate_from_idea',
	'prautoblogger_opik_dispatch',
	'prautoblogger_rerun_stage_replay',
	'prautoblogger_rerun_from_stage',
	// v0.21.0 (M4): chained-cron checkpoint ticks.
	'prautoblogger_gen_orchestrate',
	'prautoblogger_gen_tick',
);

foreach ( $hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
