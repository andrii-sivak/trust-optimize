<?php
/**
 * Regenerated metadata must clean up variants that no longer belong (02.13).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\ConversionQueue::handle_new_metadata
 */
class MetadataRegenerationTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	private function optimized_upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		ActionScheduler_QueueRunner::instance()->run();

		return $id;
	}

	private function abs( $relative ) {
		return wp_upload_dir()['basedir'] . '/' . $relative;
	}

	private function medium_variant( $id ) {
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			if ( 'medium' === $row['size_name'] ) {
				return $row;
			}
		}

		return null;
	}

	public function test_regeneration_removes_the_variant_of_a_replaced_size_file() {
		$id  = $this->optimized_upload();
		$old = $this->medium_variant( $id );
		$this->assertNotNull( $old );
		$this->assertFileExists( $this->abs( $old['relative_path'] ) );

		// A theme change gives "medium" another file.
		$stored = wp_get_attachment_metadata( $id );
		$metadata = $stored;
		$new_name                           = 'regenerated-' . wp_generate_uuid4() . '.jpg';
		$metadata['sizes']['medium']['file'] = $new_name;
		copy( $this->abs( $old['source_relative_path'] ), dirname( $this->abs( $old['source_relative_path'] ) ) . '/' . $new_name );

		$this->assertSame( $metadata, apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' ) );
		$this->assertSame( $stored, wp_get_attachment_metadata( $id ) );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertFileDoesNotExist( $this->abs( $old['relative_path'] ), 'The old variant file must not be left behind.' );

		ActionScheduler_QueueRunner::instance()->run();

		$new = $this->medium_variant( $id );
		$this->assertSame( 'done', $new['status'] );
		$this->assertStringEndsWith( $new_name . '.webp', $new['relative_path'] );
		$this->assertFileExists( $this->abs( $new['relative_path'] ) );
		foreach ( $this->variants->get_servable_for_attachment( $id ) as $row ) {
			$this->assertFileExists( $this->abs( $row['relative_path'] ), 'No done row without a file.' );
		}
	}

	public function test_regeneration_with_only_removals_still_deletes_them() {
		$id  = $this->optimized_upload();
		$old = $this->medium_variant( $id );

		$stored = wp_get_attachment_metadata( $id );
		$metadata = $stored;
		unset( $metadata['sizes']['medium'] );

		$this->assertSame( $metadata, apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' ) );
		$this->assertSame( $stored, wp_get_attachment_metadata( $id ) );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertFileDoesNotExist( $this->abs( $old['relative_path'] ) );
		$this->assertNull( $this->medium_variant( $id ) );
	}

	public function test_removals_together_with_new_work_still_queue_the_attachment() {
		$id = $this->optimized_upload();

		$stored = wp_get_attachment_metadata( $id );
		$metadata = $stored;
		unset( $metadata['sizes']['thumbnail'] );
		$again                                = 'again-' . wp_generate_uuid4() . '.jpg';
		$metadata['sizes']['medium']['file'] = $again;
		$old                                 = $this->medium_variant( $id );
		copy( $this->abs( $old['source_relative_path'] ), dirname( $this->abs( $old['source_relative_path'] ) ) . '/' . $again );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$this->assertSame( $metadata, apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' ) );
		$this->assertSame( $stored, wp_get_attachment_metadata( $id ) );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
	/**
	 * Stage a client upload with real size files but only original metadata saved.
	 */
	private function staged_upload() {
		$queue = Plugin::get_instance()->conversion_queue;
		remove_filter( 'wp_generate_attachment_metadata', array( $queue, 'handle_new_metadata' ), 20 );
		try {
			$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		} finally {
			add_filter( 'wp_generate_attachment_metadata', array( $queue, 'handle_new_metadata' ), 20, 2 );
		}
		$complete = wp_get_attachment_metadata( $id );
		$original = $complete;
		unset( $original['sizes'] );
		wp_update_attachment_metadata( $id, $original );
		apply_filters( 'wp_generate_attachment_metadata', $original, $id, 'create' );

		return array( $id, $original, $complete );
	}

	private function pending_actions( $id ) {
		return as_get_scheduled_actions(
			array(
				'hook' => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args' => array( 'attachment_id' => $id ),
			),
			'ids'
		);
	}

	private function assert_drained( $id, $metadata ) {
		$rows = $this->variants->get_for_attachment( $id );
		$this->assertCount( 1 + count( $metadata['sizes'] ), $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'done', $row['status'] );
			$this->assertFileExists( $this->abs( $row['relative_path'] ) );
			$this->assertSame( $row['file_hash'], hash_file( 'sha256', $this->abs( $row['relative_path'] ) ) );
		}
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_late_snapshot_is_planned_before_persistence_and_replays_do_not_encode_again() {
		list( $id, $original, $complete ) = $this->staged_upload();
		$action = $this->pending_actions( $id );
		$this->assertSame( $complete, apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' ) );
		$this->assertSame( $original, wp_get_attachment_metadata( $id ) );
		$this->assertCount( 1 + count( $complete['sizes'] ), $this->variants->get_for_attachment( $id ) );
		$this->assertSame( $action, $this->pending_actions( $id ) );
		wp_update_attachment_metadata( $id, $complete );
		ActionScheduler_QueueRunner::instance()->run();
		$this->assert_drained( $id, $complete );
		$ready = $this->variants->get_for_attachment( $id );
		apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		$this->assertSame( $ready, $this->variants->get_for_attachment( $id ) );
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_late_event_after_completion_enqueues_once_and_preserves_original() {
		list( $id, $original, $complete ) = $this->staged_upload();
		ActionScheduler_QueueRunner::instance()->run();
		$ready = $this->variants->get_for_attachment( $id )[0];
		apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		$this->assertCount( 1, $this->pending_actions( $id ) );
		$this->assertSame( $ready, $this->variants->get_for_attachment( $id )[0] );
		wp_update_attachment_metadata( $id, $complete );
		ActionScheduler_QueueRunner::instance()->run();
		$this->assert_drained( $id, $complete );
	}

	public function test_supplied_dimensions_control_eligibility_before_persistence() {
		list( $id, $original, $complete ) = $this->staged_upload();
		$complete['width'] = 100000;
		$complete['height'] = 100000;
		$this->assertSame( $complete, apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' ) );
		$this->assertSame( $original, wp_get_attachment_metadata( $id ) );
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$this->assertSame( 'too_large', $attachments->get( $id )['reason'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
	}

	public function test_metadata_replayed_during_conversion_preserves_attempt_and_encodes_each_size_once() {
		list( $id, $original, $complete ) = $this->staged_upload();
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$encodes = 0;
		$fired = false;
		$interleave = function ( $sql ) use ( $id, $complete, $attachments, &$encodes, &$fired ) {
			if ( false !== strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, "status = 'processing', updated_at" ) && false !== strpos( $sql, 'SET ' ) ) {
				++$encodes;
				if ( ! $fired ) {
					$fired = true;
					$before = $attachments->get( $id );
					apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
					apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
					$this->assertSame( $before, $attachments->get( $id ) );
					$this->assertSame( array(), $this->pending_actions( $id ) );
				}
			}
			return $sql;
		};
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		add_filter( 'query', $interleave );
		try {
			Plugin::get_instance()->conversion_queue->process( $id );
			apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertTrue( $fired );
		$this->assertSame( 1 + count( $complete['sizes'] ), $encodes );
		$this->assert_drained( $id, $complete );
	}

	public function test_late_sizes_complete_responsive_candidates_and_preserve_the_fallback() {
		list( $id, $original, $complete ) = $this->staged_upload();
		ActionScheduler_QueueRunner::instance()->run();
		$renderer = new \TrustOptimize\Frontend\PictureRenderer( $this->variants, new \TrustOptimize\Frontend\UploadsUrl() );
		$base = wp_upload_dir()['baseurl'] . '/';
		$directory = dirname( $complete['file'] ) . '/';
		$candidates = array( $base . $complete['file'] . ' ' . $complete['width'] . 'w' );
		foreach ( $complete['sizes'] as $size ) {
			$candidates[] = $base . $directory . $size['file'] . ' ' . $size['width'] . 'w';
		}
		$sizes = '(max-width: 640px) 100vw, 640px';
		$img = '<img src="' . $base . $complete['file'] . '" srcset="' . implode( ', ', $candidates ) . '" sizes="' . $sizes . '" alt="Original fallback">';
		$this->assertSame( $img, $renderer->render( $img, $id ) );
		apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		wp_update_attachment_metadata( $id, $complete );
		ActionScheduler_QueueRunner::instance()->run();
		$optimized = array_map( static function ( $candidate ) { return str_replace( '.jpg ', '.jpg.webp ', $candidate ); }, $candidates );
		$this->assertSame( '<picture><source type="image/webp" srcset="' . implode( ', ', $optimized ) . '" sizes="' . $sizes . '">' . $img . '</picture>', $renderer->render( $img, $id ) );
	}

	public function test_a_no_work_replay_does_not_overwrite_a_scheduling_owners_queued_state() {
		list( $id, $original, $complete ) = $this->staged_upload();
		ActionScheduler_QueueRunner::instance()->run();
		$queue = Plugin::get_instance()->conversion_queue;
		$this->assertTrue( $queue->enqueue( $id ) );
		$actions = $this->pending_actions( $id );
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$before = $attachments->get( $id );
		apply_filters( 'wp_generate_attachment_metadata', $original, $id, 'update' );
		$this->assertSame( $before, $attachments->get( $id ) );
		$this->assertSame( $actions, $this->pending_actions( $id ) );
	}

	public function test_clearing_a_no_work_skip_cannot_overwrite_a_concurrent_enqueue() {
		list( $id, $original ) = $this->staged_upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->variants->delete_for_attachment( $id );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 0, 'convert_to_avif' => 0 ) );
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$attachments->set_state( $id, 'skipped', 'unsupported_mime' );
		$fired = false;
		$interleave = function ( $sql ) use ( $id, &$fired ) {
			if ( ! $fired && false !== strpos( $sql, "SET state = 'none'" ) ) {
				$fired = true;
				$this->assertTrue( Plugin::get_instance()->conversion_queue->enqueue( $id ) );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			apply_filters( 'wp_generate_attachment_metadata', $original, $id, 'update' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertTrue( $fired );
		$this->assertSame( 'queued', $attachments->get_state( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ) );
	}

	public function test_a_late_event_during_no_work_recompute_keeps_its_only_action() {
		list( $id, $original, $complete ) = $this->staged_upload();
		ActionScheduler_QueueRunner::instance()->run();
		$fired = false;
		$interleave = function ( $sql ) use ( $id, $complete, &$fired ) {
			if ( ! $fired && false !== strpos( $sql, "state = 'optimized', reason = NULL, last_error = NULL" ) ) {
				$fired = true;
				apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			apply_filters( 'wp_generate_attachment_metadata', $original, $id, 'update' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertTrue( $fired );
		$this->assertCount( 1, $this->pending_actions( $id ) );
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$this->assertSame( 'queued', $attachments->get_state( $id ) );
		wp_update_attachment_metadata( $id, $complete );
		ActionScheduler_QueueRunner::instance()->run();
		$this->assert_drained( $id, $complete );
	}

	public function test_a_plan_already_drained_before_enqueue_does_not_schedule_an_empty_action() {
		list( $id, $original, $complete ) = $this->staged_upload();
		ActionScheduler_QueueRunner::instance()->run();
		$fired = false;
		$interleave = function ( $sql ) use ( $id, &$fired ) {
			if ( ! $fired && false !== strpos( $sql, "SET state = 'queued'" ) && false !== strpos( $sql, 'EXISTS ( SELECT 1 FROM' ) ) {
				$fired = true;
				Plugin::get_instance()->processor->run( $id, INF );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertTrue( $fired );
		$this->assert_drained( $id, $complete );
	}

	/**
	 * Insert through the real metadata event after the final count, before its closing SQL executes.
	 */
	public static function closing_orderings() {
		return array( 'continue' => array( 'continue' ), 'paused' => array( 'paused' ), 'lost token' => array( 'lost' ) );
	}

	/**
	 * @dataProvider closing_orderings
	 */
	public function test_late_event_at_terminal_write_keeps_the_token_and_schedules_one_continuation( $ordering ) {
		list( $id, $original, $complete ) = $this->staged_upload();
		$attachments = new AttachmentRepository( new DatabaseManager(), $this->variants );
		$jobs = new \TrustOptimize\Bulk\BulkJobRepository( new DatabaseManager() );
		$job = $jobs->create( 'sync' );
		$jobs->mark_running( $job->get_id() );
		$attachments->assign_job( $id, $job->get_id() );
		$new_token = null;
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$fired = false;
		$interleave = function ( $sql ) use ( $id, $complete, $attachments, $jobs, $job, $ordering, &$new_token, &$fired ) {
			if ( ! $fired && false !== strpos( $sql, 'attempt_token = NULL' ) && false !== strpos( $sql, 'attempt_token =' ) && false !== strpos( $sql, "state = 'optimized'" ) ) {
				$fired = true;
				$before = $attachments->get( $id );
				$this->assertNotEmpty( $before['attempt_token'] );
				apply_filters( 'wp_generate_attachment_metadata', $complete, $id, 'update' );
				$this->assertSame( $before, $attachments->get( $id ), 'Metadata must not change the active attempt.' );
				$this->assertSame( array(), $this->pending_actions( $id ) );
				if ( 'paused' === $ordering ) {
					$this->assertTrue( $jobs->pause( $job->get_id() ) );
				} elseif ( 'lost' === $ordering ) {
					$this->assertTrue( $attachments->recover_dead( $id, $before['attempt_token'] ) );
					$new_token = $attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
				}
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			Plugin::get_instance()->conversion_queue->process( $id );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertTrue( $fired );
		if ( 'lost' === $ordering ) {
			$this->assertSame( $new_token, $attachments->get( $id )['attempt_token'] );
			$this->assertSame( 'processing', $attachments->get_state( $id ) );
			$this->assertSame( array(), $this->pending_actions( $id ) );
			return;
		}
		$this->assertSame( 'queued', $attachments->get_state( $id ) );
		$this->assertNull( $attachments->get( $id )['attempt_token'] );
		$this->assertSame( 0, $attachments->get_attempts( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ) );
		$action = ActionScheduler::store()->fetch_action( $this->pending_actions( $id )[0] );
		$this->assertSame( 20, $action->get_priority() );
		wp_update_attachment_metadata( $id, $complete );
		if ( 'paused' === $ordering ) {
			$before = $this->variants->get_for_attachment( $id );
			ActionScheduler_QueueRunner::instance()->run();
			$this->assertSame( $before, $this->variants->get_for_attachment( $id ) );
			$this->assertSame( 'none', $attachments->get_state( $id ) );
			$this->assertSame( 'paused', $attachments->get( $id )['reason'] );
			$this->assertSame( 'paused', $jobs->get( $job->get_id() )->get_status() );
			$this->assertSame( array(), $this->pending_actions( $id ) );
			return;
		}
		ActionScheduler_QueueRunner::instance()->run();
		$this->assert_drained( $id, $complete );
	}

}
