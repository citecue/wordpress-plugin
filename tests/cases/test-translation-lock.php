<?php
/**
 * The per-main-post lock that serializes translation pushes.
 *
 * Two pushes into one translation group must not interleave, and a request
 * that dies holding the lock must not block the group for good.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Translations
 */
class Test_Citecue_Translation_Lock extends Citecue_Test_Case {

	/**
	 * Clears any lock a previous test held in this process.
	 *
	 * @return void
	 */
	public function tear_down() {
		Citecue_Translations::release_held_locks();
		parent::tear_down();
	}

	/**
	 * @return void
	 */
	public function test_a_free_lock_is_taken_and_released() {
		$this->assertTrue( Citecue_Translations::acquire_lock( 'main-1', 0 ) );
		$this->assertNotNull( $this->lock_row( 'main-1' ) );

		Citecue_Translations::release_lock( 'main-1' );
		$this->assertNull( $this->lock_row( 'main-1' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_held_lock_is_refused() {
		$this->hold( 'main-1', time() );

		$this->assertFalse( Citecue_Translations::acquire_lock( 'main-1', 0 ) );
	}

	/**
	 * Contention waits before giving up, rather than failing on the first try.
	 *
	 * @return void
	 */
	public function test_contention_waits_then_gives_up() {
		$this->hold( 'main-1', time() );

		$started = microtime( true );
		$this->assertFalse( Citecue_Translations::acquire_lock( 'main-1', 1 ) );
		$this->assertGreaterThanOrEqual( 0.9, microtime( true ) - $started );
	}

	/**
	 * Locks are per main post: another group is not blocked.
	 *
	 * @return void
	 */
	public function test_another_main_post_is_not_blocked() {
		$this->hold( 'main-1', time() );

		$this->assertTrue( Citecue_Translations::acquire_lock( 'main-2', 0 ) );
	}

	/**
	 * A crashed request's lock expires and is taken over.
	 *
	 * @return void
	 */
	public function test_a_stale_lock_is_taken_over() {
		$this->hold( 'main-1', time() - Citecue_Translations::LOCK_TTL - 1 );

		$this->assertTrue( Citecue_Translations::acquire_lock( 'main-1', 0 ) );
		$this->assertGreaterThanOrEqual( time() - 1, (int) $this->lock_row( 'main-1' ) );
	}

	/**
	 * A lock someone else took over is not released by its old owner.
	 *
	 * @return void
	 */
	public function test_release_leaves_a_lock_taken_over_by_another_request() {
		$this->assertTrue( Citecue_Translations::acquire_lock( 'main-1', 0 ) );

		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => time() . ':someone-else' ), array( 'option_name' => Citecue_Translations::lock_name( 'main-1' ) ) );

		Citecue_Translations::release_lock( 'main-1' );
		$this->assertSame( time() . ':someone-else', $this->lock_row( 'main-1' ) );
	}

	/**
	 * @return void
	 */
	public function test_the_lock_is_released_after_an_exception() {
		try {
			Citecue_Translations::with_lock(
				'main-1',
				0,
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception should propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertNull( $this->lock_row( 'main-1' ) );
	}

	/**
	 * A fatal error skips `finally`; the shutdown hook releases what is held.
	 *
	 * @return void
	 */
	public function test_shutdown_releases_held_locks() {
		$this->assertTrue( Citecue_Translations::acquire_lock( 'main-1', 0 ) );

		Citecue_Translations::release_held_locks();

		$this->assertNull( $this->lock_row( 'main-1' ) );
	}

	/**
	 * @return void
	 */
	public function test_with_lock_answers_busy_on_contention() {
		$this->hold( 'main-1', time() );
		$ran = false;

		$result = Citecue_Translations::with_lock(
			'main-1',
			0,
			static function () use ( &$ran ) {
				$ran = true;
			}
		);

		$this->assertFalse( $ran );
		$this->assertWPError( $result );
		$this->assertSame( 'citecue_busy', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/**
	 * Simulates another request holding the lock since `$since`.
	 *
	 * @param string $key   Lock key.
	 * @param int    $since Unix time the holder took it.
	 * @return void
	 */
	private function hold( $key, $since ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => Citecue_Translations::lock_name( $key ),
				'option_value' => $since . ':other-request',
				'autoload'     => 'no',
			)
		);
	}

	/**
	 * The raw lock row value, or null.
	 *
	 * @param string $key Lock key.
	 * @return string|null
	 */
	private function lock_row( $key ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Citecue_Translations::lock_name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
