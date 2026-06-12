<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * One-time and version-gated DB/option migrations for PRAutoBlogger.
 *
 * Extracted from PRAutoBlogger (class-prautoblogger.php) in v0.19.2 so the
 * main orchestrator stays under the 300-line cap. All migration logic lives
 * here; the orchestrator holds thin proxy methods for backward compat.
 *
 * Each migration is idempotent: guarded by a stored option flag so it never
 * runs more than once per site.
 *
 * Triggered by: PRAutoBlogger::on_check_db_version() (admin_init)
 *               PRAutoBlogger::on_check_db_version_for_cron() (init, cron-only).
 * Dependencies: PRAutoBlogger_Activator, PRAutoBlogger_Image_Model_Registry,
 *               PRAutoBlogger_Encryption, PRAutoBlogger_Migrate_Remove_Cloudflare_V0100.
 *
 * @see class-prautoblogger.php   -- Orchestrator; holds proxy methods.
 * @see class-activator.php       -- Schema install / activate logic called here.
 * @see ARCHITECTURE.md           -- DB version history and migration discipline.
 */
class PRAutoBlogger_DB_Migrations {

	/**
	 * Run all pending migrations.
	 *
	 * Called on `admin_init` (and on `init` for cron contexts). Idempotent.
	 *
	 * Side effects: may update database schema and wp_options rows.
	 *
	 * @return void
	 */
	public static function run(): void {
		$stored_version = get_option( 'prautoblogger_db_version', '0' );
		if ( version_compare( $stored_version, PRAUTOBLOGGER_DB_VERSION, '<' ) ) {
			PRAutoBlogger_Activator::activate();
		}

		// One-time migration: switch to Gemini 2.5 Flash Lite for cost/speed.
		if ( ! get_option( 'prautoblogger_migrated_gemini_flash_lite' ) ) {
			update_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
			update_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
			update_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
			update_option( 'prautoblogger_migrated_gemini_flash_lite', '1' );
		}

		// One-time migration (v0.8.0): the admin no longer has an independent
		// Image Provider dropdown; provider is derived from the image model on
		// save. Auto-heal any existing site where the saved provider doesn't
		// match the saved model's provider. Runs once.
		if ( ! get_option( 'prautoblogger_migrated_image_provider_v080' ) ) {
			$saved_model = (string) get_option( 'prautoblogger_image_model', '' );
			$provider    = PRAutoBlogger_Image_Model_Registry::provider_for( $saved_model );
			if ( '' !== $provider ) {
				update_option( 'prautoblogger_image_provider', $provider );
			}
			update_option( 'prautoblogger_migrated_image_provider_v080', '1' );
		}

		// One-time migration (v0.8.2): reschedule the daily-generation cron in
		// the site's configured timezone. Pre-v0.8.2 activator interpreted the
		// admin "Generation Time" input as UTC; after v0.8.2 it honours the
		// site timezone. Clears the stale UTC-scheduled event and re-queues
		// using the timezone-aware helper. See class-activator.php.
		PRAutoBlogger_Activator::reschedule_daily_in_site_timezone_v082();

		// One-time migration (v3): switch to single-panel newspaper comic style.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v3' ) ) {
			$known_old_prefixes = array(
				'Style: a screengrab from a 1995',       // v1: infomercial.
				'Style: premium scientific lifestyle',    // v2: photography.
			);
			$current            = get_option( 'prautoblogger_image_style_suffix', '' );
			$is_old             = ( '' === $current );
			foreach ( $known_old_prefixes as $prefix ) {
				if ( false !== strpos( $current, $prefix ) ) {
					$is_old = true;
					break;
				}
			}
			if ( $is_old ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v3', '1' );
		}

		// One-time migration (v4): remove caption-in-image instruction from style suffix.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v4' ) ) {
			$current = get_option( 'prautoblogger_image_style_suffix', '' );
			if ( false !== strpos( $current, 'caption text in a clean sans-serif font below the panel' ) ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v4', '1' );
		}

		// One-time migration (v0.16.0): editorial pivot -- comic Style Suffix
		// replaced by editorial Style Template. Old value mirrored to a
		// deprecated-keyed option for one version cycle.
		if ( ! get_option( 'prautoblogger_migrated_style_template_v0160' ) ) {
			$old_suffix = get_option( 'prautoblogger_image_style_suffix', '' );
			if ( '' !== $old_suffix && false === get_option( 'prautoblogger_image_style_suffix_deprecated', false ) ) {
				update_option( 'prautoblogger_image_style_suffix_deprecated', $old_suffix );
			}
			if ( '' === (string) get_option( 'prautoblogger_image_style_template', '' ) ) {
				update_option( 'prautoblogger_image_style_template', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE );
			}
			update_option( 'prautoblogger_migrated_style_template_v0160', '1' );
		}

		// v0.9.0 -- Runware as default image model. v0.10.0 -- remove CF Workers AI.
		PRAutoBlogger_Activator::migrate_default_image_v090();
		PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		// One-time migration: re-wrap existing encrypted values with "enc:" prefix.
		if ( ! get_option( 'prautoblogger_migrated_enc_prefix' ) ) {
			$enc_options = array( 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json', 'prautoblogger_runware_api_key' );
			foreach ( $enc_options as $opt ) {
				$val = get_option( $opt, '' );
				if ( '' !== $val && ! PRAutoBlogger_Encryption::is_encrypted( $val ) ) {
					delete_option( $opt );
				}
			}
			update_option( 'prautoblogger_migrated_enc_prefix', '1' );
		}
	}
}
