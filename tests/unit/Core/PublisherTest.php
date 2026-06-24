<?php
/**
 * Tests for PRAutoBlogger_Publisher.
 *
 * Validates WordPress post creation via publish() and save_as_draft() methods,
 * including correct post_status, metadata storage, taxonomy assignment, and
 * generation log linking.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PublisherTest extends BaseTestCase {

    private \PRAutoBlogger_Article_Idea $idea;
    private \PRAutoBlogger_Editorial_Review $review;

    protected function setUp(): void {
        parent::setUp();

        $this->idea   = new \PRAutoBlogger_Article_Idea( $this->get_article_idea_fixture() );
        $this->review = new \PRAutoBlogger_Editorial_Review( $this->get_editorial_review_fixture() );

        // Common stubs needed by Publisher.
        $this->stub_get_option( [
            'prautoblogger_writing_pipeline'  => 'multi_step',
            'prautoblogger_writing_model'     => 'anthropic/claude-sonnet-4',
            'prautoblogger_default_author'    => 1,
        ] );

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        // v0.18.1 empty-content guard strips tags before the emptiness check.
        Functions\when( 'wp_strip_all_tags' )->alias( function ( $text ) {
            return trim( strip_tags( (string) $text ) );
        } );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'absint' )->alias( function ( $val ) { return abs( (int) $val ); } );
        Functions\when( 'get_users' )->justReturn( [ 1 ] );
        Functions\when( 'get_term_by' )->justReturn( false );
        Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 5 ] );
        Functions\when( 'wp_set_post_categories' )->justReturn( true );
        Functions\when( 'wp_set_post_tags' )->justReturn( true );
        // P2-2: get_post_meta is called by Post_Assembler::attach_generated_images()
        // to check the imagery-suppression flag. Return '' so imagery is not skipped
        // in Publisher-level tests (image pipeline errors are caught downstream).
        Functions\when( 'get_post_meta' )->justReturn( '' );
    }

    /**
     * Test publish() creates a post with 'publish' status and returns post ID.
     */
    public function test_publish_returns_post_id(): void {
        $captured_args = null;
        Functions\when( 'wp_insert_post' )->alias( function ( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return 42;
        } );

        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'query' )->willReturn( 0 );
        $wpdb->method( 'prepare' )->willReturn( '' );
        $GLOBALS['wpdb'] = $wpdb;

        $publisher = new \PRAutoBlogger_Publisher();
        $post_id   = $publisher->publish( '<p>Test content</p>', $this->idea, $this->review, 'run_test_123' );

        $this->assertSame( 42, $post_id );
        $this->assertSame( 'publish', $captured_args['post_status'] );
        $this->assertSame( 'post', $captured_args['post_type'] );
    }

    /**
     * Test save_as_draft() creates a post with 'draft' status.
     */
    public function test_save_as_draft_creates_draft(): void {
        $captured_args = null;
        Functions\when( 'wp_insert_post' )->alias( function ( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return 43;
        } );

        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'query' )->willReturn( 0 );
        $wpdb->method( 'prepare' )->willReturn( '' );
        $GLOBALS['wpdb'] = $wpdb;

        $publisher = new \PRAutoBlogger_Publisher();
        $post_id   = $publisher->save_as_draft( '<p>Draft content</p>', $this->idea, $this->review );

        $this->assertSame( 43, $post_id );
        $this->assertSame( 'draft', $captured_args['post_status'] );
    }

    /**
     * Test that generation metadata is stored in meta_input.
     */
    public function test_publish_stores_generation_metadata(): void {
        $captured_args = null;
        Functions\when( 'wp_insert_post' )->alias( function ( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return 44;
        } );

        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'query' )->willReturn( 0 );
        $wpdb->method( 'prepare' )->willReturn( '' );
        $GLOBALS['wpdb'] = $wpdb;

        $publisher = new \PRAutoBlogger_Publisher();
        $publisher->publish( '<p>Content</p>', $this->idea, $this->review, 'run_meta_test' );

        $meta = $captured_args['meta_input'];

        // Verify all expected meta keys exist.
        $this->assertSame( '1', $meta['_prautoblogger_generated'] );
        $this->assertSame( 'approved', $meta['_prautoblogger_editor_verdict'] );
        $this->assertSame( 0.88, $meta['_prautoblogger_quality_score'] );
        $this->assertSame( 0.92, $meta['_prautoblogger_seo_score'] );
        $this->assertSame( 'anthropic/claude-sonnet-4', $meta['_prautoblogger_model_used'] );
        $this->assertSame( 'multi_step', $meta['_prautoblogger_pipeline_mode'] );
        $this->assertSame( 'Test Article Topic', $meta['_prautoblogger_topic'] );
        $this->assertSame( 'guide', $meta['_prautoblogger_article_type'] );
    }

    /**
     * Test that post title comes from the idea's suggested_title.
     */
    public function test_publish_uses_idea_title(): void {
        $captured_args = null;
        Functions\when( 'wp_insert_post' )->alias( function ( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return 45;
        } );

        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'query' )->willReturn( 0 );
        $wpdb->method( 'prepare' )->willReturn( '' );
        $GLOBALS['wpdb'] = $wpdb;

        $publisher = new \PRAutoBlogger_Publisher();
        $publisher->publish( '<p>Content</p>', $this->idea, $this->review );

        $this->assertSame( 'Ultimate Guide to Test Topic', $captured_args['post_title'] );
    }

    /**
     * Test that publish throws RuntimeException when wp_insert_post returns WP_Error.
     */
    public function test_publish_throws_on_wp_error(): void {
        Functions\when( 'wp_insert_post' )->justReturn( new \stdClass() );
        Functions\when( 'is_wp_error' )->justReturn( true );

        $wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Mock the WP_Error-like object to have get_error_message.
        $error = $this->getMockBuilder( \stdClass::class )
            ->addMethods( [ 'get_error_message' ] )
            ->getMock();
        $error->method( 'get_error_message' )->willReturn( 'DB insert failed' );

        Functions\when( 'wp_insert_post' )->justReturn( $error );

        $this->expectException( \RuntimeException::class );

        $publisher = new \PRAutoBlogger_Publisher();
        $publisher->publish( '<p>Content</p>', $this->idea, $this->review );
    }

    /**
     * v0.18.1 belt-and-braces: save_as_draft() with empty content throws
     * and never reaches wp_insert_post — no empty draft post can be
     * created (the 2026-06-11 post-921 incident shape).
     */
    public function test_save_as_draft_refuses_empty_content(): void {
        Functions\expect( 'wp_insert_post' )->never();

        $publisher = new \PRAutoBlogger_Publisher();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'generated content is empty' );

        $publisher->save_as_draft( '', $this->idea, $this->review, 'run_empty_draft' );
    }

    /**
     * v0.18.1 belt-and-braces: publish() refuses content that is empty
     * once tags and whitespace are stripped (an empty HTML shell).
     */
    public function test_publish_refuses_whitespace_only_html_content(): void {
        Fu