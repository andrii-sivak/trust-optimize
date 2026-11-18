<?php
/**
 * Admin notices (06.7).
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
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Admin\Notices
 */
class NoticesTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database      = new DatabaseManager();
		$this->jobs    = new BulkJobRepository( $database );
		$this->notices = new Notices( $this->jobs, Plugin::get_instance()->bulk_producer, new CapabilityService(), $database, Plugin::get_instance()->job_health );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
	}

	private function html() {
		ob_start();
		$this->notices->render();

		return ob_get_clean();
	}

	/**
	 * Run an admin-post handler and return where it redirected.
	 *
	 * @param string $handler Method of Notices.
	 * @param string $action  Nonce action.
	 * @param array  $get     Query arguments.
	 * @return string
	 */
	private function request( $handler, $action, array $get = array() ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_GET                 = $get;
		add_filter(
			'wp_redirect',
			static function ( $url ) {
				throw new RuntimeException( $url );
			}
		);

		try {
			$this->notices->$handler();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	private function downgrade( $at ) {
		( new CapabilityService() )->downgrade( 'avif', 'imageavif() failed' );

		$stored                          = get_option( CapabilityService::OPTION );
		$stored['downgraded']['avif']['at'] = $at;
		update_option( CapabilityService::OPTION, $stored );
	}

	public function test_nothing_without_news() {
		$this->assertSame( '', $this->html() );
	}

	public function test_nothing_for_users_who_cannot_manage_options() {
		$this->downgrade( '2026-10-01 10:00:00' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_job_paused_for_disk_space_offers_resume_and_resumes() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'low_disk_space' );

		$html = $this->html();

		$this->assertStringContainsString( 'disk is almost full', $html );
		$this->assertStringContainsString( 'Resume', $html );
		$this->assertStringNotContainsString( 'Dismiss', $html );

		$this->request( 'handle_resume', Notices::ACTION_RESUME );

		$this->assertSame( JobStatus::RUNNING, $this->jobs->get( $job->get_id() )->get_status() );
		$this->assertSame( '', $this->html() );
	}

	public function test_a_job_paused_for_disk_space_before_the_migration_still_shows_the_notice() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		update_option( 'trust_optimize_db_version', '1.0.0' );

		try {
			$this->jobs->pause( $job->get_id(), 'low_disk_space' );

			$this->assertStringContainsString( 'disk is almost full', $this->html() );
		} finally {
			update_option( 'trust_optimize_db_version', DatabaseManager::DB_VERSION );
		}
	}

	public function test_a_job_the_user_paused_has_no_notice() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id() );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_job_paused_for_another_reason_names_it_shows_the_error_and_links_to_bulk_optimization() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'stopped' );
		$this->jobs->record_death( $job->get_id(), 'attachment 7, death 2 of 3' );

		$html = $this->html();

		$this->assertStringContainsString( 'the job stopped responding', $html );
		$this->assertStringContainsString( 'attachment 7, death 2 of 3', $html );
		$this->assertStringContainsString( 'page=trust-optimize#bulk', $html );
		$this->assertStringContainsString( 'Resume', $html );
		$this->assertStringNotContainsString( 'Dismiss', $html );
	}

	public function test_a_paused_job_without_a_known_cause_says_so() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'unknown' );

		$this->assertStringContainsString( 'The cause is not known', $this->html() );
	}

	public function test_a_job_paused_for_an_unknown_reason_shows_the_text_a_migration_kept() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'unknown' );
		$this->jobs->record_death( $job->get_id(), 'Bulk job recovered from stale running state' );

		$html = $this->html();

		$this->assertStringContainsString( 'Last error: Bulk job recovered from stale running state', $html );
		$this->assertStringNotContainsString( 'The cause is not known', $html );
	}

	public function test_a_failed_resume_shows_its_error_on_the_next_page_once() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'stopped' );
		$low = static function () {
			return PHP_INT_MAX;
		};
		add_filter( 'trust_optimize_min_free_disk_bytes', $low );

		try {
			$this->request( 'handle_resume', Notices::ACTION_RESUME );
		} finally {
			remove_filter( 'trust_optimize_min_free_disk_bytes', $low );
		}

		$this->assertSame( JobStatus::PAUSED, $this->jobs->get( $job->get_id() )->get_status() );
		$this->assertStringContainsString( 'The disk is almost full', $this->html() );
		$this->assertStringNotContainsString( 'The disk is almost full', $this->html(), 'The error is shown once.' );
	}

	public function test_the_queue_warning_comes_first_and_a_running_job_gets_no_button() {
		Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		global $wpdb;
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'status' => 'in-progress', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$html = $this->html();

		$this->assertStringContainsString( 'more than 15 minutes', $html );
		$this->assertStringContainsString( 'Action Scheduler may not be running', $html );
		$this->assertStringNotContainsString( 'Resume', $html );
		$this->assertStringNotContainsString( 'Dismiss', $html );
	}

	public function test_attachments_held_by_a_conversion_get_instructions_and_no_button() {
		global $wpdb;

		$attachments = new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), new TrustOptimize\Storage\VariantRepository( new DatabaseManager() ) );
		$token       = $attachments->claim( 5001, TrustOptimize\Storage\AttachmentRepository::SOURCE_QUEUE );
		$attachments->take_lease( 5001, $token );
		( new TrustOptimize\Storage\VariantRepository( new DatabaseManager() ) )->upsert(
			array(
				'attachment_id' => 5001,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => 'processing',
				'relative_path' => '2026/11/held.jpg.webp',
			)
		);
		$attachments->claim( 5002, TrustOptimize\Storage\AttachmentRepository::SOURCE_SYNC );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id IN (5001, 5002)", gmdate( 'Y-m-d H:i:s', time() - 5000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET publishing_at = %s WHERE attachment_id = 5001", gmdate( 'Y-m-d H:i:s', time() - 5000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$html = $this->html();

		$this->assertStringContainsString( 'The publication of the optimized files of attachment 5001 did not finish', $html );
		$this->assertStringContainsString( '2026/11/held.jpg.webp may already be the new version while its record keeps the previous hash', $html );
		$this->assertStringContainsString( 'target_exists_foreign', $html );
		$this->assertStringContainsString( 'A synchronous conversion of attachment 5002', $html );
		$this->assertStringContainsString( 'wp trust-optimize release 5001', $html );
		$this->assertStringContainsString( 'the age of the record does not prove it', $html );
		$this->assertStringNotContainsString( 'Resume', $html );
		$this->assertStringNotContainsString( 'Dismiss', $html );
	}

	public function test_a_format_downgrade_is_shown_and_a_new_one_shows_again_after_dismissal() {
		$this->downgrade( '2026-10-01 10:00:00' );

		$html = $this->html();
		$this->assertStringContainsString( 'AVIF files are no longer created', $html );
		$this->assertStringContainsString( 'imageavif() failed', $html );

		$this->request( 'handle_dismiss', Notices::ACTION_DISMISS, array( 'notice' => 'downgrade:avif:2026-10-01 10:00:00' ) );
		$this->assertSame( '', $this->html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'AVIF files are no longer created', $this->html() );

		$this->downgrade( '2026-10-02 10:00:00' );
		$this->assertStringContainsString( 'AVIF files are no longer created', $this->html() );
	}

	public function test_the_queue_and_held_texts_come_before_the_paused_job() {
		global $wpdb;

		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), 'stopped' );
		$action = as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );
		$wpdb->update( $wpdb->actionscheduler_actions, array( 'status' => 'in-progress', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2000 ) ), array( 'action_id' => $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attachments = new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), new TrustOptimize\Storage\VariantRepository( new DatabaseManager() ) );
		$attachments->claim( 5003, TrustOptimize\Storage\AttachmentRepository::SOURCE_SYNC );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = 5003", gmdate( 'Y-m-d H:i:s', time() - 5000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$html = $this->html();

		$queue  = strpos( $html, 'more than 15 minutes' );
		$held   = strpos( $html, 'attachment 5003' );
		$paused = strpos( $html, 'The bulk job is paused' );
		$this->assertNotFalse( $queue );
		$this->assertNotFalse( $held );
		$this->assertNotFalse( $paused );
		$this->assertLessThan( $paused, $queue );
		$this->assertLessThan( $paused, $held );
	}
}
