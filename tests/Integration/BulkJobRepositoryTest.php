<?php
/**
 * The compare-and-set update of bulk jobs only touches known columns.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

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

		$this->assertSame( 0, Plugin::get_instance()->job_health->stop_stale_jobs() );
		$this->assertSame( JobStatus::RUNNING, $this->row( $job->get_id() )['status'] );
	}

	public function test_a_really_stale_job_is_paused_on_a_site_west_of_utc() {
		$this->set_offset( -5 );
		$job = $this->jobs->create( 'library' );
		$this->jobs->mark_running( $job->get_id() );
		$this->age( $job->get_id(), 16 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, Plugin::get_instance()->job_health->stop_stale_jobs() );
		$row = $this->row( $job->get_id() );
		$this->assertSame( JobStatus::PAUSED, $row['status'] );
		$this->assertSame( 'stopped', $row['pause_reason'] );
		$this->assertNull( $row['last_error'], 'The reason does not go to the last error.' );
	}

	public function test_a_fresh_job_stays_running_on_a_site_west_of_utc() {
		$this->set_offset( -5 );
		$job = $this->jobs->create( 'library' );
		$this->jobs->mark_running( $job->get_id() );

		$this->assertSame( 0, Plugin::get_instance()->job_health->stop_stale_jobs() );
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

	public function test_a_user_pause_is_stored_and_resume_clears_it() {
		$id = $this->jobs->create( 'library' )->get_id();
		$this->jobs->mark_running( $id );

		$this->assertTrue( $this->jobs->pause( $id ) );
		$this->assertSame( 'user', $this->row( $id )['pause_reason'] );
		$this->assertNull( $this->row( $id )['last_error'] );

		$this->assertTrue( $this->jobs->mark_running( $id ) );
		$this->assertNull( $this->row( $id )['pause_reason'] );
	}

	public function test_cancel_and_complete_from_a_pause_clear_the_reason() {
		foreach ( array( 'cancel', 'complete' ) as $method ) {
			$id = $this->jobs->create( 'library' )->get_id();
			$this->jobs->pause( $id, 'low_disk_space' );
			$this->assertSame( 'low_disk_space', $this->row( $id )['pause_reason'] );

			$this->jobs->$method( $id );

			$this->assertNull( $this->row( $id )['pause_reason'], $method );
		}
	}

	public function test_stale_recovery_leaves_an_existing_last_error_untouched() {
		global $wpdb;

		$id = $this->jobs->create( 'library' )->get_id();
		$this->jobs->mark_running( $id );
		$error = "earlier failure\nsecond line";
		$wpdb->update( $wpdb->prefix . 'trust_optimize_jobs', array( 'last_error' => $error ), array( 'id' => $id ) );
		$this->age( $id, 16 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, Plugin::get_instance()->job_health->stop_stale_jobs() );

		$row = $this->row( $id );
		$this->assertSame( JobStatus::PAUSED, $row['status'] );
		$this->assertSame( 'stopped', $row['pause_reason'] );
		$this->assertSame( $error, $row['last_error'] );
	}

	/**
	 * Variant writes and whether a worker made them.
	 *
	 * @return array[]
	 */
	public static function variant_writes() {
		return array(
			'processing'           => array( VariantStatus::PROCESSING, null, true ),
			'done'                 => array( VariantStatus::DONE, null, true ),
			'skipped'              => array( VariantStatus::SKIPPED, 'no_gain', true ),
			'failed by a worker'   => array( VariantStatus::FAILED, 'save_failed', true ),
			'failed, no reason'    => array( VariantStatus::FAILED, null, true ),
			'pending'              => array( VariantStatus::PENDING, null, false ),
			'failed, max_attempts' => array( VariantStatus::FAILED, 'max_attempts', false ),
			'failed, delete'       => array( VariantStatus::FAILED, 'delete_failed', false ),
		);
	}

	/**
	 * @dataProvider variant_writes
	 *
	 * @param string      $status   Variant status.
	 * @param string|null $reason   Variant reason.
	 * @param bool        $evidence Whether it keeps the job alive.
	 */
	public function test_only_a_workers_variant_writes_keep_a_job_alive( $status, $reason, $evidence ) {
		$database    = new DatabaseManager();
		$variants    = new VariantRepository( $database );
		$attachments = new AttachmentRepository( $database, $variants );
		$job         = $this->jobs->create( 'sync' );
		$this->jobs->mark_running( $job->get_id() );
		$this->age( $job->get_id(), 20 * MINUTE_IN_SECONDS );
		$attachments->assign_job( 850, $job->get_id() );
		$variants->upsert(
			array(
				'attachment_id' => 850,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => $status,
				'reason'        => $reason,
			)
		);
		$attachments->set_state( 850, AttachmentState::OPTIMIZED );

		$this->assertSame( $evidence ? 0 : 1, count( $this->jobs->find_stale_running( 900 ) ) );
	}

	public function test_a_claim_older_than_the_recovery_wait_is_no_evidence_and_a_younger_one_is() {
		global $wpdb;

		$attachments = new AttachmentRepository( new DatabaseManager(), new VariantRepository( new DatabaseManager() ) );
		$job         = $this->jobs->create( 'sync' );
		$this->jobs->mark_running( $job->get_id() );
		$this->age( $job->get_id(), 2 * HOUR_IN_SECONDS );
		$attachments->assign_job( 851, $job->get_id() );
		$attachments->claim( 851, AttachmentRepository::SOURCE_QUEUE );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$set   = static function ( $seconds ) use ( $wpdb, $table ) {
			$wpdb->update( $table, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), array( 'attachment_id' => 851 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		};

		$set( 800 );
		$this->assertCount( 0, $this->jobs->find_stale_running( 900 ) );
		$set( 1000 );
		$this->assertCount( 1, $this->jobs->find_stale_running( 900 ) );
		$this->assertCount( 0, $this->jobs->find_stale_running( 1800 ), 'A higher threshold also stretches the claim.' );
	}
}
