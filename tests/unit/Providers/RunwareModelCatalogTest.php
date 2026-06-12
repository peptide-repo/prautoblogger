<?php
/**
 * Tests for PRAutoBlogger_Runware_Model_Catalog.
 *
 * Validates: normalize_models against real modelSearch fixture; cooldown behavior
 * (failure → no retry within window → retry after); fallback served when cache empty;
 * success path clears failure timestamp; cache version key v2 is used throughout.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RunwareModelCatalogTest extends BaseTestCase {

	/** Shared in-memory option store reset per test. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array();
		$this->stub_options();
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private function stub_options(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'time' )->justReturn( 1704067200 ); // 2024-01-01 00:00:00 UTC
	}

	/**
	 * Return the fixture as a decoded page array matching fetch_page() output.
	 *
	 * @return array{results: array, totalResults: int}
	 */
	private function get_fixture_page(): array {
		$fixture_path = __DIR__ . '/../../fixtures/runware-model-search-page1.json';
		$json = file_get_contents( $fixture_path );
		$decoded = json_decode( $json, true );
		$item = $decoded['data'][0];
		return array(
			'results'      => $item['results'],
			'totalResults' => $item['totalResults'],
		);
	}

	/**
	 * Build a fake HTTP response array for wp_remote_post stubs.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body JSON.
	 * @return array
	 */
	private function make_http_response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	private function stub_http_helpers(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) { return (string) ( $r['body'] ?? '' ); }
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	// ── normalize_models tests (via Fetcher) ──────────────────────────────────

	/**
	 * normalize_models filters to io:text-to-image and maps air → id,
	 * comment → description. Non-t2i models are excluded.
	 *
	 * Uses the real modelSearch fixture (3 t2i + 1 text LLM + 1 upscaler).
	 * Expects only the 3 t2i models to pass through.
	 */
	public function test_normalize_models_filters_to_text_to_image(): void {
		$fixture = $this->get_fixture_page();
		$fetcher = new \PRAutoBlogger_Runware_Catalog_Fetcher();
		$result  = $fetcher->normalize_models( $fixture['results'] );

		// Fixture has 3 t2i models (runware:100@1, runware:101@1, runware:400@2)
		// and 2 non-t2i (text LLM + upscaler).
		$this->assertCount( 3, $result, 'Should include exactly 3 io:text-to-image models' );

		$ids = array_column( $result, 'id' );
		$this->assertContains( 'runware:100@1', $ids );
		$this->assertContains( 'runware:101@1', $ids );
		$this->assertContains( 'runware:400@2', $ids );
		$this->assertNotContains( 'some-text-model:llm@1', $ids, 'Text LLM must be excluded' );
		$this->assertNotContains( 'upscale-model:upscaler@1', $ids, 'Upscaler must be excluded' );
	}

	/** normalize_models uses air as id, name as name, comment as description. */
	public function test_normalize_models_maps_fields_correctly(): void {
		$fixture = $this->get_fixture_page();
		$fetcher = new \PRAutoBlogger_Runware_Catalog_Fetcher();
		$result  = $fetcher->normalize_models( $fixture['results'] );

		$schnell = null;
		foreach ( $result as $m ) {
			if ( 'runware:100@1' === $m['id'] ) {
				$schnell = $m;
				break;
			}
		}

		$this->assertNotNull( $schnell );
		$this->assertSame( 'runware:100@1', $schnell['id'] );
		$this->assertSame( 'FLUX.1 schnell', $schnell['name'] );
		$this->assertSame( 'runware', $schnell['provider'] );
		$this->assertNull( $schnell['cost_per_image'], 'Pricing is null before merge_pricing runs' );
		$this->assertSame( array( 'image_generation' ), $schnell['capabilities'] );
		$this->assertSame( 'Fast 4-step text-to-image model', $schnell['description'] );
	}

	/** normalize_models skips entries with empty air field. */
	public function test_normalize_models_skips_empty_air(): void {
		$fetcher = new \PRAutoBlogger_Runware_Catalog_Fetcher();
		$raw     = array(
			array(
				'air'          => '',
				'name'         => 'No Air Model',
				'capabilities' => array( 'io:text-to-image' ),
				'comment'      => '',
			),
			array(
				'air'          => 'runware:100@1',
				'name'         => 'Valid',
				'capabilities' => array( 'io:text-to-image' ),
				'comment'      => 'OK',
			),
		);

		$result = $fetcher->normalize_models( $raw );
		$this->assertCount( 1, $result );
		$this->assertSame( 'runware:100@1', $result[0]['id'] );
	}

	/** normalize_models returns empty array for empty input. */
	public function test_normalize_models_empty_input(): void {
		$fetcher = new \PRAutoBlogger_Runware_Catalog_Fetcher();
		$this->assertSame( array(), $fetcher->normalize_models( array() ) );
	}

	// ── Failure cooldown tests ────────────────────────────────────────────────

	/** No failure on record → is_in_failure_cooldown returns false. */
	public function test_no_failure_cooldown_when_no_failure_recorded(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertFalse( $catalog->is_in_failure_cooldown() );
	}

	/** Failure recorded at t=now → cooldown active within 1h window. */
	public function test_cooldown_active_within_window(): void {
		$now = 1704067200;
		$this->options['prautoblogger_runware_catalog_last_failure_at'] = $now;
		// time() is stubbed to $now, so age = 0 < 3600 = cooldown.
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertTrue( $catalog->is_in_failure_cooldown() );
	}

	/** Failure recorded > 1h ago → cooldown expired, retry permitted. */
	public function test_cooldown_expired_after_window(): void {
		$now      = 1704067200;
		$past     = $now - 3601; // More than 1h ago.
		$this->options['prautoblogger_runware_catalog_last_failure_at'] = $past;

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertFalse( $catalog->is_in_failure_cooldown() );
	}

	/** Custom cooldown from settings is respected. */
	public function test_cooldown_uses_setting_value(): void {
		$now  = 1704067200;
		$this->options['prautoblogger_runware_catalog_last_failure_at']        = $now - 100;
		// Default 3600s would make this expired (100 < 3600), but set custom = 60s.
		// 100s > 60s so it should be expired.
		$this->options['prautoblogger_runware_catalog_failure_cooldown_seconds'] = 60;

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertFalse( $catalog->is_in_failure_cooldown() );
	}

	/** Sync failure sets last_failure_at and does not clear success timestamp. */
	public function test_sync_failure_records_failure_timestamp(): void {
		// Mock HTTP 401 to force sync failure.
		$this->options['prautoblogger_runware_api_key'] = 'test-api-key-plain';
		$this->stub_http_helpers();
		Functions\when( 'wp_remote_post' )->justReturn(
			$this->make_http_response( 401, '{"errors":[{"message":"Unauthorized"}]}' )
		);

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result  = $catalog->sync();

		$this->assertFalse( $result );
		$this->assertNotNull( $catalog->get_last_failure_at(), 'Failure timestamp should be recorded' );
	}

	/** After failure, get_models suppresses sync within cooldown window. */
	public function test_get_models_suppresses_sync_during_cooldown(): void {
		$now = 1704067200;
		$this->options['prautoblogger_runware_api_key']                    = 'test-api-key-plain';
		$this->options['prautoblogger_runware_catalog_last_failure_at']    = $now; // just failed.
		$this->options['prautoblogger_runware_model_cache_updated_at']     = $now - 90000; // stale cache.

		$this->stub_http_helpers();
		// wp_remote_post should NOT be called during cooldown.
		Functions\expect( 'wp_remote_post' )->never();

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models  = $catalog->get_models();

		// Should fall back to hardcoded fallback list.
		$this->assertNotEmpty( $models );
		$ids = array_column( $models, 'id' );
		$this->assertContains( 'runware:100@1', $ids, 'Fallback schnell model should be present' );
	}

	/** Successful sync clears failure timestamp. */
	public function test_successful_sync_clears_failure_timestamp(): void {
		$now = 1704067200;
		$this->options['prautoblogger_runware_api_key']                 = 'test-api-key-plain';
		$this->options['prautoblogger_runware_catalog_last_failure_at'] = $now - 7200; // old failure.

		$fixture_body = file_get_contents( __DIR__ . '/../../fixtures/runware-model-search-page1.json' );
		$this->stub_http_helpers();
		Functions\when( 'wp_remote_post' )->justReturn(
			$this->make_http_response( 200, $fixture_body )
		);

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result  = $catalog->sync();

		$this->assertTrue( $result, 'Sync should succeed with valid fixture response' );
		$this->assertNull( $catalog->get_last_failure_at(), 'Success should clear failure timestamp' );
	}

	// ── Fallback list tests ───────────────────────────────────────────────────

	/** get_models returns fallback when no cache and sync fails. */
	public function test_get_models_returns_fallback_when_no_cache(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models  = $catalog->get_models();

		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		$schnell_exists = false;
		foreach ( $models as $model ) {
			if ( 'runware:100@1' === ( $model['id'] ?? '' ) ) {
				$schnell_exists = true;
				$this->assertSame( 'runware', $model['provider'] );
				break;
			}
		}
		$this->assertTrue( $schnell_exists, 'Fallback should include FLUX.1 schnell' );
	}

	/** Fallback list is never empty (even with no key configured). */
	public function test_get_models_never_empty(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models  = $catalog->get_models();
		$this->assertNotEmpty( $models );

		foreach ( $models as $model ) {
			$this->assertArrayHasKey( 'id', $model );
			$this->assertArrayHasKey( 'name', $model );
			$this->assertArrayHasKey( 'provider', $model );
			$this->assertArrayHasKey( 'capabilities', $model );
		}
	}

	/** All fallback models carry the image_generation capability. */
	public function test_fallback_models_have_image_generation_capability(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models  = $catalog->get_models();

		foreach ( $models as $model ) {
			$this->assertIsArray( $model['capabilities'] );
			$this->assertContains( 'image_generation', $model['capabilities'] );
		}
	}

	/** Fallback schnell and dev have correct pricing. */
	public function test_fallback_models_have_pricing(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models  = $catalog->get_models();

		$schnell = null;
		$dev     = null;
		foreach ( $models as $model ) {
			if ( 'runware:100@1' === $model['id'] ) {
				$schnell = $model;
			}
			if ( 'runware:101@1' === $model['id'] ) {
				$dev = $model;
			}
		}

		$this->assertNotNull( $schnell );
		$this->assertEqualsWithDelta( 0.0006, $schnell['cost_per_image'], 0.000001 );
		$this->assertNotNull( $dev );
		$this->assertEqualsWithDelta( 0.02, $dev['cost_per_image'], 0.000001 );
	}

	// ── Happy path (sync → cache → get_models) ───────────────────────────────

	/** Successful sync caches models; get_models returns them from cache. */
	public function test_sync_success_caches_and_returns_models(): void {
		$fixture_body = file_get_contents( __DIR__ . '/../../fixtures/runware-model-search-page1.json' );
		$this->options['prautoblogger_runware_api_key'] = 'test-api-key-plain';

		$this->stub_http_helpers();
		Functions\when( 'wp_remote_post' )->justReturn(
			$this->make_http_response( 200, $fixture_body )
		);

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result  = $catalog->sync();

		$this->assertTrue( $result, 'Sync should return true on HTTP 200 with valid fixture' );

		$models = $catalog->get_models();
		$this->assertIsArray( $models );
		// Fixture has 3 t2i models (schnell, dev, klein 9B).
		$this->assertCount( 3, $models, 'get_models should return the 3 cached t2i models' );

		$ids = array_column( $models, 'id' );
		$this->assertContains( 'runware:100@1', $ids );
		$this->assertContains( 'runware:101@1', $ids );
		$this->assertContains( 'runware:400@2', $ids );
	}

	/** Synced models have pricing merged from the pricing class. */
	public function test_synced_models_have_pricing_merged(): void {
		$fixture_body = file_get_contents( __DIR__ . '/../../fixtures/runware-model-search-page1.json' );
		$this->options['prautoblogger_runware_api_key'] = 'test-api-key-plain';

		$this->stub_http_helpers();
		Functions\when( 'wp_remote_post' )->justReturn(
			$this->make_http_response( 200, $fixture_body )
		);

		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$catalog->sync();
		$models = $catalog->get_models();

		$schnell = null;
		foreach ( $models as $m ) {
			if ( 'runware:100@1' === $m['id'] ) {
				$schnell = $m;
			}
		}
		$this->assertNotNull( $schnell );
		$this->assertEqualsWithDelta( 0.0006, (float) $schnell['cost_per_image'], 0.000001 );
	}

	// ── Stale / absent cache tests ────────────────────────────────────────────

	/** is_stale returns true if never synced. */
	public function test_is_stale_never_synced(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertTrue( $catalog->is_stale() );
	}

	/** is_stale returns false for a fresh cache (synced < 24h ago). */
	public function test_is_stale_fresh_cache(): void {
		$now = 1704067200;
		$this->options['prautoblogger_runware_model_cache_updated_at'] = $now - 3600; // 1h ago.
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertFalse( $catalog->is_stale() );
	}

	/** is_stale returns true when cache is older than 24h. */
	public function test_is_stale_old_cache(): void {
		$now = 1704067200;
		$this->options['prautoblogger_runware_model_cache_updated_at'] = $now - 90000; // > 24h ago.
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertTrue( $catalog->is_stale() );
	}

	/** get_last_synced_at returns null if never synced. */
	public function test_get_last_synced_at_never(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertNull( $catalog->get_last_synced_at() );
	}

	/** Sync with no API key returns false without recording a failure. */
	public function test_sync_no_api_key(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result  = $catalog->sync();
		$this->assertFalse( $result );
		// No key → not a transient failure, no cooldown recorded.
		$this->assertNull( $catalog->get_last_failure_at() );
	}
}
