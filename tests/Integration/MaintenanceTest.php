<?php
/**
 * Hourly repair of lost tasks and poison files (04.4).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\JobHealth;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Queue\Maintenance;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\Maintenance
 * @covers \TrustOptimize\Storage\AttachmentRepository
 */
class MaintenanceTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Subject.
	 *
	 * @var Maintenance
	 */
	private $maintenance;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( Maintenance::HOOK );

		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
		$this->maintenance = new Maintenance( $this->attachments, Plugin::get_instance()->conversion_queue, Plugin::get_instance()->job_health );
	}

	/**
	 * An attachment whose last change is 20 minutes old.
	 *
	 * @param string $state    AttachmentState.
	 * @param int    $attempts Failed attempts.
	 * @return int
	 */
	private function stuck( $state, $attempts = 0 ) {
		global $wpdb;

		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, $state );

		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET attempts = %d, updated_at = %s WHERE attachment_id = %d", $attempts, gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id ) );

		return $id;
	}

	/**
	 * A queue worker that claimed the attachment 20 minutes ago and was never heard of again.
	 *
	 * @param int    $attempts Claims so far, this one included.
	 * @param string $source   AttachmentRepository::SOURCE_*.
	 * @return int
	 */
	private function silent_worker( $attempts = 1, $source = AttachmentRepository::SOURCE_QUEUE ) {
		global $wpdb;

		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->claim( $id, $source );

		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET attempts = %d, updated_at = %s WHERE attachment_id = %d", $attempts, gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id ) );

		return $id;
	}

	private function pending( $id ) {
		return as_get_scheduled_actions(
			array(
				'hook'   => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'attachment_id' => $id ),
			)
		);
	}

	public function test_a_dead_worker_is_recovered_once_and_its_attachment_queued_at_priority_20() {
		$id = $this->silent_worker( 1 );

		$this->assertSame( 1, $this->maintenance->run() );

		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::QUEUED, $row['state'] );
		$this->assertNull( $row['attempt_token'] );
		$this->assertSame( 1, $row['attempts'], 'The death stays counted until the worker comes back alive.' );
		$actions = $this->pending( $id );
		$this->assertCount( 1, $actions );
		$this->assertSame( 20, reset( $actions )->get_priority() );
		$this->assertSame( 0, $this->maintenance->run(), 'A second run finds nothing to recover.' );

		ActionScheduler_QueueRunner::instance()->run();
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ), 'A worker that comes back alive resets the count.' );
	}

	public function test_a_poison_file_is_given_up_with_its_third_death() {
		$id = $this->silent_worker( AttachmentRepository::MAX_ATTEMPTS );

		$this->assertSame( 1, $this->maintenance->run() );

		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::FAILED, $row['state'] );
		$this->assertSame( 'max_attempts', $row['reason'] );
		$this->assertSame( array(), $this->pending( $id ) );
	}

	public function test_an_attempt_whose_action_is_pending_or_in_progress_is_not_recovered() {
		$pending = $this->silent_worker();
		$running = $this->silent_worker();
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $pending ), ConversionQueue::GROUP );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $running ), ConversionQueue::GROUP );
		ActionScheduler::store()->log_execution( $action );

		$this->assertSame( 0, $this->maintenance->run() );

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $pending ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $running ) );
	}

	public function test_a_sync_attempt_and_a_row_from_before_the_attempt_record_are_never_recovered() {
		global $wpdb;

		$sync   = $this->silent_worker( 1, AttachmentRepository::SOURCE_SYNC );
		$legacy = $this->silent_worker();
		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET attempt_source = NULL, attempt_token = NULL WHERE attachment_id = %d", $legacy ) );

		$this->assertSame( 0, $this->maintenance->run() );

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $sync ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $legacy ) );
	}

	public function test_an_attempt_inside_a_publication_is_not_recovered() {
		$id = $this->silent_worker();
		$this->attachments->take_lease( $id, $this->attachments->get( $id )['attempt_token'] );

		$this->assertSame( 0, $this->maintenance->run() );

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
	}

	public function test_recovery_is_limited_to_a_batch_of_the_oldest_attempts() {
		$ids = array();
		for ( $i = 0; $i < JobHealth::BATCH_SIZE + 2; $i++ ) {
			$ids[] = $this->silent_worker();
		}

		$this->assertSame( JobHealth::BATCH_SIZE, Plugin::get_instance()->job_health->recover_attempts() );
		$this->assertSame( 2, Plugin::get_instance()->job_health->recover_attempts() );
	}

	public function test_nothing_is_recovered_when_action_scheduler_cannot_answer() {
		global $wp_actions, $wpdb;

		$id = $this->silent_worker();

		$initialised = $wp_actions['action_scheduler_init'];
		unset( $wp_actions['action_scheduler_init'] );
		try {
			$this->assertSame( 0, $this->maintenance->run(), 'Not initialised: its queries return nothing.' );
		} finally {
			$wp_actions['action_scheduler_init'] = $initialised;
		}

		$break = static function ( $query ) {
			return false !== strpos( $query, 'actionscheduler_actions' ) ? 'SELECT * FROM trust_optimize_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );
		try {
			$this->assertSame( 0, $this->maintenance->run(), 'A failed query is not an empty answer.' );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $break );
		}

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
	}

	public function test_a_death_is_remembered_by_the_unfinished_bulk_job() {
		$jobs = new BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$id = $this->silent_worker( 2 );
		$this->attachments->assign_job( $id, $job->get_id() );
		$jobs->update( $job->get_id(), array( 'last_error' => 'an earlier text' ) );
		global $wpdb;
		$wpdb->update( ( new DatabaseManager() )->get_plugin_table_names()['jobs'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ), array( 'id' => $job->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$before = $jobs->get( $job->get_id() )->to_array()['updated_at'];

		$this->maintenance->run();

		$after = $jobs->get( $job->get_id() )->to_array();
		$this->assertSame( sprintf( 'attachment %d, death 2 of 3', $id ), $after['last_error'], 'The text is replaced, not appended.' );
		$this->assertSame( $before, $after['updated_at'] );
	}

	public function test_a_dead_attempt_of_a_paused_job_is_parked_and_nothing_is_scheduled() {
		$jobs = new BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$jobs->pause( $job->get_id() );
		$id = $this->silent_worker();
		$this->attachments->assign_job( $id, $job->get_id() );

		$this->maintenance->run();

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::NONE, 'paused' ), array( $row['state'], $row['reason'] ) );
		$this->assertSame( array(), $this->pending( $id ) );
	}

	public function test_a_queued_attachment_without_a_task_is_queued_again() {
		$id = $this->stuck( AttachmentState::QUEUED );

		$this->maintenance->run();

		$this->assertCount( 1, $this->pending( $id ) );
	}

	public function test_an_attachment_with_a_task_is_left_alone() {
		$id = $this->stuck( AttachmentState::QUEUED );
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $id ), ConversionQueue::GROUP );

		$this->assertSame( 0, $this->maintenance->run() );
		$this->assertCount( 1, $this->pending( $id ) );
	}

	public function test_recent_work_is_left_alone() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );

		$this->assertSame( 0, $this->maintenance->run() );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
	}

	public function test_the_task_is_scheduled_once() {
		Maintenance::schedule();
		Maintenance::schedule();

		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Maintenance::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		Maintenance::unschedule();
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Maintenance::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
}
