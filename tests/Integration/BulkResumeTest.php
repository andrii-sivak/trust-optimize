<?php
/**
 * Resume continues a job, is undone when its producer cannot be scheduled, and a start that cannot be scheduled fails.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Bulk\BulkProducer::resume
 * @covers \TrustOptimize\Bulk\BulkProducer::launch
 * @covers \TrustOptimize\Bulk\BulkJobRepository::mark_running
 * @covers \TrustOptimize\Bulk\BulkJobRepository::revert_resume
 */
class BulkResumeTest extends WP_UnitTestCase {

	/**
	 * Producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
		$this->jobs        = new BulkJobRepository( $database );
		$this->producer    = Plugin::get_instance()->bulk_producer;
	}

	/**
	 * A job that stopped with an error behind it.
	 *
	 * @return int Job ID.
	 */
	private function stopped_job() {
		global $wpdb;

		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->set_cursor( $job->get_id(), 41 );
		$this->jobs->pause( $job->get_id(), PauseReason::STOPPED );
		$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['jobs'], array( 'last_error' => 'attachment 7, death 2 of 3' ), array( 'id' => $job->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		return $job->get_id();
	}

	/**
	 * Make Action Scheduler refuse new async actions while a callback runs.
	 *
	 * @param callable|null $before Called when the refusal happens.
	 * @return callable Call it to stop refusing.
	 */
	private function refuse_async_actions( $before = null ) {
		$refuse = static function ( $pre ) use ( $before ) {
			if ( null !== $before ) {
				$before();
			}

			return 0;
		};
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		return static function () use ( $refuse ) {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		};
	}

	private function row( $id ) {
		return $this->jobs->get( $id )->to_array();
	}

	public function test_resume_records_itself_and_clears_the_pause_reason_and_the_last_error() {
		$id = $this->stopped_job();

		$this->assertTrue( $this->producer->resume( $id ) );

		$row = $this->row( $id );
		$this->assertSame( JobStatus::RUNNING, $row['status'] );
		$this->assertSame( array( null, null ), array( $row['pause_reason'], $row['last_error'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $row['resume_token'] );
		$this->assertSame( '41', (string) $row['resume_cursor_id'] );
		$this->assertEqualsWithDelta( time(), strtotime( $row['resumed_at'] . ' UTC' ), 5 );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => BulkProducer::HOOK_PRODUCE, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}

	public function test_resume_refuses_a_sync_job_on_a_full_disk_and_changes_nothing() {
		$id = $this->stopped_job();
		$attempt = $this->attachments->claim( 901, AttachmentRepository::SOURCE_QUEUE );
		$this->assertNotFalse( $attempt );
		global $wpdb;
		$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['attachments'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 5000 ) ), array( 'attachment_id' => 901 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$low = static function () {
			return PHP_INT_MAX;
		};
		add_filter( 'trust_optimize_min_free_disk_bytes', $low );
		$before = $this->row( $id );

		try {
			$result = $this->producer->resume( $id );
		} finally {
			remove_filter( 'trust_optimize_min_free_disk_bytes', $low );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'trust_optimize_disk_low', $result->get_error_code() );
		$this->assertSame( $before, $this->row( $id ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 901 ), 'The health check did not run.' );
	}

	public function test_resume_recovers_dead_attempts_first() {
		$id = $this->stopped_job();
		$this->attachments->claim( 902, AttachmentRepository::SOURCE_QUEUE );
		global $wpdb;
		$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['attachments'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 5000 ) ), array( 'attachment_id' => 902 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertTrue( $this->producer->resume( $id ) );

		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( 902 ) );
	}

	public function test_a_producer_that_cannot_be_scheduled_undoes_the_resume() {
		$id     = $this->stopped_job();
		$before = $this->row( $id );
		$stop   = $this->refuse_async_actions();

		try {
			$result = $this->producer->resume( $id );
		} finally {
			$stop();
		}

		$this->assertWPError( $result );
		$this->assertSame( 'trust_optimize_resume_failed', $result->get_error_code() );
		$row = $this->row( $id );
		$this->assertSame( array( JobStatus::PAUSED, PauseReason::STOPPED, 'attachment 7, death 2 of 3', $before['resumed_at'] ), array( $row['status'], $row['pause_reason'], $row['last_error'], $row['resumed_at'] ) );
	}

	public function test_a_death_recorded_meanwhile_survives_the_undo() {
		$id   = $this->stopped_job();
		$stop = $this->refuse_async_actions(
			function () use ( $id ) {
				$this->jobs->record_death( $id, 'attachment 9, death 1 of 3' );
			}
		);

		try {
			$result = $this->producer->resume( $id );
		} finally {
			$stop();
		}

		$this->assertWPError( $result );
		$this->assertSame( 'attachment 9, death 1 of 3', $this->row( $id )['last_error'] );
		$this->assertSame( JobStatus::PAUSED, $this->row( $id )['status'] );
	}

	public function test_a_pause_that_came_first_is_not_undone_and_the_error_says_where_the_job_is() {
		$id   = $this->stopped_job();
		$stop = $this->refuse_async_actions(
			function () use ( $id ) {
				$this->jobs->pause( $id );
			}
		);

		try {
			$result = $this->producer->resume( $id );
		} finally {
			$stop();
		}

		$this->assertWPError( $result );
		$row = $this->row( $id );
		$this->assertSame( array( JobStatus::PAUSED, PauseReason::USER ), array( $row['status'], $row['pause_reason'] ), 'The job stays as the Pause left it.' );
		$this->assertStringContainsString( 'paused', $result->get_error_message() );
		$this->assertFalse( $result->get_error_data()['reverted'] );
	}

	public function test_a_resume_that_came_first_is_not_undone_either() {
		global $wpdb;

		$id   = $this->stopped_job();
		$stop = $this->refuse_async_actions(
			function () use ( $id, $wpdb ) {
				$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['jobs'], array( 'resume_token' => str_repeat( 'b', 32 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		);

		try {
			$this->producer->resume( $id );
		} finally {
			$stop();
		}

		$this->assertSame( JobStatus::RUNNING, $this->row( $id )['status'] );
	}

	public function test_resuming_a_running_job_writes_nothing_and_only_schedules_a_missing_producer() {
		$id = $this->stopped_job();
		$this->assertTrue( $this->producer->resume( $id ) );
		$before = $this->row( $id );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$this->assertTrue( $this->producer->resume( $id ) );

		$this->assertSame( $before['resume_token'], $this->row( $id )['resume_token'] );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => BulkProducer::HOOK_PRODUCE, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$stop = $this->refuse_async_actions();
		try {
			$result = $this->producer->resume( $id );
		} finally {
			$stop();
		}

		$this->assertWPError( $result );
		$this->assertSame( JobStatus::RUNNING, $this->row( $id )['status'], 'Nothing to undo.' );
		$this->assertSame( $before['resume_token'], $this->row( $id )['resume_token'] );
	}

	public function test_a_producer_that_is_already_pending_counts_as_scheduled() {
		$id = $this->stopped_job();
		as_enqueue_async_action( BulkProducer::HOOK_PRODUCE, array( 'job_id' => $id ), ConversionQueue::GROUP );
		$stop = $this->refuse_async_actions();

		try {
			$this->assertTrue( $this->producer->resume( $id ) );
		} finally {
			$stop();
		}
	}

	public function test_a_start_whose_producer_cannot_be_scheduled_fails_the_job_and_frees_the_library() {
		$stop = $this->refuse_async_actions();

		try {
			$result = $this->producer->launch( BulkJob::TYPE_SYNC );
		} finally {
			$stop();
		}

		$this->assertWPError( $result );
		$this->assertSame( 'trust_optimize_start_failed', $result->get_error_code() );
		$job = $this->jobs->get_latest_job();
		$this->assertSame( JobStatus::FAILED, $job->get_status() );
		$this->assertSame( 'producer could not be scheduled', $job->to_array()['last_error'] );
		$this->assertNull( $this->jobs->get_active_job() );
		$this->assertInstanceOf( BulkJob::class, $this->producer->launch( BulkJob::TYPE_SYNC ), 'The mutex is released.' );
	}
}
