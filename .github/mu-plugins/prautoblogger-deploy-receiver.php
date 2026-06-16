<?php
/**
 * Plugin Name: PRAutoBlogger Deploy Receiver
 * Description: REST endpoint that accepts authenticated plugin zip uploads for automated deployment.
 * Version:     1.0.0
 * Author:      PRAutoBlogger CI/CD
 *
 * INSTALLATION:
 *   1. Upload this file to wp-content/mu-plugins/prautoblogger-deploy-receiver.php
 *   2. Add to wp-config.php (above "That's all, stop editing!"):
 *        define('PRAUTOBLOGGER_DEPLOY_KEY', 'your-random-64-char-token');
 *   3. Generate a key: php -r "echo bin2hex(random_bytes(32));"
 *
 * SECURITY:
 *   - Deploy key validated with hash_equals() (timing-safe comparison).
 *   - Only accepts zip files containing prautoblogger/prautoblogger.php.
 *   - Backs up current plugin before extraction; rolls back on failure.
 *   - Rate limited: max 1 deploy per 60 seconds.
 *   - All actions logged to PHP error_log for audit trail.
 *
 * @see .github/workflows/deploy.yml — GitHub Actions workflow that calls this.
 * @see scripts/deploy.sh             — Manual deploy script that calls this.
 */

declare(strict_types=1);

// Hard requirement: deploy key must be defined in wp-config.php.
if ( ! defined( 'PRAUTOBLOGGER_DEPLOY_KEY' ) || '' === PRAUTOBLOGGER_DEPLOY_KEY ) {
	return;
}

add_action( 'rest_api_init', 'prautoblogger_deploy_register_route' );

/**
 * Register the deploy REST route.
 */
function prautoblogger_deploy_register_route(): void {
	register_rest_route(
		'prautoblogger-deploy/v1',
		'/deploy',
		array(
			'methods'             => 'POST',
			'callback'            => 'prautoblogger_deploy_handle',
			'permission_callback' => 'prautoblogger_deploy_check_auth',
		)
	);

	// Health check endpoint (GET) — useful for monitoring.
	register_rest_route(
		'prautoblogger-deploy/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return array(
					'status'  => 'ready',
					'plugin'  => is_dir( WP_PLUGIN_DIR . '/prautoblogger' ) ? 'installed' : 'not_installed',
					'active'  => is_plugin_active( 'prautoblogger/prautoblogger.php' ),
					'version' => prautoblogger_deploy_get_installed_version(),
				);
			},
			'permission_callback' => 'prautoblogger_deploy_check_auth',
		)
	);
}

/**
 * Validate the deploy key from the request header.
 * Uses hash_equals() for timing-safe comparison.
 */
function prautoblogger_deploy_check_auth( WP_REST_Request $request ): bool {
	$key = $request->get_header( 'X-Deploy-Key' );
	if ( null === $key || '' === $key ) {
		return false;
	}
	return hash_equals( (string) PRAUTOBLOGGER_DEPLOY_KEY, $key );
}

/**
 * Handle a deploy request: receive zip, validate, extract, activate.
 */
