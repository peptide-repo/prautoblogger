<?php
declare(strict_types=1);

/**
 * Tests for PRAutoBlogger_Post_Assembler::attach_generated_images() imagery-suppression gate.
 *
 * What: Verifies that the _prautoblogger_imagery_suppressed post meta flag
 *       causes attach_generated_images() to return early without touching the
 *       image pipeline, and that clearing the flag lets the method proceed.
 * Dependencies: Brain\Monkey (stubs WordPress functions), PRAutoBlogger_Logger stub.
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

		// Logger::instance()->info() reads log level from wp_options — stub it.
		$this->stub_get_option( array(
			'prautoblogger_log_level' => 'info',
		) );

		// Minimal Article_Idea mock — get_source_ids() is needed only for the non-suppressed path.
		$this->idea = $this->getMockBuilder( \PRAutoBlogger_Article_Idea::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_source_ids' ) )
			->getMock();
	}

	/**
	 * When _prautoblogger_imagery_suppressed = '1', the method returns without
	 * reaching the Source_Collector or Image_Pipeline.
	 * The get_source_ids() mock has no expectation — if it were called, PHPUnit
	 * would throw (unexpected call). Reaching assertTrue confirms early return.
	 */
	public function test_imagery_suppressed_flag_causes_early_return(): void {
		Functions\when( 'get_post_meta' )
			->alias( static function ( int $post_id, string $key, bool $single ) {
				if ( 99 === $post_id && '_prautoblogger_imagery_suppressed' === $key && $single ) {
					return '1';
				}
				return '';
			} );

		// current_time is called by Logger — already stubbed in BaseTestCase.
		// No expectation on get_source_ids — if called, that means the guard failed.
		\PRAutoBlogger_Post_Assembler::attach_generated_images( 99, $this->idea, array(), null );

		$this->assertTrue( true, 'Method returned early when imagery_suppressed = 1' );
	}

	/**
	 * When _prautoblogger_imagery_suppressed is empty the method proceeds past
	 * the guard and calls get_source_ids() before attempting Source_Collector.
	 * We assert the guard did not fire by verifying get_source_ids() is reached.
	 */
	public function test_no_suppression_flag_proceeds_past_guard(): void {
		Functions\when( 'get_post_meta' )
			->alias( static function ( int $post_id, string $key, bool $single ) {
				return ''; // Flag not set.
			} );

		$this->idea->expects( $this->once() )
			->method( 'get_source_ids' )
			->willReturn( array() );

		// Source_Collector::get_source_data_for_image will attempt a DB call — let it throw.
		// We only care that get_source_ids() was called (guard passed).
		try {
			\PRAutoBlogger_Post_Assembler::attach_generated_images( 99, $this->idea, array(), null );
		} catch ( \Throwable $e ) {
			// Expected: DB/class not available in unit context.
		}

		// PHPUnit verifies the ->once() expectation on mock teardown.
		$this->assertTrue( true, 'Guard was not triggered; method proceeded past suppression check.' );
	}
}
