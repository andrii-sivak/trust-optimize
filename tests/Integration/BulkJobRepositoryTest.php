<?php
/**
 * The compare-and-set update of bulk jobs only touches known columns.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;

/**
 * @covers \TrustOptimize\Bulk\BulkJobRepository::transition
 */
class BulkJobRepositoryTest extends WP_UnitTestCase {

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	public function set_up() {
		parent::set_up();
		$this->jobs = new BulkJobRepository( new DatabaseManager() );
	}

	private function row( $job_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'trust_optimize_jobs', $job_id ),
			ARRAY_A
		);
	}

	private function transition( $job_id, array $from, array $data ) {
		$method = new ReflectionMethod( BulkJobRepository::class, 'transition' );
		$method->setAccessible( true );

		return $method->invoke( $this->jobs, $job_id, $from, $data );
	}

	public function test_a_column_that_is_not_updatable_is_ignored() {
		$job = $this->jobs->create( 'library' );

		$this->assertTrue(
			$this->transition(
				$job->get_id(),
				array( JobStatus::PENDING ),
				array(
					'status'                         => JobStatus::PAUSED,
					'type'                           => 'hacked',
					'id = 0, status'                 => 'x',
					'last_error` = (SELECT 1), `type' => 'x',
				)
			)
		);

		$row = $this->row( $job->get_id() );
		$this->assertSame( JobStatus::PAUSED, $row['status'] );
		$this->assertSame( 'library', $row['type'] );
	}

	public function test_null_is_stored_as_null() {
		$job = $this->jobs->create( 'library' );
		$id  = $job->get_id();

		$this->transition( $id, array( JobStatus::PENDING ), array( 'last_error' => 'boom', 'finished_at' => '2026-05-01 10:00:00' ) );
		$row = $this->row( $id );
		$this->assertSame( 'boom', $row['last_error'] );
		$this->assertSame( '2026-05-01 10:00:00', $row['finished_at'] );

		$this->assertTrue( $this->transition( $id, array( JobStatus::PENDING ), array( 'last_error' => null, 'finished_at' => null ) ) );
		$row = $this->row( $id );
		$this->assertNull( $row['last_error'] );
		$this->assertNull( $row['finished_at'] );
	}

	public function test_a_job_in_another_status_is_left_alone() {
		$job = $this->jobs->create( 'library' );
		$id  = $job->get_id();

		$this->assertFalse( $this->transition( $id, array( JobStatus::RUNNING ), array( 'status' => JobStatus::PAUSED ) ) );
		$this->assertSame( JobStatus::PENDING, $this->row( $id )['status'] );
	}

	private function set_offset( $hours ) {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', $hours );
	}

	private function age( $job_id, $seconds ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'trust_optimize_jobs',
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array( 'id' => $job_id )
		);
	}

	public function tear_down() {
		delete_option( 'gmt_offset' );
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	public function test_a_growing_site_offset_does_not_make_a_running_job_stale() {
		$this->set_offset( 0 );
		$job = $this->jobs->create( 'library' );
		$this->jobs->mark_running( $job->get_id() );
		$this->jobs->touch( $job->get_id() );

		$this->set_offset( 3 );

		$this->assertSame( 0, $this->jobs->recover_stale_running() );
		$this->assertSame( JobStatus::RUNNING, $this->row( $job->get_id() )['status'] );
	}

	public function test_a_really_stale_job_is_paused_on_a_site_west_of_utc() {
		$this->set_offset( -5 );
		$job = $this->jobs->create( 'library' );
		$this->jobs->mark_running( $job->get_id() );
		$this->age( $job->get_id(), 16 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, $this->jobs->recover_stale_running() );
		$this->assertSame( JobStatus::PAUSED, $this->row( $job->get_id() )['status'] );
	}

	public function test_a_fresh_job_stays_running_on_a_site_west_of_utc() {
		$this->set_offset( -5 );
		$job = $this->jobs->create( 'library' );
		$this->jobs->mark_running( $job->get_id() );

		$this->assertSame( 0, $this->jobs->recover_stale_running() );
		$this->assertSame( JobStatus::RUNNING, $this->row( $job->get_id() )['status'] );
	}

	public function test_written_timestamps_are_utc() {
		$this->set_offset( 3 );
		$job = $this->jobs->create( 'library' );
		$id  = $job->get_id();
		$this->assertEqualsWithDelta( time(), strtotime( $this->row( $id )['updated_at'] . ' UTC' ), 5 );

		$this->jobs->mark_running( $id );
		$row = $this->row( $id );
		$this->assertEqualsWithDelta( time(), strtotime( $row['updated_at'] . ' UTC' ), 5 );
		$this->assertEqualsWithDelta( time(), strtotime( $row['started_at'] . ' UTC' ), 5 );

		$this->jobs->complete( $id );
		$row = $this->row( $id );
		$this->assertEqualsWithDelta( time(), strtotime( $row['finished_at'] . ' UTC' ), 5 );
		$this->assertEqualsWithDelta( time(), strtotime( $row['updated_at'] . ' UTC' ), 5 );
	}

	public function test_touch_and_pause_unfinished_write_utc() {
		$this->set_offset( 3 );
		$touched = $this->jobs->create( 'library' );
		$id      = $touched->get_id();
		$this->age( $id, DAY_IN_SECONDS );

		$this->jobs->touch( $id );
		$this->assertEqualsWithDelta( time(), strtotime( $this->row( $id )['updated_at'] . ' UTC' ), 5 );

		$this->age( $id, DAY_IN_SECONDS );
		$this->jobs->pause_unfinished( 'deactivated' );
		$this->assertEqualsWithDelta( time(), strtotime( $this->row( $id )['updated_at'] . ' UTC' ), 5 );
	}
}