function prautoblogger_deploy_handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	// Rate limit: 1 deploy per 60 seconds.
	$lock = get_transient( 'prautoblogger_deploy_lock' );
	if ( false !== $lock ) {
		return new WP_Error(
			'rate_limited',
			'Deploy in progress or recently completed. Wait 60 seconds.',
			array( 'status' => 429 )
		);
	}
	set_transient( 'prautoblogger_deploy_lock', time(), 60 );

	// 1. Validate uploaded file exists.
	$files = $request->get_file_params();
	if ( empty( $files['plugin']['tmp_name'] ) || ! is_uploaded_file( $files['plugin']['tmp_name'] ) ) {
		delete_transient( 'prautoblogger_deploy_lock' );
		return new WP_Error( 'no_file', 'No plugin zip uploaded.', array( 'status' => 400 ) );
	}

	$tmp_file = $files['plugin']['tmp_name'];

	// 2. Validate it's a real zip and contains our plugin.
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp_file ) ) {
		delete_transient( 'prautoblogger_deploy_lock' );
		return new WP_Error( 'invalid_zip', 'Uploaded file is not a valid zip.', array( 'status' => 400 ) );
	}

	$has_main_file = false;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( 'prautoblogger/prautoblogger.php' === $zip->getNameIndex( $i ) ) {
			$has_main_file = true;
			break;
		}
	}

	if ( ! $has_main_file ) {
		$zip->close();
		delete_transient( 'prautoblogger_deploy_lock' );
		return new WP_Error(
			'invalid_plugin',
			'Zip must contain prautoblogger/prautoblogger.php at root.',
			array( 'status' => 400 )
		);
	}

	// 3. Read version from the incoming zip before extraction.
	$incoming_main = $zip->getFromName( 'prautoblogger/prautoblogger.php' );
	$incoming_version = 'unknown';
	if ( $incoming_main && preg_match( '/Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $incoming_main, $m ) ) {
		$incoming_version = $m[1];
	}

	$plugin_dir = WP_PLUGIN_DIR . '/prautoblogger';
	$backup_dir = null;

	// 4. Deactivate plugin before file operations (prevent fatal errors).
	$was_active = is_plugin_active( 'prautoblogger/prautoblogger.php' );
	if ( $was_active ) {
		deactivate_plugins( 'prautoblogger/prautoblogger.php', true );
	}

	// 5. Backup current installation if it exists.
	if ( is_dir( $plugin_dir ) ) {
		$backup_dir = WP_PLUGIN_DIR . '/prautoblogger-backup-' . time();
		if ( ! rename( $plugin_dir, $backup_dir ) ) {
			$zip->close();
			// Re-activate if we deactivated.
			if ( $was_active ) {
				activate_plugin( 'prautoblogger/prautoblogger.php' );
			}
			delete_transient( 'prautoblogger_deploy_lock' );
			return new WP_Error( 'backup_failed', 'Could not backup current plugin.', array( 'status' => 500 ) );
		}
	}

	// 6. Extract the zip.
	$extract_ok = $zip->extractTo( WP_PLUGIN_DIR );
	$zip->close();

	if ( ! $extract_ok || ! file_exists( $plugin_dir . '/prautoblogger.php' ) ) {
		// Rollback: restore backup.
		if ( $backup_dir && is_dir( $backup_dir ) ) {
			rename( $backup_dir, $plugin_dir );
		}
		if ( $was_active ) {
			activate_plugin( 'prautoblogger/prautoblogger.php' );
		}
		delete_transient( 'prautoblogger_deploy_lock' );
		error_log( '[PRAutoBlogger Deploy] Extraction failed. Rolled back to previous version.' );
		return new WP_Error( 'extract_failed', 'Zip extraction failed. Rolled back.', array( 'status' => 500 ) );
	}

	// 7. Activate plugin.
	$activation_result = activate_plugin( 'prautoblogger/prautoblogger.php' );
	if ( is_wp_error( $activation_result ) ) {
		// Activation failed — rollback.
		prautoblogger_deploy_rmdir_recursive( $plugin_dir );
		if ( $backup_dir && is_dir( $backup_dir ) ) {
			rename( $backup_dir, $plugin_dir );
			activate_plugin( 'prautoblogger/prautoblogger.php' );
		}
		delete_transient( 'prautoblogger_deploy_lock' );
		error_log( '[PRAutoBlogger Deploy] Activation failed: ' . $activation_result->get_error_message() . '. Rolled back.' );
		return new WP_Error(
			'activation_failed',
			'Plugin activation failed: ' . $activation_result->get_error_message() . '. Rolled back.',
			array( 'status' => 500 )
		);
	}

	// 8. Clean up backup.
	if ( $backup_dir && is_dir( $backup_dir ) ) {
		prautoblogger_deploy_rmdir_recursive( $backup_dir );
	}

	$old_version = prautoblogger_deploy_get_installed_version();
	error_log(
		sprintf(
			'[PRAutoBlogger Deploy] Successfully deployed v%s (was: %s) at %s',
			$incoming_version,
			$old_version ? $old_version : 'fresh install',
			gmdate( 'Y-m-d H:i:s' )
		)
	);

	return new WP_REST_Response(
		array(
			'success' => true,
			'version' => $incoming_version,
			'message' => "PRAutoBlogger v{$incoming_version} deployed and activated.",
		),
		200
	);
}

/**
 * Get the currently installed plugin version from the main file header.
 */
function prautoblogger_deploy_get_installed_version(): string {
	$main_file = WP_PLUGIN_DIR . '/prautoblogger/prautoblogger.php';
	if ( ! file_exists( $main_file ) ) {
		return '';
	}
	$data = get_file_data( $main_file, array( 'Version' => 'Version' ) );
	return $data['Version'] ?? '';
}

/**
 * Recursively delete a directory.
 */
function prautoblogger_deploy_rmdir_recursive( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $dir );
}
