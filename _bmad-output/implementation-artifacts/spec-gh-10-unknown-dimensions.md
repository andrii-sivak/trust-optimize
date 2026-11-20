---
title: 'Unknown image dimensions still hit the pixel and memory limits'
type: 'bugfix'
created: '2026-11-20'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '72730dbab3b09d3e7016e7f6f0d84d7a3c35fe2e'
context:
  - '{project-root}/AGENTS.md'
  - '{project-root}/_bmad-output/specs/spec-gh-10-unknown-dimensions/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-gh-10-unknown-dimensions/dimension-sources.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `ImageLimits::skip_reason()` treats a non-positive width times height as allowed, so an attachment with no usable dimensions is decoded with no pixel or memory limit. Missing dimensions are not, by themselves, a reason to delete a done variant that still matches its source and whose format is enabled.

**Approach:** When metadata has no two positive sides, read the attached file's JPEG or PNG header and run the existing limit formula. The header is read only in that case. If the header yields nothing, skip with `unknown_dimensions`. Keep a `done` row only when the existing source-size check says the source is unchanged and its format is enabled. `too_large` and `insufficient_memory` still delete every existing variant row.

## Boundaries & Constraints

**Always:**
- A side counts only as an integer `>= 1`. `false`, a non-array, a missing key, zero, a negative, or a non-numeric value is not a size. Normalize metadata before reading an offset.
- Trust two positive metadata dimensions and do not read the header. Read the header of `get_attached_file()` when metadata has no usable dimensions (two integer sides `>= 1`). Do not read `original_image` or a size file.
- Header: `wp_get_image_mime()` is `image/jpeg` or `image/png`, then `wp_getimagesize()`. Any other result is `unknown_dimensions`. Do not call `wp_getimagesize()` otherwise; its HEIC branch opens an editor.
- `skip_reason()` stays pure. A non-positive side returns `unknown_dimensions`, never null. One helper does the header read. Callers: `eligible_source()` and `Inventory::inspect()` only. The pixel filter, `DEFAULT_MAX_PIXELS`, `BYTES_PER_PIXEL`, `MEMORY_SHARE`, and the `memory_limit` comparison stay.
- `unknown_dimensions` inserts nothing and does not call the converter. It deletes every row that is not `done`, a `done` row whose format is not enabled, and a `done` row the existing source-size check calls changed (both `source_file_size` values positive and unequal; a zero on either side is not a change). No new source-change check. A kept `done` row and its file stay and keep being served. A quality mismatch does not remove or re-encode it. The attachment is `skipped` with that reason on the row and the sync result.
- Inventory counts only `too_large` and `insufficient_memory` as `oversized_attachments`. No new snapshot key. Do not write metadata or change an original, a WordPress size, or another plugin's file.
- One Unreleased changelog bullet. The readme FAQ "Why was an image not converted?" names `unknown_dimensions`. No hook, setting, REST, WP-CLI parameter, schema, or AD change. Comments do not cite the issue.

