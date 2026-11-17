<?php
/**
 * Conversion waits for the database update to 1.2.0 and spends no attempt meanwhile.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Notices;
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
 * @covers \TrustOptimize\Queue\ConversionQueue::process
 * @covers \TrustOptimize\Processing\AttachmentProcessor::sync
 * @covers \TrustOptimize\Bulk\BulkProducer::launch
 * @covers \TrustOptimize\Bulk\BulkProducer::resume
 */
class SchemaPendingTest extends WP_UnitTestCase {

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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
	}

	private function pending_schema() {
		update_option( 'trust_optimize_db_version', '1.1.0' );
	}

	public function test_a_queued_action_waits_for_the_update_without_claiming_or_spending_an_attempt() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$before = $this->attachments->get( $id );
		$this->pending_schema();

		Plugin::get_instance()->conversion_queue->process( $id );

		$this->assertSame( $before, $this->attachments->get( $id ), 'No claim, no attempt, no state change.' );
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'attachment_id' => $id ),
			)
		);
		$this->assertCount( 1, $actions );
		$action = reset( $actions );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $action->get_schedule()->get_date()->getTimestamp(), 5 );
		$this->assertSame( 20, $action->get_priority() );
	}

	public function test_a_sync_answers_schema_pending_before_any_write() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->pending_schema();
		$before = $this->attachments->get( $id );

		$result   = Plugin::get_instance()->processor->sync( $id );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/image/' . $id . '/sync' ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'schema_pending', $result->get_message() );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'trust_optimize_schema_pending', $response->as_error()->get_error_code() );
		$this->assertSame( $before, $this->attachments->get( $id ) );
	}

	public function test_starting_and_resuming_a_bulk_job_answer_schema_pending() {
		$producer = Plugin::get_instance()->bulk_producer;
		$jobs     = new BulkJobRepository( new DatabaseManager() );
		$job      = $producer->launch( BulkJob::TYPE_SYNC );
		$producer->pause( $job->get_id() );
		$this->pending_schema();

		$resume = $producer->resume( $job->get_id() );
		$start  = rest_do_request( new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/resume' ) );

		$this->assertWPError( $resume );
		$this->assertSame( 'trust_optimize_schema_pending', $resume->get_error_code() );
		$this->assertSame( 'trust_optimize_schema_pending', $start->as_error()->get_error_code() );
		$this->assertSame( JobStatus::PAUSED, $jobs->get( $job->get_id() )->get_status() );

		$jobs->cancel( $job->get_id() );
		$request  = new WP_REST_Request( 'POST', '/trust-optimize/v1/bulk/start' );
		$request->set_param( 'type', 'sync' );
		$launched = rest_do_request( $request );

		$this->assertSame( 'trust_optimize_schema_pending', $launched->as_error()->get_error_code() );
		$this->assertNull( $jobs->get_active_job() );
	}

	public function test_a_pause_still_records_its_reason_on_the_old_schema() {
		$producer = Plugin::get_instance()->bulk_producer;
		$jobs     = new BulkJobRepository( new DatabaseManager() );
		$job      = $producer->launch( BulkJob::TYPE_SYNC );
		$this->pending_schema();

		$this->assertTrue( $producer->pause( $job->get_id() ) );

		$this->assertSame( PauseReason::USER, $jobs->get( $job->get_id() )->to_array()['pause_reason'] );
	}

	public function test_the_notice_says_that_the_update_has_not_finished() {
		$jobs    = new BulkJobRepository( new DatabaseManager() );
		$notices = new Notices( $jobs, Plugin::get_instance()->bulk_producer, new CapabilityService(), new DatabaseManager(), Plugin::get_instance()->job_health );
		set_current_screen( 'plugins' );

		ob_start();
		$notices->render();
		$this->assertStringNotContainsString( 'database update', ob_get_clean() );

		$this->pending_schema();
		ob_start();
		$notices->render();

		$this->assertStringContainsString( 'The database update has not finished', ob_get_clean() );
	}
}
