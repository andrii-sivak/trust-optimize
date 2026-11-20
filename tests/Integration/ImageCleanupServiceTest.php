<?php
/**
 * ImageCleanupService tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Frontend\PictureRenderer;
use TrustOptimize\Frontend\UploadsUrl;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;

/**
 * @covers \TrustOptimize\Service\ImageCleanupService
 */
class ImageCleanupServiceTest extends WP_UnitTestCase {

	/**
	 * Uploads sub directory used by the test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Its relative path.
	 *
	 * @var string
	 */
	private $relative_dir;

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

	/**
	 * Service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	public function set_up() {
		parent::set_up();
		$this->relative_dir = 'cleanup-test-' . wp_generate_uuid4();
		$this->dir          = wp_upload_dir()['basedir'] . '/' . $this->relative_dir;
		wp_mkdir_p( $this->dir );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->cleanup     = new ImageCleanupService( $this->variants, $this->attachments, new FileOwnership( $this->variants ) );
	}

	public function tear_down() {
		chmod( $this->dir, 0755 );
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Create a variant file and its done row.
	 */
	private function variant( $attachment_id, $name, $format = 'webp', $content = 'variant bytes' ) {
		file_put_contents( $this->dir . '/' . $name, $content );

		return $this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $name,
				'format'               => $format,
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $this->relative_dir . '/source.jpg',
				'relative_path'        => $this->relative_dir . '/' . $name,
				'file_hash'            => hash( 'sha256', $content ),
			)
		);
	}

	public function test_deletes_owned_files_and_rows_but_not_anything_else() {
		$this->variant( 1001, 'a.jpg.webp' );
		file_put_contents( $this->dir . '/a.jpg', 'original' );
		file_put_contents( $this->dir . '/a.webp', 'users own file' );

		$result = $this->cleanup->cleanup_attachment( 1001 );

		$this->assertTrue( $result->is_success() );
		$this->assertFileDoesNotExist( $this->dir . '/a.jpg.webp' );
		$this->assertSame( 'original', file_get_contents( $this->dir . '/a.jpg' ) );
		$this->assertSame( 'users own file', file_get_contents( $this->dir . '/a.webp' ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1001 ) );
		$this->assertNull( $this->attachments->get( 1001 ) );
	}

	public function test_a_file_that_cannot_be_deleted_keeps_its_row_marked_failed() {
		$id = $this->variant( 1002, 'b.jpg.webp' );
		chmod( $this->dir, 0555 );
		if ( wp_is_writable( $this->dir ) ) {
			$this->markTestSkipped( 'Running as a user that ignores directory permissions.' );
		}

		$result = $this->cleanup->cleanup_attachment( 1002 );

		$this->assertTrue( $result->is_failed() );
		$this->assertFileExists( $this->dir . '/b.jpg.webp' );
		$rows = $this->variants->get_for_attachment( 1002 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( VariantStatus::FAILED, $rows[0]['status'] );
		$this->assertSame( 'delete_failed', $rows[0]['reason'] );
	}

	public function test_a_modified_file_is_left_alone_and_no_longer_owned() {
		$this->variant( 1003, 'c.jpg.webp' );
		file_put_contents( $this->dir . '/c.jpg.webp', 'someone replaced this' );

		$result = $this->cleanup->cleanup_attachment( 1003 );

		$this->assertSame( 'hash_mismatch', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( 'someone replaced this', file_get_contents( $this->dir . '/c.jpg.webp' ) );
		$this->assertFalse( $this->variants->owns( $this->relative_dir . '/c.jpg.webp' ), 'The row must not keep claiming a file it no longer owns.' );
	}

	public function test_never_deletes_the_original_or_sizes_of_the_attachment() {
		$id   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$file = get_attached_file( $id );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'x.jpg',
				'relative_path'        => ltrim( substr( $file, strlen( wp_upload_dir()['basedir'] ) ), '/' ),
			)
		);

		$result = $this->cleanup->cleanup_attachment( $id );

		$this->assertSame( 'protected_file', $result->get_data()['skipped'][0]['reason'] );
		$this->assertFileExists( $file );
	}

	public function test_missing_file_still_removes_the_row() {
		$this->variant( 1004, 'd.jpg.webp' );
		unlink( $this->dir . '/d.jpg.webp' );

		$result = $this->cleanup->cleanup_attachment( 1004 );

		$this->assertSame( 'missing_file', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1004 ) );
	}

	public function test_a_row_pointing_outside_uploads_is_removed_and_its_file_is_never_touched() {
		$outside = dirname( wp_upload_dir()['basedir'] ) . '/cleanup-outside-' . wp_generate_uuid4();
		wp_mkdir_p( $outside );
		file_put_contents( $outside . '/g.jpg.webp', 'not ours to delete' );
		$this->variants->upsert(
			array(
				'attachment_id'        => 1007,
				'size_name'            => 'g.jpg.webp',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => '../' . basename( $outside ) . '/g.jpg',
				'relative_path'        => '../' . basename( $outside ) . '/g.jpg.webp',
				'file_hash'            => hash( 'sha256', 'not ours to delete' ),
			)
		);

		$result = $this->cleanup->cleanup_attachment( 1007 );

		$this->assertSame( 'outside_uploads', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( 'not ours to delete', file_get_contents( $outside . '/g.jpg.webp' ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1007 ), 'The row is not parked: nothing reports it.' );

		unlink( $outside . '/g.jpg.webp' );
		rmdir( $outside );
	}

	public function test_a_file_shared_with_another_attachment_is_kept_until_its_last_owner_is_cleaned_up() {
		$this->variant( 1020, 'shared.jpg.webp' );
		$this->variant( 1021, 'shared.jpg.webp' );

		$first = $this->cleanup->cleanup_attachment( 1020 );

		$this->assertSame( 'shared_file', $first->get_data()['skipped'][0]['reason'] );
		$this->assertFileExists( $this->dir . '/shared.jpg.webp' );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1020 ), 'Its own row is removed.' );

		$remaining = $this->variants->get_servable_for_attachment( 1021 );
		$this->assertCount( 1, $remaining, 'The other attachment still has its variant.' );
		$this->assertFileExists( UploadsPath::absolute( VariantRepository::servable_path( $remaining[0] ) ) );

		$img = '<img src="' . wp_upload_dir()['baseurl'] . '/' . $this->relative_dir . '/source.jpg" alt="x">';
		$out = ( new PictureRenderer( $this->variants, new UploadsUrl() ) )->render( $img, 1021 );
		$this->assertStringContainsString( '<picture>', $out );
		$this->assertStringContainsString( $this->relative_dir . '/shared.jpg.webp', $out );

		$this->cleanup->cleanup_attachment( 1021 );

		$this->assertFileDoesNotExist( $this->dir . '/shared.jpg.webp', 'The last owner deletes the file.' );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1021 ) );
	}

	public function test_cleanup_variants_keeps_a_shared_file_too() {
		$this->variant( 1022, 'shared2.jpg.webp' );
		$this->variant( 1023, 'shared2.jpg.webp' );

		$this->cleanup->cleanup_variants( 1022, $this->variants->get_for_attachment( 1022 ) );

		$this->assertFileExists( $this->dir . '/shared2.jpg.webp' );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1022 ) );
		$this->assertCount( 1, $this->variants->get_for_attachment( 1023 ) );
	}

	public function test_a_file_that_is_the_original_of_another_attachment_is_never_deleted() {
		$this->variant( 1030, 'imported.jpg.webp', 'webp', 'original of another attachment' );
		self::factory()->post->create(
			array(
				'post_type'  => 'attachment',
				'meta_input' => array( '_wp_attached_file' => $this->relative_dir . '/imported.jpg.webp' ),
			)
		);

		$result = $this->cleanup->cleanup_attachment( 1030 );

		$this->assertSame( 'attachment_file', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( hash( 'sha256', 'original of another attachment' ), hash_file( 'sha256', $this->dir . '/imported.jpg.webp' ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1030 ), 'The row is removed: it must not claim the file.' );
	}

	public function test_a_row_that_reserved_its_path_but_never_finished_is_removed_without_its_file() {
		file_put_contents( $this->dir . '/reserved.jpg.webp', 'written before the crash' );
		$this->variants->upsert(
			array(
				'attachment_id'        => 1040,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::PROCESSING,
				'source_relative_path' => $this->relative_dir . '/source.jpg',
				'relative_path'        => $this->relative_dir . '/reserved.jpg.webp',
			)
		);

		$this->cleanup->cleanup_attachment( 1040 );

		$this->assertFileExists( $this->dir . '/reserved.jpg.webp', 'A reserved path without a hash does not prove the file is ours.' );
		$this->assertSame( 'written before the crash', file_get_contents( $this->dir . '/reserved.jpg.webp' ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1040 ) );
	}

	/**
	 * Put a file on a path a row reserved without recording a hash.
	 *
	 * @param int         $attachment_id Attachment the row belongs to.
	 * @param string      $name          File name inside the test directory.
	 * @param string      $status        Variant status.
	 * @param string|null $hash          Hash to store. Null leaves the column NULL. An empty string is stored as empty.
	 * @return string Absolute path of the file.
	 */
	private function unconfirmed( $attachment_id, $name, $status = VariantStatus::PROCESSING, $hash = null ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, 'foreign bytes' );
		$row  = array(
			'attachment_id'        => $attachment_id,
			'size_name'            => 'original',
			'format'               => 'webp',
			'status'               => $status,
			'source_relative_path' => $this->relative_dir . '/source.jpg',
			'relative_path'        => $this->relative_dir . '/' . $name,
		);

		if ( null !== $hash ) {
			$row['file_hash'] = $hash;
		}

		$this->variants->upsert( $row );

		return $path;
	}

	public function test_cleanup_leaves_a_foreign_file_on_a_path_reserved_without_a_hash() {
		$path = $this->unconfirmed( 1050, 'foreign-cleanup.jpg.webp' );
		$this->assertNull( $this->variants->get_for_attachment( 1050 )[0]['file_hash'] );

		$result = $this->cleanup->cleanup_attachment( 1050 );

		$this->assertSame( 'unconfirmed', $result->get_data()['skipped'][0]['reason'] );
		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1050 ), 'The row must stop claiming a file it cannot prove it wrote.' );
	}

	public function test_deleting_the_attachment_leaves_a_foreign_file_on_a_path_reserved_without_a_hash() {
		$id   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$path = $this->unconfirmed( $id, 'foreign-delete.jpg.webp' );

		wp_delete_attachment( $id, true );

		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
	}

	public function test_the_uninstall_batch_leaves_a_foreign_file_on_a_path_reserved_without_a_hash() {
		$path = $this->unconfirmed( 1052, 'foreign-uninstall.jpg.webp' );

		$batch = $this->cleanup->cleanup_managed_records_batch( 1051, 1 );

		$this->assertSame( 1052, $batch['cursor_id'] );
		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1052 ) );
	}

	public function test_a_done_row_without_a_hash_does_not_prove_the_file_is_ours() {
		$path = $this->unconfirmed( 1053, 'foreign-done.jpg.webp', VariantStatus::DONE );

		$this->cleanup->cleanup_attachment( 1053 );

		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1053 ) );
	}

	public function test_cleanup_variants_leaves_a_foreign_file_on_a_path_reserved_without_a_hash() {
		$path = $this->unconfirmed( 1054, 'foreign-variants.jpg.webp', VariantStatus::FAILED );
		$rows = $this->variants->get_for_attachment( 1054 );

		$this->cleanup->cleanup_variants( 1054, $rows );

		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1054 ) );
	}

	public function test_an_empty_string_hash_does_not_prove_the_file_is_ours() {
		$path = $this->unconfirmed( 1056, 'foreign-empty-hash.jpg.webp', VariantStatus::PROCESSING, '' );
		$this->assertSame( '', $this->variants->get_for_attachment( 1056 )[0]['file_hash'] );

		$result = $this->cleanup->cleanup_attachment( 1056 );

		$this->assertSame( 'unconfirmed', $result->get_data()['skipped'][0]['reason'] );
		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1056 ) );
	}

	public function test_replaced_files_cleanup_leaves_a_foreign_file_reserved_without_a_hash() {
		$path = $this->unconfirmed( 1055, 'foreign-replaced.jpg.webp', VariantStatus::PENDING );
		$rows = $this->variants->get_for_attachment( 1055 );

		$this->cleanup->cleanup_replaced_files( 1055, $rows );

		$this->assertFileExists( $path );
		$this->assertSame( 'foreign bytes', file_get_contents( $path ) );
		$this->assertCount( 1, $this->variants->get_for_attachment( 1055 ), 'Replaced-file cleanup does not remove the row.' );
	}

	public function test_cleanup_variants_removes_only_the_given_rows() {
		$keep = $this->variant( 1005, 'e1.jpg.webp' );
		$this->variant( 1005, 'e2.jpg.webp' );
		$rows = array_values(
			array_filter(
				$this->variants->get_for_attachment( 1005 ),
				static function ( $row ) {
					return 'e2.jpg.webp' === $row['size_name'];
				}
			)
		);

		$this->cleanup->cleanup_variants( 1005, $rows );

		$this->assertFileExists( $this->dir . '/e1.jpg.webp' );
		$this->assertFileDoesNotExist( $this->dir . '/e2.jpg.webp' );
		$this->assertSame( array( $keep ), array_column( $this->variants->get_for_attachment( 1005 ), 'id' ) );
	}

	public function test_replaced_files_are_removed_without_touching_rows() {
		$id = $this->variant( 1006, 'f.jpg.webp' );
		$old = $this->variants->get_for_attachment( 1006 );

		$this->cleanup->cleanup_replaced_files( 1006, $old );

		$this->assertFileDoesNotExist( $this->dir . '/f.jpg.webp' );
		$this->assertSame( array( $id ), array_column( $this->variants->get_for_attachment( 1006 ), 'id' ) );
	}

	public function test_deleting_the_attachment_removes_variants_files_and_rows() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		file_put_contents( $this->dir . '/g.jpg.webp', 'x' );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'x.jpg',
				'relative_path'        => $this->relative_dir . '/g.jpg.webp',
				'file_hash'            => hash( 'sha256', 'x' ),
			)
		);

		wp_delete_attachment( $id, true );

		$this->assertFileDoesNotExist( $this->dir . '/g.jpg.webp' );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
	}

	public function test_batch_cleanup_walks_attachments_in_order() {
		$this->variant( 1010, 'h1.jpg.webp' );
		$this->variant( 1011, 'h2.jpg.webp' );

		$first = $this->cleanup->cleanup_managed_records_batch( 1009, 1 );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 1010, $first['cursor_id'] );

		$second = $this->cleanup->cleanup_managed_records_batch( $first['cursor_id'], 5 );
		$this->assertTrue( $second['done'] );
		$this->assertFileDoesNotExist( $this->dir . '/h2.jpg.webp' );
	}
}