**Never:**
- A per-size check, a new memory formula, pace, or a guard in `ImageConverter`. The worker still converts rows a plan already left pending.
- Distrusting positive metadata that is smaller than the header, or treating a short header as a decompression-bomb detector.
- Making `too_large` or `insufficient_memory` keep `done` variants. Reclaiming actions queued before this plan. Writing discovered dimensions into metadata. A new source-change detector (hash, mtime, or content).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Header over the pixel limit | After a normal upload, replace the attached file with the 17-byte SOF JPEG (companion) at 12000×12000 and clear dimensions, or set both to `-1`. | Skip `too_large`. No editor `load()`. Source bytes and metadata unchanged. `plan_and_enqueue()` does not schedule `HOOK_PROCESS`. | N/A |
| `too_large` removes done | A `done` variant exists, then metadata is 12000×12000. | Skip `too_large`. That row and its file are removed. | N/A |
| Header over memory | Dimensions cleared. SOF JPEG at 3000×3000. `memory_limit` as in the existing memory test. | Skip `insufficient_memory`. No `load()`. | Restore `memory_limit` in `finally`. |
| Header unknown | Non-image bytes as `image/jpeg`, no positive dimensions. | Skip `unknown_dimensions`, state `skipped`, reason stored. No `load()`. Source bytes unchanged. `skip_reason(0, 0)` and `skip_reason(-1, -1)` return `unknown_dimensions`. | N/A |
| Done variant kept | A `done` row whose `source_file_size` equals the current attached file and whose format is enabled. Metadata dimensions are then removed. The attached bytes stay. | On `test-image.jpg` the header is read and is within limits, so this is not `unknown_dimensions`. That row, `relative_path`, and file bytes remain, and that variant is not encoded again. The same keep holds when the header yields nothing: `sync()` skips `unknown_dimensions`, no `load()`, no new row, other rows removed. | A `done` row with both sizes positive and unequal, or with a disabled format, is removed. The comparison is the existing source-size check. Do not replace a valid source with invalid bytes to prove missing dimensions. |
| Header within limits | `test-image.jpg` with width and height removed. The header is read because usable metadata dimensions are absent. | `sync()` succeeds. Original bytes unchanged. | N/A |
| Inventory | The 12000 header with empty metadata, plus the non-image file. The existing three-upload walk. | Oversized includes the header and excludes the non-image file. No `load()`. Existing walk: 1 missing, 1 oversized, 3 eligible, 0 editor calls. | N/A |

</frozen-after-approval>

## Code Map

- `includes/planning/ImageLimits.php` — `skip_reason()` plus the header helper. No editor.
- `includes/planning/VariantPlanner.php` — `eligible_source()` (~374) uses the helper. `plan()` (~94) deletes every existing row. For `unknown_dimensions` only, keep a `done` row when `source_changed()` is false and `OptimizationSettings::is_enabled()` is true. The size passed to `source_changed()` is the size of that row's own `source_relative_path`, measured with `UploadsPath::absolute()` and `wp_filesize()` the way `with_source_sizes()` measures each source file.
- `includes/bulk/Inventory.php` — `inspect()` (~121). Count only `too_large` and `insufficient_memory`.
- Leave `AttachmentProcessor` (`sync()` already returns on a skip; `run()` does not replan), `ImageConverter`, `ConversionQueue::plan_and_enqueue()`, and `VariantRepository::servable_path()`.
- Tests: `tests/Integration/ImageGuardTest.php`, `tests/Integration/InventoryTest.php`. Upload `DIR_TESTDATA . '/images/test-image.jpg'` first. Never `create_upload_object()` the SOF file. The keep-done fixture records `source_file_size` from the current attached file and does not replace those bytes.
- Docs: `readme.txt` FAQ, `CHANGELOG.md` Unreleased. Spine Deferred already lists the five #10 holes. Do not amend an AD.

## Tasks & Acceptance

**Execution:**
- [x] `includes/planning/ImageLimits.php` -- Non-positive sides return `unknown_dimensions`. One helper maps metadata or a JPEG/PNG header through the existing formula.
- [x] `includes/planning/VariantPlanner.php` -- `eligible_source()` uses the helper. For `unknown_dimensions`, the skip branch keeps a `done` row only when the existing source-size check matches and the format is enabled.
- [x] `includes/bulk/Inventory.php` -- Same helper. Count `too_large` and `insufficient_memory` only.
- [x] `tests/Integration/ImageGuardTest.php` -- Matrix rows for the header, the unknown file, the kept `done` variant (same source, enabled format, and the leave-delivery cases), the removed `too_large` variant, and the in-limits JPEG. Spy `load()`, not the editors filter.
- [x] `tests/Integration/InventoryTest.php` -- Inventory row, and the existing walk stays green.
- [x] `CHANGELOG.md` and `readme.txt` -- Unreleased bullet and the FAQ reason.

**Acceptance Criteria:**
- Given the spine Deferred section already lists the five #10 holes, when this change lands, then no AD rule text has changed.
- Given a `done` row remains after `unknown_dimensions`, when that row is passed to `servable_path()`, then the path is returned.

