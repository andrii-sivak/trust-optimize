<?php
/**
 * One Action Scheduler action per attachment (a partial result is stored).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\ConversionQueue
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 */
class ConversionQueueTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
	}

	private function pending_actions( $attachment_id = null ) {
		$args = array(
			'hook'   => ConversionQueue::HOOK_PROCESS,
			'status' => ActionScheduler_Store::STATUS_PENDING,
		);
		if ( null !== $attachment_id ) {
			$args['args'] = array( 'attachment_id' => $attachment_id );
		}

		return as_get_scheduled_actions( $args, 'ids' );
	}

	private function run_queue() {
		ActionScheduler_QueueRunner::instance()->run();
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	public function test_upload_is_planned_queued_and_optimized_by_one_action() {
		$id = $this->upload();

		$this->assertCount( 1, $this->pending_actions( $id ), 'One action per attachment, not one per variant.' );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $id ) );

		$this->run_queue();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$done = $this->variants->get_servable_for_attachment( $id );
		$this->assertNotEmpty( $done );
		foreach ( $done as $row ) {
			$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $row['relative_path'] );
		}
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_enqueueing_twice_does_not_duplicate_the_action() {
		$id    = $this->upload();
		$queue = $this->queue();

		$this->assertFalse( $queue->enqueue( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ) );
	}

	/**
	 * Regression: one failing variant must leave the attachment "partial", not stuck.
	 */
	public function test_one_failed_variant_gives_partial() {
		$id = $this->upload();
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			if ( 'thumbnail' === $row['size_name'] ) {
				unlink( wp_upload_dir()['basedir'] . '/' . $row['source_relative_path'] );
			}
		}

		$this->run_queue();

		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->get_state( $id ) );
		$attachment = $this->attachments->get( $id );
		$this->assertSame( 'missing_file', $attachment['reason'] );
		$this->assertStringContainsString( 'Source file is missing', $attachment['last_error'] );
		$statuses = array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) );
		sort( $statuses );
		$this->assertSame( array( VariantStatus::DONE, VariantStatus::FAILED ), $statuses );
	}

	public function test_exhausted_time_budget_leaves_the_rest_pending_and_reschedules() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		add_filter( 'trust_optimize_worker_time_budget', '__return_zero' );

		$this->queue()->process( $id );

		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $id ) );
		$this->assertSame( array(), $this->variants->get_servable_for_attachment( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ), 'The run continues in a new action.' );
	}

	public function test_cancel_removes_the_pending_action() {
		$id = $this->upload();

		ConversionQueue::cancel_tasks_for_attachment( $id );

		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	/**
	 * Priorities of the pending conversion actions of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int[]
	 */
	private function priorities( $attachment_id ) {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'attachment_id' => $attachment_id ),
			)
		);

		return array_values( array_map( static fn( $action ) => $action->get_priority(), $actions ) );
	}

	public function test_an_upload_is_queued_at_priority_20() {
		$id = $this->upload();

		$this->assertSame( array( 20 ), $this->priorities( $id ) );
	}

	public function test_a_continuation_after_the_time_budget_is_queued_at_priority_20() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		add_filter( 'trust_optimize_worker_time_budget', '__return_zero' );

		$this->queue()->process( $id );

		$this->assertSame( array( 20 ), $this->priorities( $id ) );
	}

	public function test_a_requeued_attachment_is_queued_at_priority_20() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		global $wpdb;
		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id ) );

		$this->assertTrue( $this->queue()->requeue( $id ) );

		$this->assertSame( array( 20 ), $this->priorities( $id ) );
	}

	/**
	 * Store an attempt count on a row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $attempts      Attempts.
	 */
	private function set_attempts( $attachment_id, $attempts ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = %d WHERE attachment_id = %d", $attempts, $attachment_id ) );
	}

	public function test_a_late_action_on_a_poison_file_converts_and_schedules_nothing() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts' );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );
		$row      = $this->attachments->get( $id );
		$variants = $this->variants->get_for_attachment( $id );

		$this->queue()->process( $id );

		$this->assertSame( $row, $this->attachments->get( $id ) );
		$this->assertSame( $variants, $this->variants->get_for_attachment( $id ) );
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_a_duplicate_action_during_the_third_attempt_leaves_the_attempt_to_its_worker() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );
		$row      = $this->attachments->get( $id );
		$variants = $this->variants->get_for_attachment( $id );

		$this->queue()->process( $id );

		$this->assertSame( $row, $this->attachments->get( $id ), 'Still processing, attempts 3, same token.' );
		$this->assertSame( $token, $this->attachments->get( $id )['attempt_token'] );
		$this->assertSame( $variants, $this->variants->get_for_attachment( $id ) );
		$this->assertSame( array(), $this->pending_actions( $id ) );

		$this->assertTrue( $this->attachments->finish( $id, $token, AttachmentState::OPTIMIZED ), 'The worker\'s closing write lands.' );
		$this->assertSame( array( AttachmentState::OPTIMIZED, 0 ), array( $this->attachments->get_state( $id ), $this->attachments->get_attempts( $id ) ) );
	}

	public function test_a_queued_file_with_all_its_attempts_used_is_given_up_by_its_action() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );
		// An orphan left by an attempt that is gone: nobody holds the row.
		$orphan = $this->variants->get_for_attachment( $id )[0];
		$this->variants->transition( $orphan['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );

		$this->queue()->process( $id );

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::FAILED, 'max_attempts', 3 ), array( $row['state'], $row['reason'], $row['attempts'] ) );
		$this->assertStringContainsString( '(3)', $row['last_error'] );
		$statuses = array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) );
		$this->assertSame( array( VariantStatus::FAILED ), $statuses, 'No pending variant is left, so a bulk job can complete.' );
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_a_queued_file_of_a_paused_job_with_all_its_attempts_used_is_given_up_not_parked() {
		$id   = $this->upload();
		$jobs = new \TrustOptimize\Bulk\BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$this->attachments->assign_job( $id, $job->get_id() );
		$this->assertTrue( $jobs->pause( $job->get_id() ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );

		$this->queue()->process( $id );

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::FAILED, 'max_attempts' ), array( $row['state'], $row['reason'] ), 'Parked, it would be refused after Resume and hold its job open.' );
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	/**
	 * States a worker stored as its result.
	 *
	 * @return array[]
	 */
	public static function result_states() {
		return array(
			'optimized' => array( AttachmentState::OPTIMIZED ),
			'partial'   => array( AttachmentState::PARTIAL ),
			'skipped'   => array( AttachmentState::SKIPPED ),
		);
	}

	/**
	 * @dataProvider result_states
	 *
	 * @param string $state Stored state.
	 */
	public function test_a_late_action_never_turns_a_result_into_a_failure_because_of_the_count( $state ) {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, $state );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );
		$row      = $this->attachments->get( $id );
		$variants = $this->variants->get_for_attachment( $id );

		$this->queue()->process( $id );

		$this->assertSame( $row, $this->attachments->get( $id ) );
		$this->assertSame( $variants, $this->variants->get_for_attachment( $id ) );
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_a_sync_converts_a_file_that_used_all_its_attempts() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts' );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );

		$result = \TrustOptimize\Core\Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_replaced_source_gives_a_poison_file_its_attempts_back() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'max_attempts', 'source_file_size' => 1 ) );
		}
		$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts' );
		$this->set_attempts( $id, AttachmentRepository::MAX_ATTEMPTS );

		$this->assertTrue( $this->queue()->plan_and_enqueue( $id ) );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
		$this->run_queue();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	/**
	 * The queue registered by the plugin on boot.
	 *
	 * @return ConversionQueue
	 */
	private function queue() {
		return \TrustOptimize\Core\Plugin::get_instance()->conversion_queue;
	}
}
