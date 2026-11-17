<?php
/**
 * VariantPlanner::plan() against real attachments.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Planning\VariantPlanner
 */
class VariantPlannerTest extends WP_UnitTestCase {

	/**
	 * Planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

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
		$this->planner     = new VariantPlanner( $this->variants, $this->attachments, new Settings(), new CapabilityService() );
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $id );
		// Ignore whatever the upload hook did to the plugin tables.
		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );

		return $id;
	}

	public function test_plans_pending_rows_for_original_and_sizes() {
		$id   = $this->upload();
		$plan = $this->planner->plan( $id );

		$this->assertFalse( $plan->is_skipped() );
		$this->assertTrue( $plan->has_work() );

		$sizes = array_column( $plan->pending(), 'size_name' );
		$this->assertContains( 'original', $sizes );
		$this->assertContains( 'thumbnail', $sizes );
		foreach ( $plan->pending() as $row ) {
			$this->assertSame( 'webp', $row['format'] );
			$this->assertSame( VariantStatus::PENDING, $row['status'] );
			$this->assertStringEndsWith( '.jpg', $row['source_relative_path'] );
		}
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $id ), 'Whoever queues or claims the attachment sets the state.' );
	}

	public function test_planning_twice_does_not_duplicate_rows() {
		$id = $this->upload();

		$first = count( $this->planner->plan( $id )->pending() );
		$this->planner->plan( $id );

		$this->assertCount( $first, $this->variants->get_for_attachment( $id ) );
	}

	public function test_stale_done_rows_return_to_pending_and_current_ones_do_not() {
		$id = $this->upload();
		$this->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::DONE, array( 'quality' => 85 ) );
		}
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 85 ) );

		$this->assertFalse( $this->planner->plan( $id )->has_work(), 'Same quality: nothing to redo.' );

		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 60 ) );

		$this->assertTrue( $this->planner->plan( $id )->has_work(), 'Different quality: variants are reconverted.' );
	}

	public function test_disabling_a_format_lists_its_rows_for_deletion() {
		$id = $this->upload();
		$this->planner->plan( $id );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 0, 'convert_to_avif' => 0 ) );

		$plan = $this->planner->plan( $id );

		$this->assertFalse( $plan->has_work() );
		$this->assertNotEmpty( $plan->to_delete() );
		$this->assertSame( 'webp', $plan->to_delete()[0]['format'] );
	}

	public function test_webp_source_gets_no_variants() {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/webp',
				'file'           => '2026/05/source.webp',
			)
		);

		$plan = $this->planner->plan( $id );

		$this->assertTrue( $plan->is_skipped() );
		$this->assertSame( 'unsupported_mime', $plan->skip_reason() );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->get_state( $id ) );
	}

	public function test_a_variant_a_worker_is_converting_is_never_reset_deleted_or_replaced() {
		$id = $this->upload();
		$this->planner->plan( $id );
		$rows   = $this->variants->get_for_attachment( $id );
		$moved  = $rows[0];
		$gone   = $rows[1];
		$this->variants->transition( $moved['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );
		$this->variants->transition( $gone['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );
		// The first row now comes from another source file (regenerated thumbnails); the second size vanished.
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => $moved['size_name'],
				'format'               => $moved['format'],
				'source_relative_path' => 'elsewhere/other.jpg',
			)
		);
		$this->variants->upsert(
			array(
				'attachment_id' => $id,
				'size_name'     => 'vanished',
				'format'        => 'webp',
				'status'        => VariantStatus::PROCESSING,
			)
		);

		$plan = $this->planner->plan( $id );

		$after = array_column( $this->variants->get_for_attachment( $id ), null, 'size_name' );
		$this->assertSame( VariantStatus::PROCESSING, $after[ $moved['size_name'] ]['status'] );
		$this->assertSame( 'elsewhere/other.jpg', $after[ $moved['size_name'] ]['source_relative_path'], 'The planner did not re-point it.' );
		$this->assertSame( VariantStatus::PROCESSING, $after['vanished']['status'] );
		$this->assertSame( array(), $plan->replaced() );
		$this->assertNotContains( 'vanished', array_column( $plan->to_delete(), 'size_name' ) );
	}

	public function test_a_failed_row_whose_source_file_changed_is_retried_and_the_attempts_start_over() {
		global $wpdb;

		$id = $this->upload();
		$this->planner->plan( $id );
		$row = $this->variants->get_for_attachment( $id )[0];
		$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed', 'source_file_size' => 1 ) );
		$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts' );
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = %d WHERE attachment_id = %d", AttachmentRepository::MAX_ATTEMPTS, $id ) );

		$plan = $this->planner->plan( $id );

		$this->assertSame( array( $row['size_name'] ), array_column( array_filter( $plan->pending(), static function ( $pending ) use ( $row ) {
			return $pending['size_name'] === $row['size_name'];
		} ), 'size_name' ), 'The row of a new source goes back to pending although the count was used up.' );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
	}

	public function test_a_failed_row_of_the_same_source_stays_failed_while_the_attempts_are_used_up() {
		global $wpdb;

		$id = $this->upload();
		$this->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed', 'source_file_size' => $row['source_file_size'] ) );
		}
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = %d WHERE attachment_id = %d", AttachmentRepository::MAX_ATTEMPTS, $id ) );

		$this->assertFalse( $this->planner->plan( $id )->has_work() );
		$this->assertSame( AttachmentRepository::MAX_ATTEMPTS, $this->attachments->get_attempts( $id ) );
	}

	public function test_planning_under_a_claim_writes_no_attachment_state() {
		$id = $this->upload();
		wp_delete_file( get_attached_file( $id ) );
		$token = $this->attachments->claim( $id, AttachmentRepository::SOURCE_SYNC );

		$plan = $this->planner->plan( $id, true );

		$this->assertSame( 'missing_file', $plan->skip_reason() );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
		$this->assertTrue( $this->attachments->finish( $id, $token, AttachmentState::SKIPPED, $plan->skip_reason() ) );
	}
}
