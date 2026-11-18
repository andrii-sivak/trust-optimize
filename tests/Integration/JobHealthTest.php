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

	/**
	 * A running sync job whose row has been silent for a while and whose producer is not scheduled.
	 *
	 * @param int $silent_seconds Seconds since the producer's last step.
	 * @return int Job ID.
	 */
	private function silent_job( $silent_seconds = 1080 ) {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC )->get_id();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$this->age_job( $job, $silent_seconds );

		return $job;
	}

	/**
	 * What a worker leaves behind: a variant it moved, and the attachment's closing write.
	 *
	 * @param int         $id      Attachment ID.
	 * @param int         $job     Job ID.
	 * @param string      $status  Variant status.
	 * @param string|null $reason  Variant reason.
	 * @param int         $seconds How long ago it happened.
	 */
	private function worker_change( $id, $job, $status, $reason = null, $seconds = 0 ) {
		$this->attachments->assign_job( $id, $job );
		$this->variants->upsert(
			array(
				'attachment_id' => $id,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => $status,
				'reason'        => $reason,
			)
		);
		$this->attachments->set_state( $id, AttachmentState::OPTIMIZED );
		$this->age_variants( $id, $seconds );
		$this->age_attachment( $id, $seconds );
	}

	private function age_variants( $id, $seconds ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['variants'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - $seconds ), $id ) );
		$this->variants->invalidate( $id );
	}

	/**
	 * A queue attempt of the job that claimed the attachment some time ago.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $job     Job ID.
	 * @param int $seconds Seconds since the claim.
	 * @return string The token.
	 */
	private function claimed( $id, $job, $seconds ) {
		$this->attachments->assign_job( $id, $job );
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$this->age_attachment( $id, $seconds );

		return $token;
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

	public function test_a_resumed_job_whose_progress_is_older_than_the_threshold_is_stopped() {
		$job = $this->resumed_job();
		$this->worker_change( 808, $job, VariantStatus::DONE, null, 1000 );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$this->assertTrue( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ) );
		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_giving_up_a_file_is_not_progress_but_a_failure_is() {
		$job = $this->resumed_job();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$data = $this->jobs->get( $job );
		$this->worker_change( 809, $job, VariantStatus::FAILED, 'max_attempts' );
		$this->attachments->set_state( 809, AttachmentState::FAILED, 'max_attempts' );

		$this->assertFalse( $this->jobs->has_progress_since_resume( $data ), 'The death compare-and-set is not work.' );

		$this->worker_change( 810, $job, VariantStatus::FAILED, 'save_failed' );

		$this->assertTrue( $this->jobs->has_progress_since_resume( $data ) );
	}

	public function test_work_that_finished_before_the_resume_is_not_progress_since_it() {
		$job = $this->jobs->create( 'sync' )->get_id();
		$this->jobs->mark_running( $job );
		$this->worker_change( 811, $job, VariantStatus::DONE, null, HOUR_IN_SECONDS );
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

	public function test_there_are_no_signals_when_action_scheduler_cannot_answer() {
		global $wp_actions, $wpdb;

		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$this->make_action_old( $action, 2000, ActionScheduler_Store::STATUS_RUNNING );
		$this->assertSame( array( JobHealth::SIGNAL_STUCK_CLAIM ), $this->health->queue_signals() );

		$initialised = $wp_actions['action_scheduler_init'];
		unset( $wp_actions['action_scheduler_init'] );
		try {
			$this->assertSame( array(), $this->health->queue_signals(), 'Not initialised.' );
		} finally {
			$wp_actions['action_scheduler_init'] = $initialised;
		}

		$break = static function ( $query ) {
			return false !== strpos( $query, 'actionscheduler_actions' ) ? 'SELECT * FROM trust_optimize_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );
		try {
			$this->assertSame( array(), $this->health->queue_signals(), 'A failed query.' );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $break );
		}
	}

	public function test_a_resumed_job_that_made_progress_is_stopped_after_aging_although_its_producer_is_pending() {
		$job = $this->resumed_job();
		$this->worker_change( 812, $job, VariantStatus::DONE, null, 1000 );
		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => BulkProducer::HOOK_PRODUCE, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_resumed_remove_job_whose_cursor_moved_is_stopped_after_aging() {
		$job = $this->resumed_job( BulkJob::TYPE_REMOVE );
		$this->jobs->set_cursor( $job, 99 );
		$this->age_job( $job, 20 * MINUTE_IN_SECONDS );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_job_stays_running_while_its_conversions_are_claimed_and_finished_although_its_row_is_old() {
		$job = $this->silent_job( 1080 );
		$this->worker_change( 820, $job, VariantStatus::DONE );
		$this->claimed( 821, $job, 60 );

		$this->health->check_admin_load();

		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_real_conversions_of_a_producer_that_handed_over_keep_the_job_alive() {
		$producer = Plugin::get_instance()->bulk_producer;
		remove_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20 );
		$ids = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$ids[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		}
		add_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20, 2 );
		$job = $producer->launch( BulkJob::TYPE_SYNC )->get_id();
		$producer->produce( $job );
		$this->age_job( $job, 1080 );

		ActionScheduler_QueueRunner::instance()->run();
		$this->health->check_admin_load();

		foreach ( $ids as $id ) {
			$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		}
		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_a_job_whose_producer_stepped_within_the_threshold_is_alive() {
		$job = $this->silent_job( 600 );

		$this->assertSame( array(), $this->jobs->find_stale_running( 900 ) );
		$this->health->check_admin_load();
		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
	}

	public function test_replanning_an_old_final_state_is_not_evidence() {
		$job = $this->silent_job();
		$this->worker_change( 830, $job, VariantStatus::DONE, null, 3 * HOUR_IN_SECONDS );

		$this->attachments->recompute( 830 );

		$this->assertSame( array( $job ), array_map( static fn( $found ) => $found->get_id(), $this->jobs->find_stale_running( 900 ) ) );
		$this->health->check_admin_load();
		$this->assertSame( JobStatus::PAUSED, $this->status( $job ) );
	}

	public function test_replanning_a_poison_file_is_no_evidence_for_liveness_or_for_progress_since_resume() {
		$job = $this->resumed_job();
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		$this->worker_change( 831, $job, VariantStatus::FAILED, 'max_attempts', 3 * HOUR_IN_SECONDS );
		$this->attachments->set_state( 831, AttachmentState::FAILED, 'max_attempts' );

		$this->attachments->recompute( 831 );

		$this->assertNull( $this->attachments->get( 831 )['reason'], 'recompute() rewrote the row with no reason.' );
		$this->assertFalse( $this->jobs->has_progress_since_resume( $this->jobs->get( $job ) ) );
		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_racing_planner_insert_of_a_done_variant_is_not_evidence() {
		$job = $this->silent_job();
		$this->worker_change( 832, $job, VariantStatus::DONE, null, 3 * HOUR_IN_SECONDS );
		$this->variants->upsert(
			array(
				'attachment_id' => 832,
				'size_name'     => 'original',
				'format'        => 'webp',
			)
		);
		$this->attachments->recompute( 832 );

		$this->assertCount( 1, $this->jobs->find_stale_running( 900 ) );
	}

	public function test_a_dead_worker_without_a_runner_is_stopped_once_its_claim_is_older_than_the_threshold() {
		$job = $this->silent_job();
		$this->claimed( 833, $job, 1000 );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
		$this->assertSame( JobStatus::PAUSED, $this->status( $job ) );
	}

	public function test_recoveries_and_repairs_are_not_evidence() {
		$job = $this->silent_job();
		// The death compare-and-set, with its poison branch, then a requeue and the poison compare-and-set of a late action.
		$token = $this->claimed( 834, $job, 2000 );
		$this->variants->upsert(
			array(
				'attachment_id' => 834,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
			)
		);
		$this->age_variants( 834, 2000 );
		$this->assertTrue( $this->attachments->recover_dead( 834, $token ) );
		$this->attachments->set_state( 835, AttachmentState::QUEUED );
		$this->attachments->assign_job( 835, $job );
		$this->age_attachment( 835, 2000 );
		$this->assertTrue( $this->attachments->requeue( 835, 900 ) );
		$this->claimed( 836, $job, 2000 );
		$this->variants->upsert(
			array(
				'attachment_id' => 836,
				'size_name'     => 'original',
				'format'        => 'webp',
			)
		);
		$this->age_variants( 836, 2000 );
		$this->attachments->release( 836, $this->attachments->get( 836 )['attempt_token'], $this->attachments->get( 836 )['updated_at'], null );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_variant_change_of_another_job_is_not_evidence_for_this_one() {
		$job = $this->silent_job();
		$this->worker_change( 837, $job + 1000, VariantStatus::DONE );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
		$this->assertSame( JobStatus::PAUSED, $this->status( $job ) );
	}

	public function test_a_raised_threshold_keeps_the_job_alive_through_a_slow_claim_until_the_threshold() {
		add_filter(
			'trust_optimize_bulk_stale_after_seconds',
			static function () {
				return 3600;
			}
		);
		$job = $this->silent_job( 4000 );
		$this->claimed( 838, $job, 1200 );

		$this->assertSame( 0, $this->health->stop_stale_jobs() );
		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );

		$this->age_attachment( 838, 3700 );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_low_threshold_keeps_the_job_alive_through_a_claim_until_the_recovery_wait() {
		add_filter(
			'trust_optimize_bulk_stale_after_seconds',
			static function () {
				return 300;
			}
		);
		$job = $this->silent_job( 700 );
		$this->claimed( 839, $job, 600 );

		$this->assertSame( 0, $this->health->stop_stale_jobs(), 'A claim of 600 s is still protected from recovery.' );

		$this->age_attachment( 839, 1000 );

		$this->assertSame( 1, $this->health->stop_stale_jobs() );
	}

	public function test_a_producer_wait_keeps_a_job_alive_while_a_sync_attempt_is_held() {
		$job = $this->silent_job( 1080 );
		$this->attachments->assign_job( 840, $job );
		$this->attachments->claim( 840, AttachmentRepository::SOURCE_SYNC );
		$this->age_attachment( 840, 2000 );
		$this->jobs->touch( $job );

		$this->health->check_admin_load();

		$this->assertSame( JobStatus::RUNNING, $this->status( $job ) );
		$this->assertCount( 1, $this->health->held() );
	}

	public function test_only_an_admin_page_load_stops_a_job_without_evidence() {
		$job = $this->silent_job();
		add_filter( 'wp_doing_cron', '__return_true' );

		try {
			$this->health->check_admin_load();
			$this->assertSame( JobStatus::RUNNING, $this->status( $job ), 'Cron changes nothing.' );
			$this->health->recover_attempts();
			$this->assertSame( JobStatus::RUNNING, $this->status( $job ), 'Step 1 alone never stops a job.' );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
	}
}
