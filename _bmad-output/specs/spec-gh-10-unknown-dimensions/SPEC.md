---
id: SPEC-gh-10-unknown-dimensions
companions:
  - dimension-sources.md
  - ../../planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md
sources: []
---

# Unknown image dimensions still hit the pixel and memory limits (#10)

## Why

A pain for anyone who converts a library on a memory-limited host. `ImageLimits::skip_reason()` treats a non-positive width times height as allowed, so an attachment with no usable dimensions is handed to the image editor. A large file can exhaust memory and the worker dies, up to three times, before the attachment is marked failed. The limits already exist. They never see this image.

## Capabilities

- **CAP-1**
  - **intent:** An attachment whose metadata has no positive dimensions is still subject to the pixel limit and the memory limit when the attached file's header reports dimensions.
  - **success:** With metadata dimensions cleared, a header of 12000×12000 is skipped with `too_large`. The same header with metadata width and height both set to -1 is also skipped with `too_large`. A header of 3000×3000 under the same tight `memory_limit` as the existing memory test is skipped with `insufficient_memory`. No image editor loads. The attached file's bytes and the attachment metadata are unchanged. `plan_and_enqueue()` does not schedule `ConversionQueue::HOOK_PROCESS`. A done variant that already exists on that `too_large` attachment is still removed, file and row.
- **CAP-2**
  - **intent:** An attachment whose dimensions cannot be established is skipped with an explicit reason and is not decoded.
  - **success:** A non-image file stored as `image/jpeg`, with no positive metadata dimensions, is skipped with `unknown_dimensions` and attachment state `skipped`. No image editor loads. The file bytes are unchanged. `ImageLimits::skip_reason(0, 0)` and `skip_reason(-1, -1)` return `unknown_dimensions`.
- **CAP-3**
  - **intent:** A JPEG or PNG that is within the limits is still converted when only the file header knows its dimensions.
  - **success:** `test-image.jpg` with width and height removed from its metadata converts successfully, and the original file's bytes stay unchanged.
- **CAP-4**
  - **intent:** Library inventory reaches the same oversized decision as planning and does so without decoding the file.
  - **success:** The 12000×12000 header with empty metadata counts as one `oversized_attachments`. The non-image file does not. No image editor loads. The existing inventory walk still reports one missing file, one oversized attachment, three eligible attachments, and zero editor calls.
- **CAP-5**
  - **intent:** An existing done variant that still matches the previous source, and whose format is still enabled, stays in place and is not encoded again when metadata dimensions are unusable.
  - **success:** The done row's `source_file_size` equals the current attached file and its format is enabled. Usable metadata dimensions are then removed. The attached bytes stay as they are. The header is read. On `test-image.jpg` that header is within the limits, so the attachment is not skipped for `unknown_dimensions` and that done variant is not encoded again. The same keep holds when the header yields nothing: `sync()` skips `unknown_dimensions`, `load()` does not run, and no new row is inserted. A done row whose stored size and the current size are both positive and different is removed, and so is a done row whose format is disabled. Both decisions use the existing source-size check and the existing enabled-format check. Replacing a valid source with invalid bytes is not this scenario.

## Constraints

