<?php
/**
 * wp trust-optimize release: inspection changes nothing, a confirmed release frees exactly the attempt that was shown.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Bulk\JobProgress;
use TrustOptimize\CLI\Command;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * The part of WP-CLI the command talks to, recording instead of printing.
	 */
	class WP_CLI {

		/**
		 * Lines logged so far.
		 *
		 * @var string[]
		 */
		public static $lines = array();

		public static function log( $message ) {
			self::$lines[] = $message;
		}

		public static function success( $message ) {
			self::$lines[] = 'Success: ' . $message;
		}

		public static function error( $message ) {
			throw new RuntimeException( $message );
		}
	}
}

/**
 * @covers \TrustOptimize\CLI\Command::release
 * @covers \TrustOptimize\Bulk\JobHealth::release
 */
class ReleaseCommandTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Command
	 */
	private $command;

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
		WP_CLI::$lines = array();

		$database          = new DatabaseManager();
		$plugin            = Plugin::get_instance();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$eligibility       = new EligibilityQuery( $this->variants );
		$this->command     = new Command( new BulkJobRepository( $database ), $eligibility, $plugin->inventory, new JobProgress( $this->attachments, $eligibility ), $plugin->bulk_producer, $plugin->processor, $plugin->cleanup, $plugin->job_health, $database );
	}

	private function age( $id, $seconds ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - $seconds ), $id ) );
	}

	/**
	 * Run the command and return what it said, or the message of its error.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return string
	 */
	private function run_release( array $args, array $assoc_args = array() ) {
		WP_CLI::$lines = array();

		try {
			$this->command->release( $args, $assoc_args );
		} catch ( RuntimeException $error ) {
			return 'Error: ' . $error->getMessage();
		}

		return implode( "\n", WP_CLI::$lines );
	}

	/**
	 * The options the inspection tells to confirm with.
	 *
	 * @param string $output Output of the inspection.
	 * @return array
	 */
	private function confirmation_options( $output ) {
		$this->assertSame( 1, preg_match( '/--attempt=(\S+) --since="([^"]+)" --lease="([^"]+)"/', $output, $found ), $output );

		return array(
			'attempt' => $found[1],
			'since'   => $found[2],
			'lease'   => $found[3],
		);
	}

	public function test_inspection_shows_the_attempt_and_changes_nothing() {
		$token = $this->attachments->claim( 1001, AttachmentRepository::SOURCE_SYNC );
		$this->age( 1001, 3000 );
		$before = $this->attachments->get( 1001 );

		$output = $this->run_release( array( '1001' ) );

		$this->assertStringContainsString( 'source:   sync', $output );
		$this->assertStringContainsString( 'attempt:  ' . $token, $output );
		$this->assertStringContainsString( 'since:    ' . $before['updated_at'], $output );
		$this->assertStringContainsString( 'lease:    none', $output );
		$this->assertStringContainsString( 'the age of the record does not prove it', $output );
		$this->assertSame( $before, $this->attachments->get( 1001 ) );
	}

	public function test_a_confirmed_release_frees_the_attachment_without_touching_a_file() {
		$this->attachments->claim( 1002, AttachmentRepository::SOURCE_SYNC );
		$this->variants->upsert(
			array(
				'attachment_id' => 1002,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
			)
		);
		$this->age( 1002, 3000 );
		$options = $this->confirmation_options( $this->run_release( array( '1002' ) ) );

		$output = $this->run_release( array( '1002' ), $options );

		$this->assertStringContainsString( 'Success', $output );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( 1002 ) );
		$this->assertSame( VariantStatus::PENDING, $this->variants->get_for_attachment( 1002 )[0]['status'] );
	}

	public function test_a_publication_incomplete_names_the_variant_and_is_released_to_pending() {
		$token = $this->attachments->claim( 1003, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( 1003, $token );
		$this->variants->upsert(
			array(
				'attachment_id' => 1003,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
				'relative_path' => '2026/11/photo.jpg.webp',
				'file_hash'     => 'old',
			)
		);
		$this->age( 1003, 3000 );
		$output = $this->run_release( array( '1003' ) );
		$this->assertStringContainsString( 'variant:  2026/11/photo.jpg.webp', $output );
		$this->assertStringNotContainsString( 'lease:    none', $output );

		$this->assertStringContainsString( 'Success', $this->run_release( array( '1003' ), $this->confirmation_options( $output ) ) );

		$row = $this->variants->get_for_attachment( 1003 )[0];
		$this->assertSame( array( VariantStatus::PENDING, '2026/11/photo.jpg.webp', 'old' ), array( $row['status'], $row['relative_path'], $row['file_hash'] ) );
		$this->assertNull( $this->attachments->get( 1003 )['publishing_at'] );
	}

	public function test_the_third_attempt_is_released_as_given_up() {
		global $wpdb;

		$this->attachments->claim( 1004, AttachmentRepository::SOURCE_SYNC );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = 3 WHERE attachment_id = %d", 1004 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->variants->upsert(
			array(
				'attachment_id' => 1004,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
			)
		);
		$this->age( 1004, 3000 );

		$this->run_release( array( '1004' ), $this->confirmation_options( $this->run_release( array( '1004' ) ) ) );

		$this->assertSame( array( AttachmentState::FAILED, 'max_attempts' ), array( $this->attachments->get( 1004 )['state'], $this->attachments->get( 1004 )['reason'] ) );
		$this->assertSame( VariantStatus::FAILED, $this->variants->get_for_attachment( 1004 )[0]['status'] );
	}

	public function test_a_row_from_before_the_attempt_record_is_released_as_legacy() {
		global $wpdb;

		$this->attachments->claim( 1005, AttachmentRepository::SOURCE_QUEUE );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempt_source = NULL, attempt_token = NULL WHERE attachment_id = %d", 1005 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->age( 1005, 3000 );
		$output = $this->run_release( array( '1005' ) );

		$this->assertStringContainsString( 'attempt:  legacy', $output );
		$options = $this->confirmation_options( $output );
		$this->assertSame( 'legacy', $options['attempt'] );
		$this->assertStringContainsString( 'Success', $this->run_release( array( '1005' ), $options ) );
	}

	public function test_a_release_misses_when_the_attempt_changed_since_the_inspection() {
		$this->attachments->claim( 1006, AttachmentRepository::SOURCE_SYNC );
		$this->age( 1006, 3000 );
		$options = $this->confirmation_options( $this->run_release( array( '1006' ) ) );

		// A worker wrote to the attachment (old code included): the age differs from the one that was shown.
		$this->age( 1006, 2900 );
		$this->assertStringContainsString( 'inspect again', $this->run_release( array( '1006' ), $options ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 1006 ) );

		// A live worker took a lease after the inspection.
		$options = $this->confirmation_options( $this->run_release( array( '1006' ) ) );
		$token   = $this->attachments->get( 1006 )['attempt_token'];
		$this->attachments->take_lease( 1006, $token );
		$this->assertStringContainsString( 'inspect again', $this->run_release( array( '1006' ), $options ) );

		// Another attempt altogether.
		$this->assertStringContainsString( 'inspect again', $this->run_release( array( '1006' ), array( 'attempt' => str_repeat( 'a', 32 ), 'since' => $this->attachments->get( 1006 )['updated_at'], 'lease' => $this->attachments->get( 1006 )['publishing_at'] ) ) );
	}

	public function test_a_young_attempt_is_not_released() {
		$this->attachments->claim( 1007, AttachmentRepository::SOURCE_SYNC );
		$options = $this->confirmation_options( $this->run_release( array( '1007' ) ) );

		$this->assertStringContainsString( 'may still be running', $this->run_release( array( '1007' ), $options ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 1007 ) );
	}

	public function test_a_queue_attempt_that_action_scheduler_still_holds_is_not_released() {
		$this->attachments->claim( 1008, AttachmentRepository::SOURCE_QUEUE );
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1008 ), ConversionQueue::GROUP );
		$this->age( 1008, 3000 );
		$options = $this->confirmation_options( $this->run_release( array( '1008' ) ) );

		$this->assertStringContainsString( 'Action Scheduler still holds it; let its runner run', $this->run_release( array( '1008' ), $options ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 1008 ) );
	}

	public function test_an_attachment_nothing_holds_and_a_pending_schema_are_refused() {
		$this->assertStringContainsString( 'No conversion attempt holds attachment 1009', $this->run_release( array( '1009' ) ) );

		update_option( 'trust_optimize_db_version', '1.1.0' );
		$this->assertStringContainsString( 'database update has not finished', $this->run_release( array( '1009' ) ) );
	}
}
