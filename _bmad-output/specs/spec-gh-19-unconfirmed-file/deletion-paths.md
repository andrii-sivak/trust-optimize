# Deletion paths

Every path below reaches `ImageCleanupService::remove_file()`. Confirmed ownership is checked there once. Callers do not grow a second policy.

A file is confirmed only when the row's `file_hash` is a non-empty string and equals `hash_file( 'sha256', $path )` for that row's `relative_path`. SQL `NULL` and `''` are both missing. The check names both, and both skip as `unconfirmed`. The checks that already run first stay first: empty path, outside uploads, this attachment's original and WordPress sizes, missing file, another attachment's original, a path shared with another variant row.

| Path | Entry | Row after an unconfirmed file | File |
|---|---|---|---|
| Ordinary cleanup of an attachment | `cleanup_attachment()` — also the remove bulk, REST delete, and WP-CLI delete | dropped | kept |
| Selected variants | `cleanup_variants()` — planner rows for a disabled format or a vanished size | dropped | kept |
| Superseded source file | `cleanup_replaced_files()` — snapshot from before the row was re-pointed; `delete_rows` is false | kept | kept |
| Attachment deleted | `delete_attachment` → `on_attachment_deleted()` → `cleanup_attachment()` | dropped | kept |
| Uninstall | `trust_optimize_uninstall_cleanup_generated_files()` → `cleanup_managed_records_batch()` → `cleanup_attachment()` | dropped, so `count_removable()` can reach 0 and the tables can be dropped | kept |

Reproduced red on PHP 8.0 / WordPress 7.0 (`ImageCleanupServiceTest`, 6 failures, 14 existing tests passed): `cleanup_attachment` on `processing`, `wp_delete_attachment`, the uninstall batch, a `done` row with no hash, `cleanup_variants` on `failed`, and `cleanup_replaced_files` on `pending`.

`VariantPlanner::reconcile()` does not put a `processing` row into delete or replaced. A live conversion is not cleaned up by planning. Attachment deletion, the remove bulk, REST, WP-CLI, and uninstall still run during an attempt; they drop the row and, after this fix, leave the unconfirmed file.

`AtomicImageWriter::owns_target()` and `ImageConverter` already refuse to replace a target that has no hash. This companion does not change that refusal, the publication lease, or `discard_unowned_file()`.
