<?php
/**
 * An attempt owns its attachment: nothing else writes its rows, and nothing replaces a served file without its lease.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Naming\VariantNaming;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * A writer that runs a callback between the temporary file and the move into place.
 */
class Interrupted_Writer extends AtomicImageWriter {

	/**
	 * Callback.
	 *
	 * @var callable|null
	 */
	public $between;

	public function write_temporary( WP_Image_Editor $editor, $target_path, $mime ) {
		$saved = parent::write_temporary( $editor, $target_path, $mime );

		if ( null !== $this->between && ! is_wp_error( $saved ) ) {
			$between       = $this->between;
			$this->between = null;
			$between();
		}

		return $saved;
	}
}

/**
 * @covers \TrustOptimize\Features\Optimization\ImageConverter
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 * @covers \TrustOptimize\Queue\ConversionQueue::process
 */
class AttemptSafetyTest extends WP_UnitTestCase {

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

	/**
	 * Writer.
	 *
	 * @var Interrupted_Writer
	 */
	private $writer;

	/**
	 * Converter that uses the writer.
	 *
	 * @var ImageConverter
	 */
	private $converter;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->writer      = new Interrupted_Writer( new FileOwnership( $this->variants ) );
		$this->converter   = new ImageConverter( $this->variants, $this->writer, new CapabilityService(), $this->attachments );
	}

	private function settings() {
		return new OptimizationSettings( array( 'webp' ), array( 'webp' ), array( 'webp' => 80 ) );
	}

	/**
	 * An uploaded attachment whose planned variants are pending and not queued.
	 *
	 * @return int
	 */
	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, AttachmentState::NONE );

		return $id;
	}

	/**
	 * Break the queries that contain a text, once the callback has been armed.
	 *
	 * @param string $needle Text of the query to break.
	 * @return callable Call it to stop breaking.
	 */
	private function break_queries( $needle ) {
		global $wpdb;

		$break = static function ( $query ) use ( $needle ) {
			return false !== strpos( $query, $needle ) ? 'SELECT * FROM trust_optimize_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		return static function () use ( $break, $wpdb ) {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( false );
		};
	}

	private function served_hash( $relative ) {
		return hash_file( 'sha256', wp_upload_dir()['basedir'] . '/' . $relative );
	}

	private function leftovers( $id ) {
		$dir = dirname( wp_upload_dir()['basedir'] . '/' . $this->variants->get_for_attachment( $id )[0]['source_relative_path'] );

		return glob( $dir . '/*.tmp-*' ) ?: array();
	}

	public function test_a_sync_of_a_held_attachment_answers_busy_before_any_side_effect() {
		$id    = $this->upload();
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$rows  = $this->variants->get_for_attachment( $id );
		$held  = $this->attachments->get( $id );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'busy', $result->get_message() );
		$this->assertSame( 'queue', $result->get_data()['source'] );
		$this->assertSame( $held['updated_at'], $result->get_data()['since'] );
		$this->assertSame( $held, $this->attachments->get( $id ), 'No attempts reset, no claim, no state change.' );
		$this->assertSame( $rows, $this->variants->get_for_attachment( $id ), 'No planning, no removals.' );
		$this->assertSame( $token, $this->attachments->get( $id )['attempt_token'] );
	}

	public function test_a_sync_runs_under_its_own_claim_and_ends_it() {
		$id = $this->upload();

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::OPTIMIZED, 0 ), array( $row['state'], $row['attempts'] ) );
		$this->assertSame( array( null, null, null ), array( $row['attempt_source'], $row['attempt_token'], $row['publishing_at'] ) );
	}

	public function test_a_skip_found_by_the_planner_lands_through_the_closing_write() {
		$id = $this->upload();
		wp_delete_file( get_attached_file( $id ) );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertSame( 'missing_file', $result->get_message() );
		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::SKIPPED, 'missing_file', null ), array( $row['state'], $row['reason'], $row['attempt_token'] ) );
	}

	public function test_a_variant_left_processing_by_a_dead_attempt_is_converted_by_the_next_one() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		$orphan = $this->variants->get_for_attachment( $id )[0];
		$this->variants->transition( $orphan['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );

		$result = Plugin::get_instance()->processor->run( $id, INF );

		$this->assertSame( AttachmentState::OPTIMIZED, $result->get_data()['state'] );
		$this->assertSame( array( VariantStatus::DONE ), array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) ) );
	}

	public function test_an_exception_ends_the_attempt_as_failed_and_is_rethrown() {
		$id        = $this->upload();
		$converter = new class( $this->variants, $this->writer, new CapabilityService(), $this->attachments ) extends ImageConverter {
			public function convert( array $variant_row, OptimizationSettings $settings, $token ) {
				throw new RuntimeException( 'converter blew up' );
			}
		};
		$plugin    = Plugin::get_instance();
		$processor = new AttachmentProcessor( $this->attachments, $this->variants, $converter, $plugin->planner, $plugin->cleanup, new TrustOptimize\Admin\Settings(), new CapabilityService(), new DatabaseManager() );

		try {
			$processor->sync( $id );
			$this->fail( 'The exception was expected.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'converter blew up', $e->getMessage() );
		}

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::FAILED, 'exception', 'converter blew up', null ), array( $row['state'], $row['reason'], $row['last_error'], $row['attempt_token'] ) );
	}

	public function test_a_late_worker_does_not_replace_the_file_a_newer_attempt_published() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		$row         = $this->variants->get_for_attachment( $id )[0];
		$first_token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$newer       = null;

		// While the first worker holds its temporary file it is recovered as dead, and a second attempt publishes.
		$this->writer->between = function () use ( $id, $first_token, $row, &$newer ) {
			$this->assertTrue( $this->attachments->recover_dead( $id, $first_token ) );
			$newer     = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
			$current   = $this->variants->get_for_attachment( $id )[0];
			$other     = new ImageConverter( $this->variants, new AtomicImageWriter( new FileOwnership( $this->variants ) ), new CapabilityService(), $this->attachments );
			$published = $other->convert( $current, $this->settings(), $newer );
			$this->assertTrue( $published->is_success(), wp_json_encode( $published->to_array() ) );
		};

		$result = $this->converter->convert( $row, $this->settings(), $first_token );
		$done   = $this->variants->get_for_attachment( $id )[0];

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'attempt_lost', $result->get_message() );
		$this->assertSame( VariantStatus::DONE, $done['status'] );
		$this->assertSame( $done['file_hash'], $this->served_hash( $done['relative_path'] ), 'The served file is the newer attempt\'s.' );
		$this->assertSame( array(), $this->leftovers( $id ), 'The late worker deleted its temporary file.' );
		$this->assertSame( $newer, $this->attachments->get( $id )['attempt_token'], 'The late worker changed nothing on the attachment.' );
		$this->assertNull( $this->attachments->get( $id )['publishing_at'] );
	}

	public function test_a_late_worker_ends_without_a_write_and_schedules_nothing() {
		$id    = $this->upload();
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->recover_dead( $id, $token );
		$this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
		$held = $this->attachments->get( $id );

		$this->assertFalse( $this->attachments->finish( $id, $token, AttachmentState::OPTIMIZED ) );
		$this->assertSame( $held, $this->attachments->get( $id ) );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}

	public function test_a_lease_that_cannot_be_checked_fails_the_variant_and_touches_no_file() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		$row   = $this->variants->get_for_attachment( $id )[0];
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );

		$stop = $this->break_queries( 'SET publishing_at' );
		try {
			$result = $this->converter->convert( $row, $this->settings(), $token );
		} finally {
			$stop();
		}

		$after = $this->variants->get_for_attachment( $id )[0];
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array( VariantStatus::FAILED, 'publish_unsafe' ), array( $after['status'], $after['reason'] ) );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/' . VariantNaming::target_relative_path( $after['source_relative_path'], 'webp' ) );
		$this->assertSame( array(), $this->leftovers( $id ) );
	}

	public function test_a_failed_done_write_after_the_rename_keeps_the_lease_and_stops_the_worker() {
		$id = $this->upload();

		$stop = $this->break_queries( "status = 'done'" );
		try {
			$result = Plugin::get_instance()->processor->run( $id, INF );
		} finally {
			$stop();
		}

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'publish_incomplete', $result->get_message() );
		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::PROCESSING, $row['state'], 'The attachment stays held.' );
		$this->assertNotNull( $row['publishing_at'], 'The lease stays.' );
		$open = array_values( array_filter( $this->variants->get_for_attachment( $id ), static function ( $variant ) {
			return VariantStatus::PROCESSING === $variant['status'];
		} ) );
		$this->assertCount( 1, $open );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $open[0]['relative_path'], 'The file may already be in place.' );
		$this->assertEmpty( $open[0]['file_hash'], 'Its row kept the previous (here: no) hash.' );
		$this->assertContains( VariantStatus::PENDING, array_column( $this->variants->get_for_attachment( $id ), 'status' ), 'The other variants were not tried.' );
	}

	public function test_a_lease_that_cannot_be_released_stops_publishing_and_the_closing_write_clears_it() {
		$id = $this->upload();

		$stop = $this->break_queries( 'SET publishing_at = NULL' );
		try {
			$result = Plugin::get_instance()->processor->run( $id, INF );
		} finally {
			$stop();
		}

		$statuses = array_count_values( array_column( $this->variants->get_for_attachment( $id ), 'status' ) );
		$this->assertSame( 1, $statuses[ VariantStatus::DONE ], 'The variant that was published stands.' );
		$this->assertGreaterThan( 0, $statuses[ VariantStatus::PENDING ], 'Nothing more was published.' );
		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::QUEUED, $row['state'] );
		$this->assertSame( array( null, null ), array( $row['publishing_at'], $row['attempt_token'] ), 'The closing write cleared the lease.' );
		$this->assertTrue( $result->get_data()['more'] );
	}

	public function test_a_row_removed_meanwhile_releases_the_lease() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		$row   = $this->variants->get_for_attachment( $id )[0];
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );

		$this->writer->between = function () use ( $row ) {
			$this->variants->delete( $row['id'] );
		};
		$result = $this->converter->convert( $row, $this->settings(), $token );

		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertNull( $this->attachments->get( $id )['publishing_at'] );
	}

	public function test_a_queued_action_of_a_paused_job_parks_the_attachment_and_a_resumed_job_converts_it() {
		$jobs = new BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$id = $this->upload();
		$this->attachments->assign_job( $id, $job->get_id() );
		$this->assertTrue( Plugin::get_instance()->conversion_queue->enqueue( $id ) );
		$jobs->pause( $job->get_id() );

		ActionScheduler_QueueRunner::instance()->run();

		$row = $this->attachments->get( $id );
		$this->assertSame( array( AttachmentState::NONE, 'paused', 0 ), array( $row['state'], $row['reason'], $row['attempts'] ) );
		$this->assertSame( array( VariantStatus::PENDING ), array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) ) );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ), 'Nothing is scheduled for a parked attachment.' );

		$jobs->mark_running( $job->get_id() );
		$this->assertTrue( Plugin::get_instance()->conversion_queue->enqueue( $id ), 'A parked attachment can be queued again.' );
		ActionScheduler_QueueRunner::instance()->run();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_task_that_cannot_be_scheduled_parks_an_attachment_of_a_job_and_leaves_another_queued() {
		$jobs = new BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$in_job = $this->upload();
		$alone  = $this->upload();
		$this->attachments->assign_job( $in_job, $job->get_id() );
		$refuse = static function () {
			return true;
		};
		add_filter( 'pre_as_enqueue_async_action', $refuse );
		$queue = Plugin::get_instance()->conversion_queue;

		try {
			$this->assertFalse( $queue->enqueue( $in_job ) );
			$this->assertFalse( $queue->enqueue( $alone ) );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}

		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $in_job ) );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $alone ), 'Without a job the maintenance task repairs it.' );
	}

	public function test_after_a_release_the_next_attempt_keeps_the_file_and_ends_normally() {
		global $wpdb;

		$id   = $this->upload();
		$stop = $this->break_queries( "status = 'done'" );
		try {
			Plugin::get_instance()->processor->run( $id, INF );
		} finally {
			$stop();
		}
		$open = array_values( array_filter( $this->variants->get_for_attachment( $id ), static function ( $variant ) {
			return VariantStatus::PROCESSING === $variant['status'];
		} ) )[0];
		$file = wp_upload_dir()['basedir'] . '/' . $open['relative_path'];
		$hash = hash_file( 'sha256', $file );

		// The operator inspects after the attempt went silent, then confirms.
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - 3000 ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$health  = Plugin::get_instance()->job_health;
		$attempt = $health->attempt( $id );
		$this->assertSame( 'publication_incomplete', $attempt['kind'] );
		$this->assertSame( array( $open['relative_path'] ), $attempt['paths'] );
		$this->assertSame( 'released', $health->release( $id, $attempt['token'], $attempt['since'], $attempt['lease'] ) );
		$this->assertSame( $hash, hash_file( 'sha256', $file ), 'The release touched no file.' );

		$result = Plugin::get_instance()->processor->run( $id, INF );

		$after = array_column( $this->variants->get_for_attachment( $id ), null, 'id' );
		$this->assertSame( array( VariantStatus::FAILED, 'target_exists_foreign' ), array( $after[ $open['id'] ]['status'], $after[ $open['id'] ]['reason'] ) );
		$this->assertSame( $hash, hash_file( 'sha256', $file ), 'The file is kept for the operator.' );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ), 'The attempt ended normally, so nothing counts as a death.' );
		$this->assertContains( $result->get_data()['state'], array( AttachmentState::PARTIAL, AttachmentState::FAILED ) );
	}
}
