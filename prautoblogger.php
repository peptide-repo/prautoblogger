<?php

/**
 * phpcs:ignore Generic.PHP.RequireStrictTypes.MissingDeclaration -- strict_types must precede docblock
 *
 * PRAutoBlogger — Automated Content Research & Publishing
 *
 * @wordpress-plugin
 * Plugin Name:       PRAutoBlogger
 * Plugin URI:        https://peptiderepo.com/prautoblogger
 * Description:       Monitors social media for trending topics, generates SEO-friendly blog posts using AI, and publishes them on a daily schedule with full cost tracking and self-improvement metrics.
 * Version:           0.20.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PeptideRepo
 * Author URI:        https://peptiderepo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prautoblogger
 * Domain Path:       /languages
 *
 * @see ARCHITECTURE.md — Full data flow and file tree
 * @see CONVENTIONS.md  — Naming patterns and extension guides
 */
declare(strict_types=1);

// Abort if called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/*
|--------------------------------------------------------------------------
| Plugin Constants
|--------------------------------------------------------------------------
| Defined here so every file in the plugin can reference paths, versions,
| and limits without magic strings.
*/
define( 'PRAUTOBLOGGER_VERSION', '0.20.0' );
define( 'PRAUTOBLOGGER_DB_VERSION', '1.3.0' );
define( 'PRAUTOBLOGGER_PLUGIN_FILE', __FILE__ );
define( 'PRAUTOBLOGGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRAUTOBLOGGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRAUTOBLOGGER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// API and retry limits — named constants instead of magic numbers.
define( 'PRAUTOBLOGGER_MAX_RETRIES', 3 );
define( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 2 );
define( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS', 180 );
define( 'PRAUTOBLOGGER_CACHE_TTL_SECONDS', 3600 );
// Default models for OpenRouter (user can override in settings).
define( 'PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL', 'google/gemini-2.5-flash-lite' );
define( 'PRAUTOBLOGGER_DEFAULT_WRITING_MODEL', 'google/gemini-2.5-flash-lite' );
define( 'PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL', 'google/gemini-2.5-flash-lite' );
// Image generation defaults.
// Default provider is Runware; OpenRouter (Gemini) remains as the alternate.
// Default model is FLUX.1 schnell via Runware (~$0.0006/image, ~2s). CEO
// decision 2026-04-21 after comic A/B: schnell's looser fidelity reads as
// editorial-cartoon style and is 65× cheaper than Nano Banana. Users can
// override via Settings → Images → Image Model.
define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER', 'runware' );
define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL', 'runware:100@1' );
// Default style suffix appended to every image-gen prompt. Updated
// 2026-04-18 v4: single-panel newspaper comic style. Caption is now
// inserted as HTML text below the image, NOT rendered inside it.
// Users may override via Settings → Images → Style Suffix.
define(
	'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX',
	'Style: a single-panel newspaper comic in classic black ink on white paper, simple clean linework like The Far Side or B.C., slightly yellowed newsprint texture, hand-drawn crosshatching for shading, bold panel border, warm and humorous tone, visible in a newspaper comics section. IMPORTANT: Do not render any text, captions, speech bubbles, or words inside the image.'
);
// Default editorial-illustration prompt template for the image generator.
// Updated 2026-05-29 (v0.16.0): pivot from single-panel comic to text-free
// editorial scientific illustration. The {{ topic_summary }} token is filled
// at build time with a 1-2 sentence topic/mechanism description from the
// rewriter LLM; the rest is brand-locked. Replaces the old Style Suffix.
// Users may override via Settings -> Images -> Style Template. Per brief A5
// the template must contain exactly one {{ topic_summary }} token.
define(
	'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE',
	"Editorial scientific illustration, flat vector aesthetic, limited palette of\n" .
	"teal (#1B8A92) and cream (#FAF6EE) with ink (#0D0D0D) line work and accents\n" .
	"of orange (#FF8A3D) and lime (#7FD600). Subject: {{ topic_summary }}.\n" .
	"Style reference: Quanta Magazine, The Pudding, scientific editorial illustration.\n" .
	"Composition: clear focal point, balanced negative space, subtle textural detail.\n" .
	'Mood: authoritative, clinical, calm. NO TEXT. NO PEOPLE. NO LOGOS. NO LABELS.'
);
// Opik observability (feature-flag gated).
// API credentials must be defined in wp-config.php constants:
// - PRAUTOBLOGGER_OPIK_API_KEY: API key for authentication
// - PRAUTOBLOGGER_OPIK_WORKSPACE: Opik workspace name (e.g. 'peptiderepo')
// - PRAUTOBLOGGER_OPIK_URL_OVERRIDE: Optional; defaults to https://www.comet.com/opik/api/
if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) ) {
	define( 'PRAUTOBLOGGER_OPIK_API_KEY', '' );
}
if ( ! defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) ) {
	define( 'PRAUTOBLOGGER_OPIK_WORKSPACE', 'peptiderepo' );
}
if ( ! defined( 'PRAUTOBLOGGER_OPIK_URL_OVERRIDE' ) ) {
	define( 'PRAUTOBLOGGER_OPIK_URL_OVERRIDE', 'https://www.comet.com/opik/api/' );
}

/*
|--------------------------------------------------------------------------
| Autoloader
|--------------------------------------------------------------------------
*/
require_once PRAUTOBLOGGER_PLUGIN_DIR . 'includes/class-autoloader.php';
PRAutoBlogger_Autoloader::register();
// The main orchestrator class is loaded explicitly because its CamelCase name
// (PRAutoBlogger) doesn't map cleanly to a filename via the autoloader's
// kebab-case convention (it would look for class-pr-auto-blogger.php).
require_once PRAUTOBLOGGER_PLUGIN_DIR . 'includes/class-db-migrations.php'; // Loaded explicitly: DB_Migrations -> class-d-b-migrations.php via autoloader
require_once PRAUTOBLOGGER_PLUGIN_DIR . 'includes/class-prautoblogger.php';
/*
|--------------------------------------------------------------------------
| Activation & Deactivation
|--------------------------------------------------------------------------
*/
register_activation_hook( __FILE__, array( 'PRAutoBlogger_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PRAutoBlogger_Deactivator', 'deactivate' ) );
/*
|--------------------------------------------------------------------------
| Boot the Plugin
|--------------------------------------------------------------------------
| The main orchestrator registers all hooks. Nothing else happens here —
| keep the bootstrap file minimal.
*/
/**
 * Returns the singleton instance of the main plugin class.
 *
 * @return PRAutoBlogger
 */
function prautoblogger(): PRAutoBlogger {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new PRAutoBlogger();
	}
	return $instance;
}
// Initialize on plugins_loaded so all dependencies are available.
add_action(
	'plugins_loaded',
	static function (): void {
		prautoblogger()->run();
	}
);
