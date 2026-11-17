<?php
/**
 * A site without cron: a worker dies, the queue stalls, and the job survives Resume and a stale check.
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
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Queue\Maintenance;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Bulk\JobHealth
 * @covers \TrustOptimize\Bulk\BulkProducer
 * @covers \TrustOptimize\Queue\ConversionQueue
 */
class DeadWorkerMatrixTest extends WP_UnitTestCase {

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

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		remove_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20 );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->jobs        = new BulkJobRepository( $database );
		$this->producer    = Plugin::get_instance()->bulk_producer;
	}

	public function tear_down() {
		add_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20, 2 );
		parent::tear_down();
	}

	private function upload() {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
	}

	private function sql( $sql, ...$args ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
	}

	private function tables() {
		return ( new DatabaseManager() )->get_plugin_table_names();
	}

	/**
	 * A worker that claimed the attachment 20 minutes ago and died; Action Scheduler still shows its action as in progress.
	 *
	 * @param int $id Attachment ID.
	 * @return int Action ID.
	 */
	private function kill_worker( $id ) {
		global $wpdb;

		$this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$this->sql( 'UPDATE %i SET updated_at = %s WHERE attachment_id = %d', $this->tables()['attachments'], gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $id ), ConversionQueue::GROUP );
		ActionScheduler::store()->log_execution( $action );
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $action;
	}

	public function test_a_killed_worker_with_an_in_progress_action_is_not_recovered_and_the_idle_job_stops() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->producer->produce( $job->get_id() );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->kill_worker( $id );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$this->sql( 'UPDATE %i SET updated_at = %s WHERE id = %d', $this->tables()['jobs'], gmdate( 'Y-m-d H:i:s', time() - 1200 ), $job->get_id() );

		Plugin::get_instance()->job_health->check_admin_load();

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ), 'Action Scheduler still holds the action: the attempt is not dead yet.' );
		$this->assertSame( array( 'stuck_claim' ), Plugin::get_instance()->job_health->queue_signals() );
		$row = $this->jobs->get( $job->get_id() )->to_array();
		$this->assertSame( array( JobStatus::PAUSED, 'stopped' ), array( $row['status'], $row['pause_reason'] ) );
	}

	public function test_after_action_scheduler_gives_the_action_up_the_attempt_is_recovered_parked_and_converted_after_resume() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->producer->produce( $job->get_id() );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$action = $this->kill_worker( $id );
		$this->producer->pause( $job->get_id() );

		ActionScheduler::store()->mark_failure( $action );
		$this->assertSame( 1, Plugin::get_instance()->job_health->recover_attempts() );

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::NONE, 'paused' ), array( $row['state'], $row['reason'] ) );
		$this->assertSame( 0, Plugin::get_instance()->job_health->recover_attempts(), 'Once.' );
		$this->assertSame( VariantStatus::PENDING, $this->variants->get_for_attachment( $id )[0]['status'] );
		ActionScheduler_QueueRunner::instance()->run();
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $id ), 'Nothing is converted while paused.' );

		$this->assertTrue( $this->producer->resume( $job->get_id() ) );
		for ( $round = 0; $round < 10 && in_array( $this->jobs->get( $job->get_id() )->get_status(), JobStatus::active(), true ); $round++ ) {
			ActionScheduler_QueueRunner::instance()->run();
			$this->producer->produce( $job->get_id() );
		}

		$this->assertSame( JobStatus::COMPLETED, $this->jobs->get( $job->get_id() )->get_status() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_resumed_job_that_waited_once_stays_running_and_continues_without_a_second_resume() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->producer->produce( $job->get_id() );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$stuck = $this->kill_worker( $id );
		$this->jobs->pause( $job->get_id(), 'stopped' );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$this->assertTrue( $this->producer->resume( $job->get_id() ) );
		$this->producer->produce( $job->get_id() );
		$this->sql( 'UPDATE %i SET updated_at = %s WHERE id = %d', $this->tables()['jobs'], gmdate( 'Y-m-d H:i:s', time() - 1200 ), $job->get_id() );

		Plugin::get_instance()->job_health->check_admin_load();

		$this->assertSame( JobStatus::RUNNING, $this->jobs->get( $job->get_id() )->get_status(), 'No conversion finished since the Resume, and the queue is stuck.' );

		// Action Scheduler runs again.
		ActionScheduler::store()->mark_failure( $stuck );
		Plugin::get_instance()->job_health->recover_attempts();
		for ( $round = 0; $round < 10 && in_array( $this->jobs->get( $job->get_id() )->get_status(), JobStatus::active(), true ); $round++ ) {
			ActionScheduler_QueueRunner::instance()->run();
			$this->producer->produce( $job->get_id() );
		}

		$this->assertSame( JobStatus::COMPLETED, $this->jobs->get( $job->get_id() )->get_status() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_file_given_up_after_its_third_death_stays_given_up_for_bulk_maintenance_and_restore() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$this->sql( 'UPDATE %i SET attempts = 3, updated_at = %s WHERE attachment_id = %d', $this->tables()['attachments'], gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id );
		$this->assertSame( 1, Plugin::get_instance()->job_health->recover_attempts() );
		$this->assertSame( AttachmentState::FAILED, $this->attachments->get_state( $id ) );
		$this->assertNotSame( '', $token );

		$queue = Plugin::get_instance()->conversion_queue;
		$this->assertFalse( $queue->enqueue( $id ), 'A bulk run or an upload does not queue it again.' );
		$this->assertFalse( $queue->requeue( $id ) );
		$this->assertFalse( Plugin::get_instance()->planner->plan( $id )->has_work(), 'Neither does a regeneration.' );
		$this->assertSame( 0, ( new Maintenance( $this->attachments, $queue, Plugin::get_instance()->job_health ) )->run() );
		$this->assertSame( AttachmentState::FAILED, $this->attachments->get_state( $id ) );

		$result = Plugin::get_instance()->processor->sync( $id );
		$this->assertTrue( $result->is_success(), 'An explicit sync is the way back.' );
	}

	public function test_a_parked_attachment_whose_task_cannot_be_scheduled_is_parked_again() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->producer->produce( $job->get_id() );
		$this->producer->pause( $job->get_id() );
		ActionScheduler_QueueRunner::instance()->run();
		$this->jobs->mark_running( $job->get_id() );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$refuse = static function () {
			return 0;
		};
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		try {
			$this->producer->produce( $job->get_id() );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::NONE, 'paused' ), array( $row['state'], $row['reason'] ) );
		$this->assertSame( 1, $this->attachments->count_parked( $job->get_id() ) );
	}
}
