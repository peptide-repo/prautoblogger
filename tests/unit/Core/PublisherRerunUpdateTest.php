<?php
/**
 * Tests for the v0.20.0 Publisher refresh-unpublished behavior.
 *
 * A re-run's regenerated content must land on the existing UNPUBLISHED
 * post (idempotency previously skip-returned and silently discarded
 * re-run output); published posts remain frozen and untouched (CPO
 * guardrail 5 enforced at the data layer).
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PublisherRerunUpdateTest extends BaseTestCase {

	private \PRAutoBlogger_Article_Idea $idea;
	private \PRAutoBlogger_Editorial_Review $review;

	/**
	 * Args captured from wp_update_post().
	 *
	 * @var array|null
	 */
	private $updated_args = null;

	/**
	 * Meta captured from update_post_meta().
	 *
	 * @var array<string, mixed>
	 */
	private array $updated_meta = array();

	protected function setUp(): void {
		parent::setUp();

		$this->idea   = new \PRAutoBlogger_Article_Idea( $this->get_article_idea_fixture() );
		$this->review = new \PRAutoBlogger_Editorial_Review( $this->get_editorial_review_fixture() );

		$this->stub_get_option( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->updated_args = null;
		$this->updated_meta = array();
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) {
				$this->updated_args = (array) $args;
				return $args['ID'] ?? 0;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->updated_meta[ (string) $key ] = $value;
				return true;
			}
		);

		// find_existing_post() resolves post 55 for this (run, idea).
		$wpdb = $this->create_mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( 'prepared' );
		$wpdb->method( 'get_var' )->willReturn( '55' );
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Stub get_post for post 55 with a status. */
	private function stub_existing_post( string $status ): void {
		$post              = new \WP_Post();
		$post->ID          = 55;
		$post->post_status = $status;
		Functions\when( 'get_post' )->justReturn( $post );
	}

	/**
	 * Re-run output refreshes the existing draft: content + title are
	 * updated, verdict meta refreshed — and the post status is NEVER
	 * touched (a re-run must not publish; publication stays an explicit
	 * operator action).
	 */
	public function test_rerun_refreshes_existing_draft_without_status_change(): void {
		$this->stub_existing_post( 'draft' );
		Functions\when( 'wp_insert_post' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_insert_post must not be called for an existing post' );
			}
		);

		$publisher = new \PRAutoBlogger_Publisher();
		$post_id   = $publisher->save_as_draft( '<p>Regenerated content</p>', $this->idea, $this->review, 'run-1' );

		$this->assertSame( 55, $post_id );
		$this->assertIsArray( $this->updated_args );
		$this->assertSame( 55, $this->updated_args['ID'] );
		$this->assertStringContainsString( 'Regenerated content', (string) $this->updated_args['post_content'] );
		$this->assertArrayNotHasKey( 'post_status', $this->updated_args );
		$this->assertSame( $this->review->get_verdict(), $this->updated_meta['_prautoblogger_editor_verdict'] );
		$this->assertArrayHasKey( '_prautoblogger_generated_at', $this->updated_meta );
	}

	/**
	 * Published post: frozen — returned untouched, no wp_update_post, no
	 * meta writes, no duplicate insert (guardrail 5 at the data layer).
	 */
	public function test_published_post_is_never_updated(): void {
		$this->stub_existing_post( 'publish' );
		Functions\when( 'wp_insert_post' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_insert_post must not be called for an existing post' );
			}
		);

		$publisher = new \PRAutoBlogger_Publisher();
		$post_id   = $publisher->save_as_draft( '<p>Regenerated content</p>', $this->idea, $this->review, 'run-1' );

		$this->assertSame( 55, $post_id );
		$this->assertNull( $this->updated_args );
		$this->assertSame( array(), $this->updated_meta );
	}
}
