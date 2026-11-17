<?php
/**
 * AttachmentRepository tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Storage\AttachmentRepository
 */
class AttachmentRepositoryTest extends WP_UnitTestCase {

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
		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function add_variant( $attachment_id, $format, $status ) {
		$this->variants->upsert(
			array(
				'attachment_id' => $attachment_id,
				'size_name'     => 'original',
				'format'        => $format,
				'status'        => $status,
			)
		);
	}

	public function test_state_is_none_without_row() {
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( 701 ) );
		$this->assertNull( $this->attachments->get( 701 ) );
	}

	/**
	 * Age the attachment row, as a worker that went silent would leave it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $seconds       Seconds since the last change.
	 */
	private function age( $attachment_id, $seconds ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - $seconds ), $attachment_id ) );
	}

	/**
	 * A bulk job that owns the attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $paused        Whether the job is paused.
	 * @return int Job ID.
	 */
	private function job_of( $attachment_id, $paused ) {
		$jobs = new BulkJobRepository( new DatabaseManager() );
		$job  = $jobs->create( 'sync' );

		$this->assertNotFalse( $job );
		$jobs->mark_running( $job->get_id() );
		$paused && $jobs->pause( $job->get_id() );
		$this->attachments->assign_job( $attachment_id, $job->get_id() );

		return $job->get_id();
	}

	public function test_claim_succeeds_exactly_once_and_returns_the_token_of_the_attempt() {
		$token = $this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $token );
		$this->assertFalse( $this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE ) );
		$this->assertFalse( $this->attachments->claim( 702, AttachmentRepository::SOURCE_SYNC ), 'A held attachment is never taken over, whoever asks.' );

		$row = $this->attachments->get( 702 );
		$this->assertSame( AttachmentState::PROCESSING, $row['state'] );
		$this->assertSame( $token, $row['attempt_token'] );
		$this->assertSame( 'queue', $row['attempt_source'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertNull( $row['publishing_at'] );
	}

	public function test_a_claim_that_is_old_is_still_not_taken_over() {
		$this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE );
		$this->age( 702, DAY_IN_SECONDS );

		$this->assertFalse( $this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE ) );
		$this->assertFalse( $this->attachments->mark_queued( 702 ) );
		$this->assertFalse( $this->attachments->requeue( 702, 900 ) );
	}

	public function test_a_queue_claim_counts_attempts_and_a_sync_claim_sets_the_count_to_one() {
		$this->assertNotFalse( $this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE ) );
		$this->attachments->recover_dead( 702, $this->attachments->get( 702 )['attempt_token'] );
		$this->assertNotFalse( $this->attachments->claim( 702, AttachmentRepository::SOURCE_QUEUE ) );
		$this->assertSame( 2, $this->attachments->get_attempts( 702 ) );
		$this->attachments->recover_dead( 702, $this->attachments->get( 702 )['attempt_token'] );

		$token = $this->attachments->claim( 702, AttachmentRepository::SOURCE_SYNC );

		$this->assertNotFalse( $token );
		$this->assertSame( 1, $this->attachments->get_attempts( 702 ) );
		$this->assertSame( 'sync', $this->attachments->get( 702 )['attempt_source'] );
	}

	public function test_a_queue_claim_misses_for_a_paused_job_and_a_sync_claim_ignores_the_pause() {
		$this->job_of( 710, true );

		$this->assertFalse( $this->attachments->claim( 710, AttachmentRepository::SOURCE_QUEUE ) );
		$this->assertNotFalse( $this->attachments->claim( 710, AttachmentRepository::SOURCE_SYNC ) );
	}

	public function test_a_queue_claim_succeeds_for_a_running_job_and_for_no_job() {
		$this->job_of( 711, false );

		$this->assertNotFalse( $this->attachments->claim( 711, AttachmentRepository::SOURCE_QUEUE ) );
		$this->assertNotFalse( $this->attachments->claim( 712, AttachmentRepository::SOURCE_QUEUE ) );
	}

	public function test_only_the_attempt_that_holds_the_attachment_can_end_it() {
		$token = $this->attachments->claim( 713, AttachmentRepository::SOURCE_SYNC );

		$this->assertFalse( $this->attachments->finish( 713, str_repeat( 'a', 32 ), AttachmentState::OPTIMIZED ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 713 ) );

		$this->assertTrue( $this->attachments->finish( 713, $token, AttachmentState::FAILED, 'save_failed', 'boom' ) );

		$row = $this->attachments->get( 713 );
		$this->assertSame( array( AttachmentState::FAILED, 'save_failed', 'boom', 0 ), array( $row['state'], $row['reason'], $row['last_error'], $row['attempts'] ) );
		$this->assertSame( array( null, null, null ), array( $row['attempt_source'], $row['attempt_token'], $row['publishing_at'] ) );
		$this->assertFalse( $this->attachments->finish( 713, $token, AttachmentState::OPTIMIZED ), 'The token works once.' );
	}

	public function test_nothing_but_the_attempt_changes_a_held_attachment() {
		$this->attachments->claim( 714, AttachmentRepository::SOURCE_QUEUE );
		$this->add_variant( 714, 'webp', VariantStatus::DONE );

		$this->attachments->set_state( 714, AttachmentState::FAILED, 'x' );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->recompute( 714 ), 'The answer is the derived state...' );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 714 ), '...but the held row is left alone.' );
		$this->attachments->reset_attempts( 714 );
		$this->assertSame( 1, $this->attachments->get_attempts( 714 ) );
	}

	public function test_variants_left_in_processing_do_not_make_an_attachment_processing() {
		$this->add_variant( 715, 'webp', VariantStatus::PROCESSING );
		$this->attachments->set_state( 715, AttachmentState::NONE, 'deactivated' );

		$this->assertSame( AttachmentState::NONE, $this->attachments->recompute( 715 ) );
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( 715 ) );
	}

	public function test_a_file_that_died_three_times_is_not_queued_again() {
		$this->attachments->set_state( 716, AttachmentState::FAILED, 'max_attempts' );
		global $wpdb;
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = 3 WHERE attachment_id = %d", 716 ) );

		$this->assertFalse( $this->attachments->mark_queued( 716 ) );
		$this->age( 716, DAY_IN_SECONDS );
		$this->assertFalse( $this->attachments->requeue( 716, 900 ) );

		$this->attachments->reset_attempts( 716 );
		$this->assertTrue( $this->attachments->mark_queued( 716 ) );
	}

	public function test_a_parked_attachment_can_be_queued() {
		$this->job_of( 717, true );
		$this->attachments->mark_queued( 717 );

		$this->assertTrue( $this->attachments->park( 717 ) );
		$row = $this->attachments->get( 717 );
		$this->assertSame( array( AttachmentState::NONE, 'paused' ), array( $row['state'], $row['reason'] ) );
		$this->assertNotNull( $row['job_id'], 'The attachment keeps its job.' );
		$this->assertTrue( $this->attachments->mark_queued( 717 ) );
	}

	public function test_park_needs_a_queued_attachment_of_a_paused_job() {
		$this->attachments->mark_queued( 718 );
		$this->assertFalse( $this->attachments->park( 718 ), 'No job.' );

		$this->job_of( 719, false );
		$this->attachments->mark_queued( 719 );
		$this->assertFalse( $this->attachments->park( 719 ), 'The job is running.' );
		$this->assertTrue( $this->attachments->repark( 719 ), 'A failed scheduling parks it for the job\'s producer.' );

		$this->attachments->mark_queued( 720 );
		$this->assertFalse( $this->attachments->repark( 720 ), 'Without a job it stays queued for the maintenance task.' );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( 720 ) );
	}

	public function test_the_lease_is_taken_once_by_the_attempt_that_holds_the_attachment() {
		$token = $this->attachments->claim( 721, AttachmentRepository::SOURCE_QUEUE );
		$this->age( 721, 1000 );
		$before = $this->attachments->get( 721 )['updated_at'];

		$this->assertFalse( $this->attachments->take_lease( 721, str_repeat( 'b', 32 ) ) );
		$this->assertTrue( $this->attachments->take_lease( 721, $token ) );
		$this->assertFalse( $this->attachments->take_lease( 721, $token ), 'One lease at a time.' );
		$this->assertNotNull( $this->attachments->get( 721 )['publishing_at'] );
		$this->assertSame( $before, $this->attachments->get( 721 )['updated_at'], 'A lease does not make the attempt look alive.' );

		$this->assertTrue( $this->attachments->release_lease( 721, $token ) );
		$this->assertFalse( $this->attachments->release_lease( 721, $token ) );
		$this->assertSame( $before, $this->attachments->get( 721 )['updated_at'] );
	}

	public function test_a_dead_attempt_goes_back_to_the_queue_with_its_variants_reset() {
		$token = $this->attachments->claim( 722, AttachmentRepository::SOURCE_QUEUE );
		$this->add_variant( 722, 'webp', VariantStatus::PROCESSING );
		$this->add_variant( 722, 'avif', VariantStatus::DONE );
		$this->variants->prime( array( 722 ) );

		$this->assertFalse( $this->attachments->recover_dead( 722, str_repeat( 'c', 32 ) ), 'Another attempt.' );
		$this->assertTrue( $this->attachments->recover_dead( 722, $token ) );

		$row = $this->attachments->get( 722 );
		$this->assertSame( array( AttachmentState::QUEUED, null, null ), array( $row['state'], $row['reason'], $row['last_error'] ) );
		$this->assertSame( array( null, null, null ), array( $row['attempt_source'], $row['attempt_token'], $row['publishing_at'] ) );
		$this->assertSame( 1, $row['attempts'], 'The death stays counted.' );
		$statuses = array_column( $this->variants->get_for_attachment( 722 ), 'status', 'format' );
		$this->assertSame( array( 'webp' => VariantStatus::PENDING, 'avif' => VariantStatus::DONE ), $statuses );
		$this->assertFalse( $this->attachments->recover_dead( 722, $token ), 'An attempt dies once.' );
	}

	public function test_a_dead_attempt_of_a_paused_job_is_parked() {
		$job   = $this->job_of( 723, true );
		$token = $this->attachments->claim( 723, AttachmentRepository::SOURCE_SYNC );
		$this->add_variant( 723, 'webp', VariantStatus::PROCESSING );

		$this->assertTrue( $this->attachments->recover_dead( 723, $token ) );

		$row = $this->attachments->get( 723 );
		$this->assertSame( array( AttachmentState::NONE, 'paused', (string) $job ), array( $row['state'], $row['reason'], $row['job_id'] ) );
		$this->assertSame( VariantStatus::PENDING, $this->variants->get_for_attachment( 723 )[0]['status'] );
	}

	public function test_the_third_death_gives_the_file_and_its_open_variants_up() {
		global $wpdb;
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$token = $this->attachments->claim( 724, AttachmentRepository::SOURCE_QUEUE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET attempts = 3 WHERE attachment_id = %d", 724 ) );
		$this->add_variant( 724, 'webp', VariantStatus::PROCESSING );
		$this->add_variant( 724, 'avif', VariantStatus::PENDING );
		$this->variants->upsert(
			array(
				'attachment_id' => 724,
				'size_name'     => 'thumbnail',
				'format'        => 'webp',
				'status'        => VariantStatus::DONE,
			)
		);

		$this->assertTrue( $this->attachments->recover_dead( 724, $token ) );

		$row = $this->attachments->get( 724 );
		$this->assertSame( array( AttachmentState::FAILED, 'max_attempts', 3 ), array( $row['state'], $row['reason'], $row['attempts'] ) );
		$this->assertSame( 'The worker died 3 times on this file.', $row['last_error'] );
		$rows = $this->variants->get_for_attachment( 724 );
		$this->assertSame( array( VariantStatus::FAILED, VariantStatus::FAILED, VariantStatus::DONE ), array_column( $rows, 'status' ) );
		$this->assertSame( array( 'max_attempts', 'max_attempts', null ), array_column( $rows, 'reason' ) );
	}

	public function test_a_lease_protects_the_attempt_from_the_death_compare_and_set() {
		$token = $this->attachments->claim( 725, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( 725, $token );

		$this->assertFalse( $this->attachments->recover_dead( 725, $token ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 725 ) );
	}

	public function test_death_candidates_are_old_leaseless_queue_attempts_oldest_first() {
		$queue_old   = $this->attachments->claim( 726, AttachmentRepository::SOURCE_QUEUE );
		$queue_older = $this->attachments->claim( 727, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->claim( 728, AttachmentRepository::SOURCE_SYNC );
		$leased = $this->attachments->claim( 729, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->claim( 730, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( 729, $leased );
		foreach ( array( 726 => 1000, 727 => 2000, 728 => 3000, 729 => 4000 ) as $id => $seconds ) {
			$this->age( $id, $seconds );
		}

		$found = $this->attachments->find_death_candidates( 900, 20 );

		$this->assertSame( array( 727, 726 ), array_column( $found, 'attachment_id' ) );
		$this->assertSame( array( $queue_older, $queue_old ), array_column( $found, 'attempt_token' ) );
		$this->assertCount( 1, $this->attachments->find_death_candidates( 900, 1 ) );
	}

	public function test_release_needs_the_attempt_as_it_was_inspected() {
		$token = $this->attachments->claim( 731, AttachmentRepository::SOURCE_SYNC );
		$this->age( 731, 2000 );
		$since = $this->attachments->get( 731 )['updated_at'];

		$this->assertFalse( $this->attachments->release( 731, str_repeat( 'd', 32 ), $since, null ), 'Another attempt.' );
		$this->assertFalse( $this->attachments->release( 731, $token, gmdate( 'Y-m-d H:i:s', time() ), null ), 'The attempt changed since.' );
		$this->assertFalse( $this->attachments->release( 731, $token, $since, '2026-01-01 00:00:00' ), 'A lease appeared.' );
		$this->assertTrue( $this->attachments->release( 731, $token, $since, null ) );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( 731 ) );
	}

	public function test_a_leased_attempt_is_released_with_the_lease_that_was_shown() {
		$token = $this->attachments->claim( 732, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( 732, $token );
		$this->age( 732, 2000 );
		$row = $this->attachments->get( 732 );

		$this->assertFalse( $this->attachments->release( 732, $token, $row['updated_at'], null ) );
		$this->assertTrue( $this->attachments->release( 732, $token, $row['updated_at'], $row['publishing_at'] ) );
		$this->assertNull( $this->attachments->get( 732 )['publishing_at'] );
	}

	public function test_a_row_from_before_the_attempt_record_is_released_as_legacy() {
		global $wpdb;
		$table = ( new DatabaseManager() )->get_plugin_table_names()['attachments'];
		$this->attachments->set_state( 733, AttachmentState::NONE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET state = 'processing', updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - 2000 ), 733 ) );
		$since = $this->attachments->get( 733 )['updated_at'];

		$this->assertFalse( $this->attachments->release( 733, str_repeat( 'e', 32 ), $since, null ) );
		$this->assertTrue( $this->attachments->release( 733, null, $since, null ) );
	}

	public function test_a_deactivation_leaves_an_attachment_inside_a_publication_alone() {
		$token = $this->attachments->claim( 734, AttachmentRepository::SOURCE_QUEUE );
		$this->attachments->take_lease( 734, $token );
		$this->attachments->claim( 735, AttachmentRepository::SOURCE_QUEUE );

		$this->assertSame( 1, $this->attachments->suspend_unfinished( 'deactivated' ) );

		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 734 ) );
		$row = $this->attachments->get( 735 );
		$this->assertSame( array( AttachmentState::NONE, 'deactivated', null ), array( $row['state'], $row['reason'], $row['attempt_token'] ) );
	}

	public function test_mark_queued_prevents_duplicates() {
		$this->assertTrue( $this->attachments->mark_queued( 703 ) );
		$this->assertFalse( $this->attachments->mark_queued( 703 ) );

		$this->attachments->recompute( 703 );
		$this->attachments->set_state( 703, AttachmentState::OPTIMIZED );
		$this->assertTrue( $this->attachments->mark_queued( 703 ), 'A finished attachment can be queued again.' );
	}

	/**
	 * Regression: "done + failed" must be stored as "partial" (a 21-character literal was rejected by the old column).
	 */
	public function test_recompute_stores_partial_for_done_and_failed() {
		$this->add_variant( 704, 'webp', VariantStatus::DONE );
		$this->add_variant( 704, 'avif', VariantStatus::FAILED );

		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->recompute( 704 ) );
		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->get_state( 704 ) );
	}

	public function test_recompute_covers_each_aggregate() {
		$this->add_variant( 705, 'webp', VariantStatus::DONE );
		$this->add_variant( 705, 'avif', VariantStatus::SKIPPED );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->recompute( 705 ) );

		$this->add_variant( 706, 'webp', VariantStatus::FAILED );
		$this->assertSame( AttachmentState::FAILED, $this->attachments->recompute( 706 ) );

		$this->add_variant( 707, 'webp', VariantStatus::PENDING );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->recompute( 707 ) );

		$this->add_variant( 708, 'webp', VariantStatus::PENDING );
		$this->add_variant( 708, 'avif', VariantStatus::DONE );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->recompute( 708 ) );
	}

	public function test_recompute_keeps_explicit_skipped_without_variants() {
		$this->attachments->set_state( 709, AttachmentState::SKIPPED, 'unsupported_mime' );

		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->recompute( 709 ) );
		$this->assertSame( 'unsupported_mime', $this->attachments->get( 709 )['reason'] );
	}

	public function test_last_error_is_truncated_to_255_characters() {
		$this->attachments->set_state( 710, AttachmentState::FAILED, 'convert_failed', str_repeat( 'é', 400 ) );

		$this->assertSame( 255, mb_strlen( $this->attachments->get( 710 )['last_error'], 'UTF-8' ) );
	}
}
