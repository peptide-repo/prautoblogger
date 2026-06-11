<?php
/**
 * PHPUnit bootstrap for PRAutoBlogger unit tests.
 *
 * Loads composer autoloader and defines WordPress constants.
 * Brain\Monkey setup is done in BaseTestCase, not here.
 *
 * @package PRAutoBlogger\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants that source files expect.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'PRAB_VERSION' ) ) {
    define( 'PRAB_VERSION', '1.0.0-test' );
}
if ( ! defined( 'PRAB_PLUGIN_DIR' ) ) {
    define( 'PRAB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Pipeline v2 Phase 1 defaults (source of truth: prautoblogger.php).
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD', 0.50 );
}
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS', 14 );
}
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS', 2048 );
}

// WordPress time constants used by CostTracker and ContentAnalyzer.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// Plugin constants used by OpenRouter provider retry logic.
if ( ! defined( 'PRAUTOBLOGGER_MAX_RETRIES' ) ) {
    define( 'PRAUTOBLOGGER_MAX_RETRIES', 3 );
}
if ( ! defined( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS' ) ) {
    define( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS', 30 );
}
if ( ! defined( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS' ) ) {
    // Zero in the test bootstrap so retry-path tests don't spend real wall
    // time in sleep(). Production default is 2 (see prautoblogger.php).
    define( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 0 );
}

// Default model slugs used by Publisher, ChiefEditor, Activator, settings UI.
// Source of truth: prautoblogger.php. Test-only duplicate so unit tests can
// load without the main plugin bootstrap running.
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL', 'google/gemini-2.5-flash-lite' );
}
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_WRITING_MODEL' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_WRITING_MODEL', 'google/gemini-2.5-flash-lite' );
}
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL', 'google/gemini-2.5-flash-lite' );
}

// Default image model slug used by OpenRouter/Runware pricing fallbacks and
// settings UI. Source of truth: prautoblogger.php line 63.
// Historically this was defined implicitly by CloudflareImageProviderTest::setUp()
// — that test was deleted in v0.10.0 (cloudflare-workers-ai-removal), so the
// define now lives here where it belongs.
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL', 'runware:100@1' );
}
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER', 'runware' );
}

// Default image style suffix used by legacy ImagePromptBuilder paths / settings UI.
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX' ) ) {
    define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX', 'Style: test infomercial style.' );
}

// Editorial style template (v0.16.0): default for prautoblogger_image_style_template.
// Must contain exactly one {{ topic_summary }} token (brief A5).
if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE' ) ) {
    define(
        'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE',
        "Editorial scientific illustration. Subject: {{ topic_summary }}. NO TEXT. NO PEOPLE. NO LOGOS. NO LABELS."
    );
}

// WordPress database constants used in $wpdb queries.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

/**
 * Minimal WP_Query stub for unit tests.
 *
 * IdeaScorer uses WP_Query to check for existing posts.
 * This stub returns empty results by default.
 */
if ( ! class_exists( 'WP_Query' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
    class WP_Query {
        /** @var array */
        public $posts = [];
        /** @var int */
        public $found_posts = 0;
        /** @var bool */
        public $have_posts = false;

        public function __construct( $args = [] ) {
            // No-op for testing — returns no posts.
        }

        public function have_posts(): bool {
            return $this->have_posts;
        }

        public function the_post(): void {
            // No-op.
        }
    }
}

// NOTE: Do NOT stub wp_strip_all_tags() or sanitize_text_field() here.
// Brain Monkey / Patchwork manages these stubs in individual tests via
// Functions\when(). Defining them in bootstrap causes Patchwork's
// "DefinedTooEarly" error.

// WP_Error stub — used by model registry and image pipeline tests.
// This is safe to define here because it's a class (not a function),
// so Patchwork doesn't need to intercept its definition.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors     = [];
        public $error_data = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( $code ) {
                $this->errors[ $code ][] = $message;
                if ( $data ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys( $this->errors );
            return $codes ? $codes[0] : '';
        }

        public function get_error_message( $code = '' ) {
            if ( ! $code ) {
                $code = $this->get_error_code();
            }
            return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
        }
    }
}
