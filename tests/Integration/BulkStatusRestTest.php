<?php
/**
 * GET requests of the bulk API never change anything (04.6).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\API\RestController::get_bulk_status
 */
class BulkStatusRestTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
	}

	/**
	 * Everything a status request could touch.
	 *
	 * @return array
	 */
	private function snapshot() {
		global $wpdb;

		$tables = ( new DatabaseManager() )->get_plugin_table_names();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array(
			$wpdb->get_results( "SELECT * FROM `{$tables['jobs']}` ORDER BY id", ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM `{$tables['attachments']}` ORDER BY attachment_id", ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM `{$tables['variants']}` ORDER BY id", ARRAY_A ),
			as_get_scheduled_actions( array( 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ),
		);
		// phpcs:enable
	}

	public function test_polling_a_running_job_changes_nothing_and_converts_nothing() {
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$before = $this->snapshot();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $job->get_id(), (int) $response->get_data()['job']['id'] );
		$this->assertSame( JobStatus::RUNNING, $response->get_data()['job']['status'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_polling_does_not_pause_an_abandoned_job() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'trust_optimize_jobs',
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'id' => $job->get_id() )
		);
		$before = $this->snapshot();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) );

		$this->assertSame( JobStatus::RUNNING, $response->get_data()['job']['status'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_status_reports_derived_counters() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$producer = Plugin::get_instance()->bulk_producer;
		$job      = $producer->launch( BulkJob::TYPE_SYNC );
		$producer->produce( $job->get_id() );
		ActionScheduler_QueueRunner::instance()->run();

		$data = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) )->get_data()['job'];

		$this->assertGreaterThanOrEqual( 1, $data['processed'] );
		$this->assertSame( 0, $data['failed_count'] );
		$this->assertNotEmpty( $id );
	}

	public function test_the_job_carries_the_queue_signals() {
		global $wpdb;

		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) )->get_data()['job'];
		$this->assertSame( array(), $data['queue_signals'] );

		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'status' => 'in-progress', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$data = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) )->get_data()['job'];
		$this->assertSame( array( 'stuck_claim' ), $data['queue_signals'] );
		$this->assertSame( $job->get_id(), (int) $data['id'] );
	}

	public function test_resume_succeeds_with_the_signals_while_the_queue_is_stuck() {
		global $wpdb;

		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/pause' ) );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'status' => 'in-progress', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$response = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/resume' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( JobStatus::RUNNING, $response->get_data()['job']['status'] );
		$this->assertSame( array( 'stuck_claim' ), $response->get_data()['job']['queue_signals'] );
	}

	public function test_a_failed_resume_and_a_failed_start_are_errors() {
		$refuse = static function () {
			return 0;
		};
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		try {
			$request = new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/start' );
			$request->set_param( 'type', 'sync' );
			$start = rest_do_request( $request );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}

		$this->assertSame( 'trust_optimize_start_failed', $start->as_error()->get_error_code() );

		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/pause' ) );
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		try {
			$resume = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/resume' ) );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}

		$this->assertSame( 'trust_optimize_resume_failed', $resume->as_error()->get_error_code() );
	}

	public function test_a_resume_on_a_full_disk_is_refused() {
		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/pause' ) );
		$low = static function () {
			return PHP_INT_MAX;
		};
		add_filter( 'trust_optimize_min_free_disk_bytes', $low );

		try {
			$resume = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/resume' ) );
		} finally {
			remove_filter( 'trust_optimize_min_free_disk_bytes', $low );
		}

		$this->assertSame( 'trust_optimize_disk_low', $resume->as_error()->get_error_code() );
		$this->assertSame( JobStatus::PAUSED, ( new TrustOptimize\Bulk\BulkJobRepository( new DatabaseManager() ) )->get_active_job()->get_status() );
	}

	public function test_a_sync_of_a_held_attachment_answers_busy_with_the_source_and_the_time() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$attachments = new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), new TrustOptimize\Storage\VariantRepository( new DatabaseManager() ) );
		$attachments->claim( $id, TrustOptimize\Storage\AttachmentRepository::SOURCE_SYNC );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/image/' . $id . '/sync' ) );

		$this->assertSame( 409, $response->get_status() );
		$error = $response->as_error();
		$this->assertSame( 'trust_optimize_attachment_busy', $error->get_error_code() );
		$this->assertSame( 'sync', $error->get_error_data()['source'] );
		$this->assertSame( $attachments->get( $id )['updated_at'], $error->get_error_data()['since'] );
	}
}
