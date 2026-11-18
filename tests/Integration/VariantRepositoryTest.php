<?php
/**
 * VariantRepository tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Storage\VariantRepository
 */
class VariantRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var VariantRepository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new VariantRepository( new DatabaseManager() );
	}

	private function variant( array $overrides = array() ) {
		return array_merge(
			array(
				'attachment_id'        => 501,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => '2026/05/photo.jpg',
			),
			$overrides
		);
	}

	/**
	 * Move the timestamp of a variant into the past.
	 *
	 * @param int $id Variant ID.
	 * @return string The timestamp.
	 */
	private function age( $id ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['variants'];
		$old   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE id = %d", $old, $id ) );
		$this->repo->invalidate( 501 );

		return $old;
	}

	public function test_upserting_an_existing_row_without_a_status_keeps_its_timestamp() {
		$id = $this->repo->upsert( $this->variant( array( 'status' => VariantStatus::DONE ) ) );
		$old = $this->age( $id );

		$this->repo->upsert( $this->variant() );
		$this->assertSame( $old, $this->repo->get_for_attachment( 501 )[0]['updated_at'], 'A racing planner insert is not a transition.' );

		$this->repo->upsert( $this->variant( array( 'quality' => 70 ) ) );
		$this->assertSame( $old, $this->repo->get_for_attachment( 501 )[0]['updated_at'] );
		$this->assertSame( VariantStatus::DONE, $this->repo->get_for_attachment( 501 )[0]['status'] );
	}

	public function test_upserting_a_status_refreshes_the_timestamp() {
		$id = $this->repo->upsert( $this->variant( array( 'status' => VariantStatus::DONE ) ) );
		$old = $this->age( $id );

		$this->repo->upsert( $this->variant( array( 'status' => VariantStatus::PENDING ) ) );

		$this->assertNotSame( $old, $this->repo->get_for_attachment( 501 )[0]['updated_at'] );
	}

	public function test_upsert_is_idempotent_and_keeps_unspecified_columns() {
		$id = $this->repo->upsert( $this->variant() );
		$this->assertGreaterThan( 0, $id );

		$again = $this->repo->upsert(
			array(
				'attachment_id' => 501,
				'size_name'     => 'original',
				'format'        => 'webp',
				'quality'       => 80,
			)
		);

		$this->assertSame( $id, $again );
		$rows = $this->repo->get_for_attachment( 501 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 80, $rows[0]['quality'] );
		$this->assertSame( '2026/05/photo.jpg', $rows[0]['source_relative_path'] );
		$this->assertSame( VariantStatus::PENDING, $rows[0]['status'] );
		$this->assertNull( $rows[0]['relative_path'] );
	}

	public function test_cas_transition_succeeds_exactly_once() {
		$id = $this->repo->upsert( $this->variant() );

		$this->assertTrue( $this->repo->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING ) );
		$this->assertFalse( $this->repo->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING ) );

		$this->assertTrue(
			$this->repo->transition(
				$id,
				VariantStatus::PROCESSING,
				VariantStatus::DONE,
				array(
					'relative_path' => '2026/05/photo.jpg.webp',
					'file_size'     => 1234,
				)
			)
		);

		$row = $this->repo->get_for_attachment( 501 )[0];
		$this->assertSame( VariantStatus::DONE, $row['status'] );
		$this->assertSame( '2026/05/photo.jpg.webp', $row['relative_path'] );
		$this->assertSame( 1234, $row['file_size'] );
	}

	public function test_servable_rows_are_batched_and_cache_is_invalidated_on_write() {
		$a = $this->repo->upsert( $this->variant( array( 'attachment_id' => 601 ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 601, 'format' => 'avif' ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 602 ) ) );

		$this->assertSame(
			array( 601 => array(), 602 => array(), 603 => array() ),
			$this->repo->get_servable_for_attachments( array( 601, 602, 603 ) )
		);

		$this->repo->transition( $a, VariantStatus::PENDING, VariantStatus::DONE, array( 'relative_path' => '2026/05/photo.jpg.webp' ) );

		$done = $this->repo->get_servable_for_attachments( array( 601, 602 ) );
		$this->assertCount( 1, $done[601] );
		$this->assertSame( 'webp', $done[601][0]['format'] );
		$this->assertSame( array(), $done[602] );
		$this->assertCount( 1, $this->repo->get_servable_for_attachment( 601 ) );
	}

	public function test_owns_and_find_by_source_path() {
		$this->repo->upsert(
			$this->variant(
				array(
					'status'        => VariantStatus::DONE,
					'relative_path' => '2026/05/photo.jpg.webp',
				)
			)
		);

		$this->assertTrue( $this->repo->owns( '2026/05/photo.jpg.webp' ) );
		$this->assertFalse( $this->repo->owns( '2026/05/photo.webp' ) );
		$this->assertCount( 1, $this->repo->find_by_source_path( '2026/05/photo.jpg' ) );
		$this->assertSame( array(), $this->repo->find_by_source_path( '2026/05/other.jpg' ) );
	}

	public function test_servable_path_is_the_file_of_a_finished_variant_only() {
		$this->assertSame( 'a.jpg.webp', VariantRepository::servable_path( array( 'status' => VariantStatus::DONE, 'relative_path' => 'a.jpg.webp' ) ) );
		$this->assertNull( VariantRepository::servable_path( array( 'status' => VariantStatus::PENDING, 'relative_path' => 'a.jpg.webp' ) ) );
		$this->assertNull( VariantRepository::servable_path( array( 'status' => VariantStatus::FAILED, 'relative_path' => null ) ) );
		$this->assertNull( VariantRepository::servable_path( array( 'status' => VariantStatus::DONE, 'relative_path' => '' ) ) );
	}

	public function test_find_other_owners_ignores_the_attachment_itself() {
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 801, 'status' => VariantStatus::DONE, 'relative_path' => '2026/05/shared.jpg.webp' ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 802, 'status' => VariantStatus::DONE, 'relative_path' => '2026/05/own.jpg.webp' ) ) );

		$owners = $this->repo->find_other_owners( 802, array( '2026/05/shared.jpg.webp', '2026/05/own.jpg.webp', '2026/05/none.jpg.webp' ) );

		$this->assertSame( array( '2026/05/shared.jpg.webp' => 801 ), $owners );
		$this->assertSame( array(), $this->repo->find_other_owners( 802, array() ) );
	}

	public function test_delete_and_count_by_status() {
		$a = $this->repo->upsert( $this->variant() );
		$this->repo->upsert( $this->variant( array( 'format' => 'avif', 'status' => VariantStatus::FAILED ) ) );

		$this->assertSame(
			array(
				VariantStatus::PENDING => 1,
				VariantStatus::FAILED  => 1,
			),
			$this->repo->count_by_status( 501 )
		);

		$this->assertTrue( $this->repo->delete( $a ) );
		$this->assertCount( 1, $this->repo->get_for_attachment( 501 ) );

		$this->repo->delete_for_attachment( 501 );
		$this->assertSame( array(), $this->repo->get_for_attachment( 501 ) );
	}

	public function test_a_token_guarded_transition_needs_the_attempt_that_holds_the_attachment() {
		$attachments = new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), $this->repo );
		$a           = $this->repo->upsert(
			array(
				'attachment_id' => 4301,
				'size_name'     => 'original',
				'format'        => 'webp',
			)
		);
		$token       = $attachments->claim( 4301, TrustOptimize\Storage\AttachmentRepository::SOURCE_QUEUE );

		$this->assertFalse( $this->repo->transition( $a, VariantStatus::PENDING, VariantStatus::PROCESSING, array(), str_repeat( 'f', 32 ) ), 'Another attempt.' );
		$this->assertSame( VariantStatus::PENDING, $this->repo->get_for_attachment( 4301 )[0]['status'] );
		$this->assertTrue( $this->repo->transition( $a, VariantStatus::PENDING, VariantStatus::PROCESSING, array(), $token ) );
		$this->assertSame( VariantStatus::PROCESSING, $this->repo->get_for_attachment( 4301 )[0]['status'], 'The cache follows the write.' );

		$attachments->recover_dead( 4301, $token );

		$this->assertFalse( $this->repo->transition( $a, VariantStatus::PENDING, VariantStatus::DONE, array(), $token ), 'The attempt was ended: its worker can change no row.' );
	}

	public function test_a_transition_reports_a_database_error_as_null() {
		global $wpdb;

		$id    = $this->repo->upsert(
			array(
				'attachment_id' => 4302,
				'size_name'     => 'original',
				'format'        => 'webp',
			)
		);
		$break = static function ( $query ) {
			return false !== strpos( $query, "status = 'processing'" ) || false !== strpos( $query, 'SET status' ) ? 'SELECT * FROM trust_optimize_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		try {
			$this->assertNull( $this->repo->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING ) );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $break );
		}
	}
}
