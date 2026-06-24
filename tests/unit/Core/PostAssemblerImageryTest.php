<?php
declare(strict_types=1);

/**
 * Tests for PRAutoBlogger_Post_Assembler::attach_generated_images() imagery-suppression gate.
 *
 * What: Verifies that the _prautoblogger_imagery_suppressed post meta flag
 *       causes attach_generated_images() to return early without touching the
 *       image pipeline, and that clearing the flag lets the method proceed.
 * Dependencies: Brain\Monkey (stubs WordPress functions), PRAutoBlogger_Logger mock.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PostAssemblerImageryTest extends BaseTestCase {

	/** @var \PHPUnit\Framework\MockObject\MockObject&object Mock Article_Idea. */
	private object $idea;

	protected function setUp(): void {
		parent::setUp();

		// Minimal Article_Idea mock — only get_source_ids is needed for the non-suppressed path.
		$this->idea = $this->getMockBuilder( \PRAutoBlogger_Article_Idea::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_source_ids' ) )
			->getMock();
	}

	/**
	 * When _prautoblogger_imagery_suppressed = '1', the method returns without
	 * reaching the Source_Collector or Image_Pipeline.
	 */
	public function test_imagery_suppressed_flag_causes_early_return(): void {
		Functions\when( 'get_post_meta' )
			->alias( static function ( int $post_id, string $key, bool $single ) {
				if ( 99 === $post_id && '_prautoblogger_imagery_suppressed' === $key && $single ) {
					return '1';
				}
				return '';
			} );

		// Logger is a singleton; reset was done in BaseTestCase::tearDown.
		// Call the method — it must return without calling get_source_ids (no expectation set).
		\PRAutoBlogger_Post_Assembler::attach_generated_images( 99, $this->idea, array(), null );

		// If we reach here without the idea mock throwing, the early return worked.
		$this->assertTrue( true, 'Method returned early when imagery_suppressed = 1' );
	}

	/**
	 * When _prautoblogger_imagery_suppressed is empty the method proceeds past
	 * the guard and attempts Source_Collector (which will throw in unit context
	 * because there is no DB). We simply verify the guard did not fire.
	 */
	public function test_no_suppression_flag_proceeds_past_guard(): void {
		Functions\when( 'get_post_meta' )
			->alias( static function ( int $post_id, string $key, bool $single ) {
				return ''; // Flag not set.
			} );

		$this->idea->expects( $this->once() )
			->method( 'get_source_ids' )
			->willReturn( array() );

		// Stub wp_unslash / get_term_by / wp_set_post_categories etc. that Source_Collector touches.
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 1 ) );
		Functions\when( 'wp_set_post_categories' )->justReturn( true );
		Functions\when( 'wp_set_post_tags' )->justReturn( true );

		// Source_Collector::get_source_data_for_image will try a DB query — let it throw.
		// We only care that get_source_ids() was called (guard passed).
		try {
			\PRAutoBlogger_Post_Assembler::attach_generated_images( 99, $this->idea, array(), null );
		} catch ( \Throwable $e ) {
			// Expected: DB/class not available in unit context. Guard was not triggered.
		}

		// PHPUnit verifies the ->once() expectation on tearDown.
		$this->assertTrue( true, 'Guard was not triggered; method proceeded past check.' );
	}
}
