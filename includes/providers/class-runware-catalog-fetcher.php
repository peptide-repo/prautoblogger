<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Runware modelSearch API fetcher — handles pagination and response normalization
 * for the Runware model catalog sync.
 *
 * Extracted from PRAutoBlogger_Runware_Model_Catalog for the 300-line rule.
 * Provides two public methods consumed by the catalog class:
 *   - fetch_all_text_to_image(): paginates modelSearch and returns raw results.
 *   - normalize_models(): converts raw results to Image_Model_Registry shape.
 *
 * API note (2026-06-12): Runware retired taskType:models. The replacement is
 * taskType:modelSearch — requires auth+modelSearch tasks, returns paginated
 * results with `air` identifiers and `capabilities[]`. Text-to-image filtering
 * is client-side (no server-side capability filter is available). The catalog
 * has 320k+ entries (mostly community checkpoints); we cap at PAGE_CAP pages
 * of PAGE_SIZE results to bound request cost while covering curated t2i models.
 *
 * @see class-runware-model-catalog.php  — Caller; owns caching, cooldown, and fallback.
 * @see class-image-model-registry.php  — Consumes the normalized model shape.
 */
class PRAutoBlogger_Runware_Catalog_Fetcher {

	private const RUNWARE_API_URL  = 'https://api.runware.ai/v1';
	private const HTTP_TIMEOUT_SEC = 30;

	/**
	 * Maximum pages to fetch per sync cycle.
	 * 5 pages × 100 results = 500 models inspected.
	 * Curated Runware-native text-to-image models number ~50 in the first 500.
	 */
	private const PAGE_CAP  = 5;
	private const PAGE_SIZE = 100;

	/**
	 * Fetch all text-to-image model results via paginated modelSearch calls.
	 *
	 * Issues up to PAGE_CAP requests against the Runware /v1 endpoint, each
	 * sending an auth task + a modelSearch task with offset/limit. Stops early
	 * when all available results have been fetched. Returns the raw result
	 * objects (unfiltered — normalize_models() applies the capability filter).
	 *
	 * @param string $api_key Decrypted Runware API key.
	 * @return array<int, array<string, mixed>> Raw modelSearch result objects.
	 * @throws \RuntimeException On HTTP error or malformed response for any page.
	 */
	public function fetch_all_text_to_image( string $api_key ): array {
		$all_results   = array();
		$offset        = 0;
		$pages_fetched = 0;
		$total         = PHP_INT_MAX; // Set after first page.

		do {
			$page = $this->fetch_page( $api_key, $offset, self::PAGE_SIZE );
			if ( empty( $page['results'] ) ) {
				break;
			}

			$all_results   = array_merge( $all_results, $page['results'] );
			$total         = (int) $page['totalResults'];
			$offset       += self::PAGE_SIZE;
			++$pages_fetched;

			if ( count( $all_results ) >= $total ) {
				break;
			}
		} while ( $pages_fetched < self::PAGE_CAP );

		if ( $pages_fetched >= self::PAGE_CAP && count( $all_results ) < $total ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf(
					'Runware catalog: reached page cap (%d pages, %d models fetched of %d total). '
					. 'Increase PAGE_CAP if curated models are being missed.',
					self::PAGE_CAP,
					count( $all_results ),
					$total
				),
				'runware-catalog'
			);
		}

		return $all_results;
	}

	/**
	 * Normalize raw modelSearch result objects to the Image_Model_Registry shape.
	 *
	 * Filters to models with "io:text-to-image" in capabilities[].
	 * Uses the `air` field as the model id (e.g. "runware:100@1").
	 * Uses the `comment` field as the human-readable description.
	 * cost_per_image is always null here — merged by the catalog class.
	 *
	 * @param array<int, array<string, mixed>> $raw_models Raw modelSearch results.
	 * @return array<int, array<string, mixed>> Normalized models.
	 */
	public function normalize_models( array $raw_models ): array {
		$normalized = array();

		foreach ( $raw_models as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$capabilities = is_array( $raw['capabilities'] ?? null ) ? $raw['capabilities'] : array();
			if ( ! in_array( 'io:text-to-image', $capabilities, true ) ) {
				continue;
			}

			$air = (string) ( $raw['air'] ?? '' );
			if ( '' === $air ) {
				continue;
			}

			$normalized[] = array(
				'id'             => $air,
				'name'           => (string) ( $raw['name'] ?? $air ),
				'provider'       => 'runware',
				'cost_per_image' => null,
				'capabilities'   => array( 'image_generation' ),
				'description'    => (string) ( $raw['comment'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Fetch a single page of modelSearch results.
	 *
	 * Sends [authentication, modelSearch] task pair to /v1.
	 * A fresh auth task is required per request per Runware's WebSocket-based
	 * API contract (each HTTP POST is a stateless task array).
	 *
	 * @param string $api_key Decrypted Runware API key.
	 * @param int    $offset  Pagination offset (0-based).
	 * @param int    $limit   Page size.
	 * @return array{results: array<int, array<string, mixed>>, totalResults: int}
	 * @throws \RuntimeException On HTTP error or malformed response (non-200 exceptions
	 *               include up to 200 chars of the response body for diagnosis).
	 */
	private function fetch_page( string $api_key, int $offset, int $limit ): array {
		$payload = array(
			array(
				'taskType' => 'authentication',
				'apiKey'   => $api_key,
			),
			array(
				'taskType' => 'modelSearch',
				'taskUUID' => wp_generate_uuid4(),
				'offset'   => $offset,
				'limit'    => $limit,
			),
		);

		$response = wp_remote_post(
			self::RUNWARE_API_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT_SEC,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf( 'Runware modelSearch unreachable: %s', $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$raw_body   = (string) wp_remote_retrieve_body( $response );
			$body_excerpt = '' !== $raw_body ? ' — body: ' . substr( $raw_body, 0, 200 ) : '';
			throw new \RuntimeException(
				sprintf( 'Runware modelSearch returned HTTP %d%s', $status, $body_excerpt )
			);
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Runware modelSearch response is not valid JSON.' );
		}

		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
			$first_msg = '';
			if ( ! empty( $decoded['errors'][0]['message'] ) ) {
				$first_msg = ': ' . (string) $decoded['errors'][0]['message'];
			}
			throw new \RuntimeException( 'Runware modelSearch API error' . $first_msg );
		}

		$data = $decoded['data'] ?? array();
		if ( ! is_array( $data ) || empty( $data ) ) {
			return array(
				'results' => array(),
				'totalResults' => 0,
			);
		}

		$item    = $data[0];
		$results = is_array( $item['results'] ?? null ) ? $item['results'] : array();
		$total   = isset( $item['totalResults'] ) ? (int) $item['totalResults'] : 0;

		return array(
			'results' => $results,
			'totalResults' => $total,
		);
	}
}
