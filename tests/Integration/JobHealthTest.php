<?php
/**
 * Held attachments, the stale step and the queue signals.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Bulk\JobHealth;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Bulk\JobHealth
 * @covers \TrustOptimize\Bulk\BulkJobRepository
 */
class JobHealthTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var JobHealth
	 */
	private $health;

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

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->jobs        = new BulkJobRepository( $database );
		$this->health      = Plugin::get_instance()->job_health;
	}

	private function age_attachment( $id, $seconds ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - $seconds ), $id ) );
	}

	private function age_job( $id, $seconds ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['jobs'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - $seconds ), $id ) );
	}

	/**
	 * A sync job that was paused and resumed, then stayed silent for 20 minutes.
	 *
	 * @param string $type Job type.
	 * @return int Job ID.
	 */
	private function resumed_job( $type = BulkJob::TYPE_SYNC ) {
		$producer = Plugin::get_instance()->bulk_producer;
		$job      = $producer->launch( $type );
		$producer->pause( $job->get_id() );
		$this->assertTrue( $producer->resume( $job->get_id() ) );
		$this->age_job( $job->get_id(), 20 * MINUTE_IN_SECONDS );

		// The Resume was 20 minutes ago: progress is compared at second precision.
		global $wpdb;
		$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['jobs'], array( 'resumed_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ) ), array( 'id' => $job->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $job->get_id();
	}

	private function status( $job_id ) {
		return $this->jobs->get( $job_id )->get_status();
	}

	private function make_action_old( $action_id, $seconds, $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'last_attempt_gmt'   => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				'last_attempt_local' => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				'status'             => $status,
			),
			array( 'action_id' => $action_id )
		);
	}

	public function test_held_attachments_are_the_old_sync_and_legacy_attempts_and_old_leases() {
		global $wpdb;

		$table  = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$sync   = 801;
		$legacy = 802;
		$leased = 803;
		$empty  = 804;
		$queue  = 805;
		$young  = 806;
		$this->attachments->claim( $sync, AttachmentRepository::SOURCE_SYNC );
		$this->attachments->claim( $legacy, AttachmentRepository::SOURCE_QUEUE );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempt_source = NULL, attempt_token = NULL WHERE attachment_id = %d", $legacy ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$token = $this->attachments->claim( $leased, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( $leased, $token );
		$this->variants->upsert(
			array(
				'attachment_id' => $leased,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
				'relative_path' => '2026/11/leased.jpg.webp',
			)
		);
		$token = $this->attachments->claim( $empty, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( $empty, $token );
		$this->attachments->claim( $queue, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->claim( $young, AttachmentRepository::SOURCE_SYNC );
		foreach ( array( $sync, $legacy, $leased, $empty, $queue ) as $id ) {
			$this->age_attachment( $id, 2000 );
		}
		// The lease is as old as the attempt.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET publishing_at = %s WHERE attachment_id IN (%d, %d)", gmdate( 'Y-m-d H:i:s', time() - 2000 ), $leased, $empty ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$held = array_column( $this->health->held(), null, 'attachment_id' );

		$this->assertSame( array( $sync, $legacy, $leased, $empty ), array_keys( $held ), 'A queue attempt without a lease is for the death compare-and-set, a young one for nobody.' );
		$this->assertSame( array( 'sync', 'sync' ), array( $held[ $sync ]['source'], $held[ $sync ]['kind'] ) );
		$this->assertSame( array( 'legacy', 'legacy', null ), array( $held[ $legacy ]['source'], $held[ $legacy ]['kind'], $held[ $legacy ]['token'] ) );
		$this->assertSame( 'publication_incomplete', $held[ $leased ]['kind'] );
		$this->assertSame( array( '2026/11/leased.jpg.webp' ), $held[ $leased ]['paths'] );
		$this->assertSame( 'lease_not_released', $held[ $empty ]['kind'] );
		$this->assertSame( array(), $held[ $empty ]['paths'] );
	}

	public function test_a_held_attachment_is_never_recovered_by_a_health_run() {
		$id = 807;
		$this->attachments->claim( $id, AttachmentRepository::SOURCE_SYNC );
		$this->age_attachment( $id, 5000 );

		$this->health->recover_attempts();
		$this->health->check_admin_load();

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
	}

	public function test_a_resumed_job_that_only_waited_is_not_stopped_while_its_producer_is_scheduled() {
		$job = $this->resumed_job();

		$this->assertSame( 0, $this->health->stop_stale_jobs() );

		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_a_producer_that_waited_once_is_not_progress() {
		$job = $this->resumed_job();
		$this->jobs->touch( $job );
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );

		$this->assertSame( 0, $this->health->stop_stale_jobs() );
		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_a_resumed_job_without_a_producer_and_without_signals_is_stopped() {
		$job = $this->resumed_job();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );

		$row = $this->jobs->get( $job )->to_array();
		$this->assertSame( array( JobStatus::PAUSED, PauseReason::STOPPED ), array( $row['status'], $row['pause_reason'] ) );
	}

	public function test_a_resumed_job_without_a_producer_is_kept_while_the_queue_shows_a_signal() {
		$job = $this->resumed_job();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$this->make_action_old( $action, 2000, ActionScheduler_Store::STATUS_RUNNING );

		$this->assertSame( 0, $this->health->stop_stale_jobs() );
		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_a_resumed_job_that_made_progress_is_judged_by_the_old_test() {
		$job = $this->resumed_job();
		$id  = 808;
		$this->attachments->assign_job( $id, $job );
		$this->attachments->set_state( $id, AttachmentState::OPTIMIZED );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_giving_up_a_file_is_not_progress_but_a_failure_is() {
		$job = $this->resumed_job();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$data = $this->jobs->get( $job );
		$this->attachments->assign_job( 809, $job );
		$this->attachments->set_state( 809, AttachmentState::FAILED, 'max_attempts' );

		$this->assertFalse( $this->jobs->has_progress_since_resume( $data ), 'The death compare-and-set is not work.' );

		$this->attachments->assign_job( 810, $job );
		$this->attachments->set_state( 810, AttachmentState::FAILED, 'save_failed' );

		$this->assertTrue( $this->jobs->has_progress_since_resume( $data ) );
	}

	public function test_work_that_finished_before_the_resume_is_not_progress_since_it() {
		$job = $this->jobs->create( 'sync' )->get_id();
		$this->jobs->mark_running( $job );
		$this->attachments->assign_job( 811, $job );
		$this->attachments->set_state( 811, AttachmentState::OPTIMIZED );
		$this->age_attachment( 811, HOUR_IN_SECONDS );
		$this->jobs->pause( $job );
		$this->jobs->mark_running( $job, str_repeat( 'a', 32 ) );

		$this->assertFalse( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ) );
	}

	public function test_a_remove_job_has_progress_only_when_its_cursor_moved() {
		$job = $this->resumed_job( BulkJob::TYPE_REMOVE );

		$this->assertFalse( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ) );

		$this->jobs->touch( $job );
		$this->assertFalse( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ), 'A touch is not progress.' );

		$this->jobs->set_cursor( $job, 99 );
		$this->assertTrue( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ) );
	}

	public function test_a_job_that_was_never_resumed_is_judged_by_the_old_test() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC )->get_id();
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_the_stale_step_runs_on_admin_page_loads_only() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC )->get_id();
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );
		add_filter( 'wp_doing_ajax', '__return_true' );

		try {
			$this->health->check_admin_load();
			$this->assertSame( JobStatus::RUNNING, $this->status( $job ), 'A status poll changes nothing.' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->health->check_admin_load();
		$this->assertSame( JobStatus::PAUSED, $this->status( $job ) );
	}

	public function test_signals_need_an_active_job() {
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$this->make_action_old( $action, 2000, ActionScheduler_Store::STATUS_RUNNING );

		$this->assertSame( array(), $this->health->queue_signals() );

		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );

		$this->assertSame( array( JobHealth::SIGNAL_STUCK_CLAIM ), $this->health->queue_signals() );
	}

	public function test_a_claimed_pending_action_is_a_stuck_claim() {
		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$this->make_action_old( $action, 2000, ActionScheduler_Store::STATUS_PENDING );
		global $wpdb;
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'claim_id' => 77 ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertContains( JobHealth::SIGNAL_STUCK_CLAIM, $this->health->queue_signals() );
	}

	public function test_a_due_action_without_a_recent_completion_is_no_progress_and_a_completion_clears_it() {
		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$due = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$this->make_action_old( $due, 2000, ActionScheduler_Store::STATUS_PENDING );

		$this->assertSame( array( JobHealth::SIGNAL_NO_PROGRESS ), $this->health->queue_signals() );

		$done = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 2 ), ConversionQueue::GROUP );
		ActionScheduler::store()->mark_complete( $done );

		$this->assertSame( array(), $this->health->queue_signals(), 'The queue works: slowly, but it does.' );
	}

	public function test_a_healthy_queue_has_no_signals() {
		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );

		$this->assertSame( array(), $this->health->queue_signals() );
	}
}