## Implementation Notes

## Spec Change Log

## Review Triage Log

- BH1 / EH1 / EH3 — `high` — `unknown_dimensions_deletions()` passes the attached file's size to `source_changed()` for every row. A done size variant stores its own file's length, so both sizes are positive and unequal and the row is deleted though that file did not change. `PictureRenderer` then drops the format when any srcset candidate is missing. Route: patch.
- BH2 — `false` — Reconcile compares a row's path to the desired path for that same size. This skip has no desired set. Comparing every stored path to the attached path would delete intermediate variants the size check still matches. A missing file measures as 0, and the existing check does not call that a change.
- BH3 — `low`, rejected — Non-done rows, including `processing`, are deleted because the spec says every row that is not `done`. The in-flight overlap is rare, and keeping `processing` would add a branch.
- BH4 / EH2 — `low`, rejected — `positive_side()` truncates non-integers. WordPress stores integer sides. Meeting `1e5` or `1.9` in metadata is not everyday use, and the fix is a new type guard.
- BH5 — rejected, out of scope — An already queued process action is the pending-row hole recorded in spine Deferred. The intent says not to reclaim it.
- BH6 — `false` — The FAQ sentence already said reasons are shown in the Media Library, and the spec only required naming `unknown_dimensions` there. The column shows the state label for every reason, unchanged.
- BH7 — `false` — `eligible_attachments` is the JPEG/PNG count and already includes `too_large`. The spec adds no snapshot key. `unknown_dimensions` stays out of `oversized_attachments`, which the inventory test checks.
- BH8 — `false` — A successful publish stores width and height from the editor save. Attachment metadata is not rewritten, which the spec forbids. A plan row of 0×0 when those keys are absent is the existing `desired_variants()` behavior.
- BH9 — `low`, rejected for the comment — WebP and AVIF bytes are `unknown_dimensions` because the spec reads a header only for JPEG and PNG. The PNG allow-list gap is VG1, not this comment.
- BH10 — `low` — No test holds two in-limit metadata sides over a 12000 header, so a change that always prefers the header would still pass. The code does trust those sides. Route: patch.
- BH11 — `low`, rejected — Header `too_large` and metadata `too_large` share the same delete-all branch. The removal test uses metadata, which is the matrix row.
- BH12 — rejected — The fix would edit the spec companion and the spine scope line.
- VG1 — `medium` — Deleting the `image/png` arm would still pass every new test. PNG is a convertible source. Filed disposition patch. Route: patch.
- VG2 — `medium` — One positive metadata side never reaches the header in a test. Switching `&&` to `||` would still pass and skip a small JPEG as `unknown_dimensions`. Filed disposition patch. Route: patch.
- VG3 — `low` — `inspect()` counts `insufficient_memory`, but every inventory assertion is `too_large`. Narrowing the condition would still pass. Filed disposition patch. Route: patch.
- VG4 — `low` — A `done` row with `source_file_size` 0 is kept only because `source_changed()` treats 0 as not a change. The keep test never plants 0. Filed disposition patch. Route: patch.

## Design Notes

`plan()` returns `new Plan( $reason, array(), $existing )`. For `unknown_dimensions`, drop a `done` row from `$existing` only when its format is enabled and `source_changed()` is false. Compare against the size of that row's own source file, not the attached file's size for every row. `sync()` removes the rest and returns before conversion. Delivery ignores attachment state.

```php
$sof  = "\xFF\xC0" . pack( 'n', 11 ) . "\x08" . pack( 'n', $height ) . pack( 'n', $width ) . "\x01\x01\x11\x00";
$jpeg = "\xFF\xD8" . $sof . "\xFF\xD9";
```

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: clean on the touched PHP
- `tools/bin/test integration 8.0 7.0 "--filter ImageGuardTest|InventoryTest"` -- expected: pass
- `tools/bin/test integration 8.5 latest "--filter ImageGuardTest|InventoryTest"` -- expected: pass
