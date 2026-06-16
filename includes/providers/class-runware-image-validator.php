<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Credential validator for the Runware image provider.
 *
 * Extracted from PRAutoBlogger_Runware_Image_Provider for 300-line compliance.
 * Sends a minimal authentication task to Runware's API to confirm the key is valid.
 *
 * What: Static utility; no state.
 * Who triggers: PRAutoBlogger_Runware_Image_Provider::validate_credentials_detailed().
 * Dependencies: PRAutoBlogger_Runware_Image_Support (API_URL constant, get_api_key()).
 *
 * @see providers/class-runware-image-provider.php -- Sole caller.
 * @see providers/class-runware-image-support.php  -- API key retrieval + URL.
 */
class PRAutoBlogger_Runware_Validator {

	/**
	 * {@inheritDoc}
	 *
	 * Runware has no dedicated "validate key" endpoint, so we send a minimal
	 * authentication task and look for `taskType=authentication` in the
	 * response data with no accompanying errors.
	 */
	public static function validate( PRAutoBlogger_Runware_Image_Support $support ): array {
		try {
			$api_key = $support->get_api_key();
		} catch ( \RuntimeException $e ) {
			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
		}

		$body = array(
			array(
				'taskType' => 'authentication',
				'apiKey'   => $api_key,
			),
		);

		$response = wp_remote_post(
			PRAutoBlogger_Runware_Image_Support::API_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Could not reach Runware API.', 'prautoblogger' ),
				'debug'   => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return array(
				'status'  => 'error',
				'message' => sprintf( __( 'Runware returned HTTP %d.', 'prautoblogger' ), $status ),
				'debug'   => substr( $raw, 0, 200 ),
			);
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
			$first = $decoded['errors'][0] ?? array();
			$msg   = is_array( $first ) ? (string) ( $first['message'] ?? '' ) : (string) $first;
			return array(
				'status'  => 'error',
				'message' => __( 'Runware rejected the API key.', 'prautoblogger' ),
				'debug'   => substr( $msg, 0, 200 ),
			);
		}

		return array(
			'status'  => 'ok',
			'message' => __( 'Runware API key valid.', 'prautoblogger' ),
		);
	}

}
