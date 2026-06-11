<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * One-shot v0.18.0 migration — seed the versioned prompt registry.
 *
 * What: Writes the canonical hardcoded prompt texts (content, analysis,
 *       editor, research, illustration rewriter, image Style Template)
 *       into `wp_prautoblogger_prompts` as immutable version 1 and
 *       activates them. Idempotent twice over: gated by the
 *       `prautoblogger_migrated_prompt_seed_v0180` flag AND seed_v1()
 *       itself skips any key that already has rows. The flag is only set
 *       when the table was actually reachable, so a failed seed
 *       self-heals on the next activation / version-mismatch pass.
 *       Standalone class per the Migrate_Remove_Cloudflare_V0100
 *       precedent (keeps Activator under the 300-line cap).
 * Who triggers it: PRAutoBlogger_Activator::activate() — on activation and
 *       on every db-version mismatch.
 * Dependencies: PRAutoBlogger_Prompt_Registry(_Writer), get_option.
 *
 * @see class-activator.php                       — Sole caller.
 * @see core/class-prompt-registry-writer.php     — seed_v1() implementation.
 * @see includes/class-migrate-remove-cloudflare-v0100.php — Pattern precedent.
 */
class PRAutoBlogger_Migrate_Prompt_Seed_V0180 {

	/** Option flag marking the seed as complete. */
	private const FLAG = 'prautoblogger_migrated_prompt_seed_v0180';

	/**
	 * Run the seed once.
	 *
	 * Side effects: up to 12 INSERTs into the prompts table, one INFO log
	 * line, one option write.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( get_option( self::FLAG ) ) {
			return;
		}
		if ( ! PRAutoBlogger_Prompt_Registry::is_available() ) {
			return; // Table missing — retry on the next migration pass.
		}

		$seeded = PRAutoBlogger_Prompt_Registry_Writer::seed_v1( 'seed:v0.18.0' );

		if ( class_exists( 'PRAutoBlogger_Logger', false ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Prompt registry seeded: %d key(s) written as v1.', $seeded ),
				'activator'
			);
		}

		update_option( self::FLAG, '1' );
	}
}
