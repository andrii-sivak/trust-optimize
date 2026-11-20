# Where dimensions come from, and which check they miss

The guard is `ImageLimits::skip_reason()`. It multiplies the two integers it is given. A product of 0 or less returns null, so `trust_optimize_max_pixels` and the memory share (`BYTES_PER_PIXEL` 5, `MEMORY_SHARE` 0.6, PHP `memory_limit`) never run.

## Attached file

`VariantPlanner::eligible_source()` reads `wp_get_attachment_metadata()`. A non-array becomes `array()`. Width and height are `(int) ( $metadata['width'] ?? 0 )` and the same for height. WordPress fills those keys in `wp_create_image_subsizes()` from `wp_getimagesize()`.

They are missing or unusable when:

- metadata was never generated (`wp_get_attachment_metadata()` is `false`);
- generation returned an empty array because `wp_getimagesize()` failed;
- the keys are absent, `0`, negative, or non-numeric.

`Inventory::inspect()` reads the same keys and does not normalize a non-array first. On PHP 8.0 and 8.5, `false['width'] ?? 0` is `0` and raises no warning. The guard then allows the file.

The file this measures is `get_attached_file()`. After WordPress scales a big upload, that path is the `-scaled` file. `original_image` is the pre-scale upload. The converter does not open it. This change does not either.

## Intermediate sizes

`VariantPlanner::desired_variants()` copies `metadata['sizes'][name]['width']` and `height` onto each variant row. Several sizes that share one path keep the first row. `ImageConverter::convert()` does not read those columns before `wp_get_image_editor()`, and `wp_get_image_editor()` calls `load()`, which decodes the bitmap.

Size dimensions are unusable in the same cases as the attached file, per size entry. Nothing passes them to `skip_reason()`. A size is opened only when the attached file's check returned null. After this change, an attached file that is `too_large`, `insufficient_memory`, or `unknown_dimensions` is skipped in `eligible_source()` before any new size row is planned, so those size files are not opened for a new encode. A size whose own dimensions are missing is still opened when the attached file's dimensions are positive and within the limits. That hole is in the spine Deferred section.

## Call sites

| Call | What it passes today | After this change |
| --- | --- | --- |
| `VariantPlanner::eligible_source()` and the skip branch of `plan()` | Attached metadata width and height. Any skip reason passes every existing row to deletion. | Same helper as inventory. The header is read when metadata has no usable dimensions. `too_large` and `insufficient_memory` still delete every existing row. `unknown_dimensions` inserts nothing and does not call the converter. It deletes every row that is not `done`, a `done` row whose format is disabled, and a `done` row the existing source-size check calls changed. A kept `done` row still matches the current file by that check and its format is enabled. |
| `Inventory::inspect()` | Attached metadata width and height. Any non-null reason increments `oversized_attachments`. | Same helper. `too_large` and `insufficient_memory` increment the counter. `unknown_dimensions` does not. |
| `SettingsFormTest` | Direct `skip_reason()` with positive numbers | Unchanged. `skip_reason(0, 0)` and `skip_reason(-1, -1)` return `unknown_dimensions`. |

No other production caller exists.

## Paths that reach the guard

Planning runs before work is queued:

- upload, via `ConversionQueue::handle_new_metadata()` → `plan_and_enqueue()`;
- bulk, via `BulkProducer` → `plan_and_enqueue()`;
- REST `POST /image/{id}/sync`, `wp trust-optimize sync-attachment`, and `sync --now`, via `AttachmentProcessor::sync()` → `plan()`.

`AttachmentProcessor::run()`, which the queue worker calls, does not plan again. It converts pending rows. This change does not add a check there. An attachment that planning skips is not queued, so the worker does not open it. Rows already pending from before this change are still converted. That is a non-goal.

`plan_and_enqueue()` on a skipped plan applies removals and does not schedule `ConversionQueue::HOOK_PROCESS`.

## Header read

`wp_getimagesize()` (`wp-includes/media.php`, since 5.7) calls PHP `getimagesize()`, then header parsers for WebP and AVIF. The HEIC branch calls `wp_get_image_editor()`. JPEG and PNG do not.

The header is read when metadata has no usable dimensions: two integer sides greater than or equal to 1. When those sides are present, the header is not consulted.

The helper therefore calls `wp_getimagesize()` only after `wp_get_image_mime()` returns `image/jpeg` or `image/png`. `wp_get_image_mime()` uses `exif_imagetype()` or a short magic read. Both are header reads.

Checked on `trust-optimize-tools:php8.0` and `:php8.5`. This file is 17 bytes and `getimagesize()` reports the requested width and height with mime `image/jpeg`:

```php
$sof  = "\xFF\xC0" . pack( 'n', 11 ) . "\x08" . pack( 'n', $height ) . pack( 'n', $width ) . "\x01\x01\x11\x00";
$jpeg = "\xFF\xD8" . $sof . "\xFF\xD9";
```

`12000` and `3000` both work. The bytes `not-an-image` make `getimagesize()` and `exif_imagetype()` return false.

`create_upload_object()` on the header file would ask WordPress to generate metadata and can open an editor during the test's setup. The oversized test uploads `DIR_TESTDATA . '/images/test-image.jpg'`, unschedules the process action the upload queued, replaces the attached file with the header-only JPEG, then clears the dimensions. The keep-done test does not use that replacement. It records `source_file_size` from the current attached file and leaves those bytes in place.

The editor spy is a `WP_Image_Editor` subclass registered through `wp_image_editors` whose `load()` counts calls. Listing editor class names, which the capability fingerprint does, does not construct that class. `wp_get_image_editor()` does, and it calls `load()`.