- A side counts only when it is an integer greater than or equal to 1. A missing metadata value (`false` or any non-array), a missing key, zero, a negative, or a non-numeric value is not a size. The product of two negatives is not a size. The shared helper normalizes metadata before any offset is read.
- Two positive metadata dimensions are trusted, and the header is not read. The header is read when metadata has no usable dimensions: two integer sides greater than or equal to 1. The path is `get_attached_file()`. The pre-scale `original_image` is not read. An intermediate size file is not measured.
- The header read accepts `image/jpeg` and `image/png` only, from `wp_get_image_mime()`, then `wp_getimagesize()`. Any other type, a false result, or a non-positive side is `unknown_dimensions`. `wp_getimagesize()` is not called otherwise: its HEIC branch opens an image editor.
- `skip_reason()` stays a pure function of two integers and returns `unknown_dimensions` for a non-positive side, never null. One helper performs the header read. `VariantPlanner::eligible_source()` and `Inventory::inspect()` are its only callers.
- The pixel filter `trust_optimize_max_pixels`, `DEFAULT_MAX_PIXELS`, `BYTES_PER_PIXEL`, `MEMORY_SHARE`, and the `memory_limit` comparison stay as they are. A header that yields positive dimensions uses that formula, so the reason is `too_large` or `insufficient_memory`.
- `too_large` and `insufficient_memory` still pass every existing variant row to deletion. `unknown_dimensions` inserts nothing and does not call the converter. It deletes every row that is not `done`, a `done` row whose format is not enabled, and a `done` row the existing source-size check calls changed: both `source_file_size` values positive and unequal. A zero on either side is not a change. No new source-change check is added. A kept `done` row and its file stay and keep being served. A quality mismatch does not remove or re-encode that row. The attachment is `skipped` with reason `unknown_dimensions`, on the attachment row and on the sync result.
- Inventory counts `too_large` and `insufficient_memory` as `oversized_attachments`, including when the header supplied the dimensions. `unknown_dimensions` does not increment that counter. No new snapshot key.
- Nothing writes attachment metadata or modifies an original, a WordPress size, or another plugin's file. `ImageConverter` grows no second limit check. The queue worker still converts pending rows that planning already created.
- `CHANGELOG.md` gains one bullet under `## [Unreleased]`. The readme FAQ "Why was an image not converted?" names `unknown_dimensions`. No hook, setting, REST route, WP-CLI parameter, or schema change. Comments do not cite the issue or this spec. No AD is amended. The spine Deferred section records the holes this issue leaves.
- The regression tests live in `ImageGuardTest` and `InventoryTest`. The oversized fixture is a real small JPEG upload whose attached file is then replaced with the header-only JPEG in `dimension-sources.md`, and whose metadata dimensions are cleared. `create_upload_object()` is not called on that header file. The editor assertion is that `load()` does not run. The CAP-5 fixture records `source_file_size` from the current attached file and leaves those bytes in place.
- Edits stay in `ImageLimits`, `VariantPlanner` (`eligible_source()` and the skip branch of `plan()`), `Inventory::inspect()`, those two test files, the Unreleased changelog bullet, the one readme FAQ sentence, and the spine Deferred section.

## Non-goals

- The AD-11 resource gate: per-variant park and defer, the one-at-a-time slot, and pace. Those stay with #26.
- A new memory estimate, per-format or per-encoder coefficients, and container headroom. Those stay with #32. `BYTES_PER_PIXEL` and `MEMORY_SHARE` stay put.
- Refusing an intermediate size file on its own dimensions. Recorded in the spine Deferred section.
- Distrusting metadata that already has two positive dimensions when the file header is larger. Recorded in the spine Deferred section.
- Rechecking rows the queue worker already holds, and any scan of attachments queued before this change. Recorded in the spine Deferred section.
- Treating a header that understates the decoded bitmap as a case this check can see. Recorded in the spine Deferred section.
- Changing `too_large` or `insufficient_memory` so they keep done variants. That deletion stays. The divergence from AD-14 is recorded in the spine Deferred section.
- A new way to detect a replaced source. The existing source-size check stays.
- Writing discovered dimensions back into attachment metadata.
- Changing the attempt counter or the poison-file rule.

## Success signal

A JPEG with no metadata dimensions and a 12000×12000 header is skipped with `too_large` before any editor loads, and an existing done variant on that attachment is still removed. The header is read when metadata has no usable dimensions. A file whose header yields no dimensions is skipped with `unknown_dimensions`, and a done variant that still matches that file by the existing source-size check, with its format enabled, keeps its row and its file. A done variant whose source size differs, or whose format is disabled, does not. A normal JPEG with those metadata keys removed still converts, and an already done variant of that same file is not encoded again. Inventory counts only the oversized header as oversized, still without an editor.

## Assumptions

- REST `POST /image/{id}/sync`, `wp trust-optimize sync-attachment`, and `sync --now` call `AttachmentProcessor::sync()`, so they follow the planner check without separate tests.
- The 17-byte fixture measured on the php8.0 and php8.5 tool images does not allocate a 12000×12000 bitmap. `getimagesize()` returns the header dimensions from that file.
- Delivery keeps serving a done row while the attachment is `skipped`, because `servable_path()` reads the variant row and not the attachment state.
