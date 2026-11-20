---
title: 'Leave a file on a path reserved without a confirming hash (#19)'
type: 'bugfix'
created: '2026-11-20'
status: 'done'
baseline_commit: 'df37a603318694a2fe9a06c7628691872adfb181'
route: ''
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
  - '{project-root}/_bmad-output/specs/spec-gh-19-unconfirmed-file/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-gh-19-unconfirmed-file/deletion-paths.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A worker stores `relative_path` when a variant enters `processing` and can die before `file_hash` is written. `ImageCleanupService::remove_file()` skips the hash compare when the hash is empty and deletes the file at that path. A later file there, and a `done` row that never recorded a hash, are deleted the same way. The writer already refuses that target (AD-13, #30). Cleanup does not.

**Approach:** In the existing `remove_file()`, delete a file only when the row's `file_hash` is non-empty and matches the file. Otherwise leave the file and drop the row only when this caller already drops rows for a skip. No new subsystem and no schema change.

## Boundaries & Constraints

**Always:**
- **Confirmation.** A non-empty `file_hash` equal to the SHA-256 of the file at that row's `relative_path`. Status, including `done`, is not confirmation. A row or a reserved path without that hash is not confirmation. `NULL` and `''` are both a missing hash. The check names both. A missing hash skips as `unconfirmed`. A stored hash that does not match the file stays `hash_mismatch`.
- **One guard.** It lives in `remove_file()`, after the checks that already return `no_file`, `outside_uploads`, `protected_file`, `missing_file`, `attachment_file`, and `shared_file`. `NULL` and `''` return skip reason `unconfirmed`. A present hash that does not match stays `hash_mismatch`. Both leave the file.
- **Rows.** The row is dropped exactly when the caller already drops rows for those skips (`cleanup_attachment`, `cleanup_variants`). `cleanup_replaced_files()` does not drop the row. A `delete_failed` row is still kept, marked `failed` / `delete_failed`.
- **Callers covered by that guard.** Ordinary cleanup, the remove bulk, REST and WP-CLI deletion, `delete_attachment`, and uninstall's `cleanup_managed_records_batch()`. Uninstall still finishes: the row is gone, so `count_removable()` can reach 0, and the unconfirmed file stays.
- **Own files and protected files.** A matching hash is still deleted, including the last owner of a shared path. Originals, WordPress sizes, another attachment's original, and a file another variant row still points at are still kept by the checks above.
- **Attempt and publication (#30).** Do not change `owns()`, `AtomicImageWriter`, `ImageConverter`, the publication lease, the death CAS, or release. The writer still refuses an unconfirmed target and still records `failed` / `target_exists_foreign`. Death recovery and release still do not delete a file. The death CAS still leaves `relative_path` and `file_hash` as they are. The planner still leaves a `processing` row out of deletion, so no lease check is added. `discard_unowned_file()` still deletes a file this request just wrote after its row is already gone.
- **Leftover.** A file the killed worker itself wrote, but never hashed, is indistinguishable and stays on disk.
- **CHANGELOG** `[Unreleased]` → Fixed, in the same commits. `readme.txt` is unchanged.

**Never:**
- Add a service, a column, a hook, or a second ownership check outside `remove_file()`.
- Treat `done`, or any other status, as proof when `file_hash` is empty. The issue's "and is not `done`" exception is not the rule.
- Delete an unconfirmed file so that uninstall has nothing left on disk. The leftover is accepted.
- Change the writer's `failed` / `target_exists_foreign` outcome. Owner decision 2026-11-20: #19 keeps it. `skipped`, how that result is shown, and the retry rules are Deferred on the spine, not this fix.
- Clear `relative_path` or `file_hash` on death, or teach cleanup to recognize a killed worker's own bytes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error handling |
|---|---|---|---|
| Reserved path, NULL hash | `processing`, `relative_path` set, `file_hash` SQL NULL, foreign bytes at the path; `cleanup_attachment()` | the row's hash is null before cleanup; file bytes unchanged; row gone; skip reason `unconfirmed` | — |
| Empty-string hash | `processing`, `relative_path` set, `file_hash` `''`, foreign bytes at the path; `cleanup_attachment()` | the row's hash is `''` before cleanup; file bytes unchanged; row gone; skip reason `unconfirmed` | — |
| Attachment deleted | same row, then `wp_delete_attachment()` | file bytes unchanged; variant rows gone | — |
| Uninstall batch | same row; `cleanup_managed_records_batch()` | file bytes unchanged; row gone | tables may be dropped afterwards, because no row remains |
| `done` without a hash | `done`, path set, `file_hash` null | file bytes unchanged; row gone | — |
| Selected variants | `failed`, path set, no hash; `cleanup_variants()` | file bytes unchanged; row gone | — |
| Replaced source | `pending`, path set, no hash; `cleanup_replaced_files()` | file bytes unchanged; row kept | — |
| Owned variant | `done`, hash matches the file | file deleted; row gone | — |
| Hash mismatch | hash present, bytes differ | file kept; row gone; reason `hash_mismatch` | — |
| Originals and shared files | matching hash, but the path is this attachment's original or size, another attachment's `_wp_attached_file`, or another variant row's path | file kept; existing skip reason; row dropped when the caller drops rows | — |
| Delete fails | hash matches, `wp_delete_file()` leaves the file | file kept; row becomes `failed` / `delete_failed` | — |
| Publication | unconfirmed target during convert, or a recovered attempt | unchanged #30 behavior: file kept, variant `failed` / `target_exists_foreign`, lease and release touch no file | — |

</frozen-after-approval>

## Code Map

- `includes/service/ImageCleanupService.php` — `remove_file()` l.261. After the existing safety checks, `NULL` and `''` return `unconfirmed` at l.308-314. A differing hash stays `hash_mismatch` at l.316. Only a match reaches `wp_delete_file()`. `remove()` drops the row unless the outcome is `failed`. Callers: `cleanup_attachment()` (also `delete_attachment`), `cleanup_variants()`, `cleanup_replaced_files()`, `cleanup_managed_records_batch()`.
- `includes/processing/AttachmentProcessor.php` l.218-223 — planner delete and replaced snapshots.
- `includes/planning/VariantPlanner.php` l.271-298 — a `processing` row is neither deleted nor replaced.
- `includes/bulk/BulkProducer.php` l.429, `includes/api/RestController.php` l.439, `includes/cli/Command.php` l.286 — all call `cleanup_attachment()`.
- `uninstall.php` l.123-150 — batch cleanup; completion is `count_removable() === 0`.
- `includes/files/AtomicImageWriter.php` l.87 `owns_target()` — already requires a hash. Do not change.
- `includes/features/optimization/ImageConverter.php` l.107, l.142, l.277 — owned hash, `target_exists_foreign`, `discard_unowned_file()`. Do not change. The outcome stays `failed` / `target_exists_foreign`.
- `includes/storage/AttachmentRepository.php` `end_attempt()` l.855 — death CAS does not clear `relative_path` or `file_hash`. `derive_state()` l.104.
- `tests/Integration/ImageCleanupServiceTest.php` — `unconfirmed()` covers SQL NULL and `''`. The reserved-path test keeps the file. The attachment-deletion test records a hash and deletes that owned file.
- Verified: `tools/bin/test integration 8.0 7.0 "--filter ImageCleanupServiceTest"` — 21 tests, 77 assertions. NULL and `''` assert `unconfirmed`.

## Tasks & Acceptance

**Execution:**
- [x] `includes/service/ImageCleanupService.php` -- require a matching hash before `wp_delete_file()`; `NULL` and `''` both skip as `unconfirmed` -- closes the hole in the one method every caller uses
- [x] `tests/Integration/ImageCleanupServiceTest.php` -- keep the red tests, including NULL and `''`, invert the reserved-path test, and give the attachment-deletion test a real hash so it still deletes an owned file -- the matrix above
- [x] `CHANGELOG.md` -- `[Unreleased]` / Fixed: cleanup no longer deletes a file whose row has no confirming hash -- same commit as the behavior change

**Acceptance Criteria:**
- Given a row whose `file_hash` is SQL NULL, and a row whose `file_hash` is `''`, when cleanup runs, then both files keep their bytes and both skips are `unconfirmed`.
- Given a `done` row whose hash matches, when the attachment is cleaned up or deleted, then that variant file is deleted and originals, sizes, and shared files are not.
- Given the #30 publication, death, and release tests, when this fix is applied, then they pass without assertion changes.

## Implementation Notes

- Shipped on `fix/gh-19-unconfirmed-file`: `18e5c88` adds the `NULL` / `''` guard, `bbc206c` corrects the comments, `8772ef1` records the spine decision.
- The writer outcome stays `failed` / `target_exists_foreign`. `skipped`, its display, and the retry rules stay Deferred on the spine.
- Accepted limitation, not fixed here: a `done` row with a path and a missing hash is still served until cleanup drops the row. Recorded with reproduction steps in `deferred-work.md`.
- Checks reported on `18e5c88`: phpcs; unit 8.0 and 8.5 (81 tests, 171 assertions each); integration 8.0 / WordPress 7.0 (455 tests, 1671 assertions, 10 skipped); integration 8.5 / latest (455 tests, 1680 assertions, 9 skipped); multisite group on 8.0 / 7.0 (9 tests, 67 assertions). After `bbc206c`: phpcs; `ImageCleanupServiceTest` 21 tests, 77 assertions; `AttemptSafetyTest|ImageConverterTest|UninstallTest` 52 tests, 251 assertions, 1 skipped.

## Spec Change Log

## Review Triage Log

- low — shared hashed owner cleaned before a hashless co-row leaves the proven file with no row. Rejected: duplicated media plus a hashless co-row is uncommon, the leftover is the accepted unconfirmed file, and a foreign file is not deleted. Counting only hashed rows as co-owners would be a new rule, not a direct correction.
- low — REST and WP-CLI still say `deleted_generated_variants` when the file was kept. Rejected: `data.skipped[].reason` is in the same payload, and that message already covered `hash_mismatch` and the other skips. Changing the message would change the public response text.
- low — uninstall summary does not count an unconfirmed skip, then drops the tables. Rejected: completion is `count_removable() === 0`, which the spec requires, and the counters already ignored other skips that keep the file.
- false — a later convert of an orphaned file consumes attempts up to `max_attempts`. A normal `target_exists_foreign` ends the attempt and resets `attempts` (AD-10). The file staying, and the next attempt refusing it, is the #30 rule this fix keeps.
- defer — `VariantRepository::servable_path()` serves a `done` row with a path and no hash. Pre-existing AD-14. This change does not touch delivery. Recorded in `deferred-work.md`.
- false — `hash_file()` failure, `CHAR` padding, and `owns_target()` using `empty()`. The empty-string test shows the column is read back as `''`, which the new guard treats as unconfirmed. An unreadable file with a stored hash already took the `hash_mismatch` branch before this change. `(string) false` stored at publish is `''`, the missing-hash case the spec names.
- low — class, `remove()`, and `remove_file()` comments still described path-only ownership. Patched in `bbc206c`: the comments now require a matching hash and name `NULL` and `''`.
- false — some new tests omit the reason `unconfirmed`, and the code map still describes the red tests. The matrix requires that reason only on the NULL and `''` rows, and those two tests assert it. Updating the code map is an edit to this build's spec, which is not a code fix.

## Verification

**Commands:**
- `tools/bin/test integration 8.0 7.0 "--filter ImageCleanupServiceTest"` -- expected: the class passes, including the NULL-hash and empty-string cases that now fail
- `tools/bin/test integration 8.0 7.0 "--filter 'AttemptSafetyTest|ImageConverterTest|UninstallTest'"` -- expected: pass with no assertion edits in the publication tests
- `tools/bin/composer phpcs` -- expected: clean for the touched PHP
