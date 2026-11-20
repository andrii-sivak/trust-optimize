---
id: SPEC-gh-19-unconfirmed-file
companions:
  - deletion-paths.md
  - ../../planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md
sources: []
---

# Unconfirmed variant files stay on disk (#19)

## Why

A pain to close before release. A worker reserves `relative_path` when a variant enters `processing` and can die before it records `file_hash`. Cleanup treats that reservation as ownership and deletes whatever file is later found at the path. AD-13 allows deletion only when the row's hash matches the file. The writer already refuses such a target; cleanup does not. The same hole is open for a `done` row that never recorded a hash.

## Capabilities

- **CAP-1**
  - **intent:** Cleanup can delete a generated file only when that row's `file_hash` matches the file at its `relative_path`.
  - **success:** The six reproduction tests in `ImageCleanupServiceTest` leave the file bytes in place. The row is removed when the caller already removes rows for a skip, and kept for replaced-file cleanup.
- **CAP-2**
  - **intent:** Cleanup still deletes a variant file the row provably wrote, and still leaves originals, WordPress sizes, another attachment's original, and a file shared with another variant row.
  - **success:** The existing cleanup tests for a matching hash, a hash mismatch, protected paths, a shared file, and another attachment's original stay green. The attachment-deletion test that omitted a hash records one, and that owned file is deleted.
- **CAP-3**
  - **intent:** A live attempt and its publication keep the rules shipped for #30.
  - **success:** The writer still refuses an unconfirmed target, still records `failed` / `target_exists_foreign`, and still does not delete that file. Death recovery and release still do not delete files. The publication tests stay green without edits.

## Constraints

- Confirmation is a non-empty `file_hash` equal to the SHA-256 of the file at that row's `relative_path`. `NULL` and `''` are both missing and are not confirmation. Status, including `done`, is not confirmation. A row or a reserved path without that hash is not confirmation.
- The guard lives in the existing `ImageCleanupService::remove_file()`. Every caller uses it. A missing hash skips the file with internal reason `unconfirmed`; a mismatch keeps `hash_mismatch`. The row is dropped only when the caller already drops rows for those skips. A `delete_failed` row is still kept.
- No schema change, no new service, and no change to `owns()`, the writer, the publication lease, or the death CAS. The death CAS keeps `relative_path` and `file_hash`.
- Explicit cleanup still drops the row if a publication lease is set. The unconfirmed file stays, so uninstall can finish. The planner already leaves a `processing` row out of deletion, so no lease check is added.
- A killed worker's own unpublished file cannot be told from a later foreign file. Both stay on disk.
- `CHANGELOG.md` under `## [Unreleased]` records the fix. `readme.txt` stays unchanged: no public hook, setting, REST route, or WP-CLI contract changes.

## Non-goals

- Switching the writer's occupied-target outcome from `failed` / `target_exists_foreign` to `skipped`, including how that result is shown and when planning retries it. AD-13 keeps `failed`. That change is Deferred on the spine and is not this fix.
- Proving a killed worker's leftover by anything other than the stored hash.
- Changing `discard_unowned_file()`, which deletes a file the current request just wrote after its row is already gone.
- Clearing `relative_path` or `file_hash` when an attempt dies.
- Changing delivery. A `done` row with a path and a missing hash (`NULL` or `''`) is still served until cleanup drops the row. That limitation is recorded in `deferred-work.md` with the reproduction steps.

## Success signal

The reproduction tests pass, including a SQL `NULL` hash and a `''` hash, the old test that deleted a file because the path was reserved is inverted, owned and protected files behave as they do today, and the #30 publication tests pass without changes.

## Assumptions

- The skip reason `unconfirmed` is internal to the cleanup result. The row is dropped, so the reason is not stored on a variant. `NULL` and `''` are both that missing-hash case. A stored hash that does not match the file stays `hash_mismatch`.
