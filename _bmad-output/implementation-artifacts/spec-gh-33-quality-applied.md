---
title: 'Apply the configured WebP/AVIF quality when saving a variant (#33)'
type: 'bugfix'
created: '2026-11-19'
status: 'done'
baseline_commit: 'c14c7f882c727ab505fbb28d77c961de6855e4d2'
route: ''
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The WebP and AVIF quality from the settings, or from `trust_optimize_{format}_quality`, has no effect.
- `ImageConverter::convert()` calls `set_quality( $q )`, then saves to the target MIME. In WordPress, `WP_Image_Editor::get_output_format()` calls `set_quality()` without an argument whenever the output MIME differs from the source's. The editor then takes the core default for the output MIME through `wp_editor_set_quality`: 86 for WebP, 82 for AVIF.
- The variant row stores the requested value, so the database claims a quality the file does not have, and staleness, which compares that value with the settings, never notices.
- Confirmed on 2026-11-19 in PHP-FPM with GD WebP/AVIF and Imagick WebP: settings 30, 60 and 90 all gave files byte-identical to encodes at 86/82.

**Approach:** Around the save of our variant only, a `wp_editor_set_quality` callback returns the requested quality for the target MIME. The callback is removed in `finally`. After the save, the converter reads the quality the editor applied and records a deviation without failing the variant.

## Boundaries & Constraints

**Always:**
- **Scope of the override.**
  - The callback is added right before `AtomicImageWriter::write_temporary()` and removed right after it, in `finally`. That covers a `WP_Error` and an exception.
  - It is added at `PHP_INT_MAX`.
  - It returns the requested quality only when `$mime_type` equals the target MIME. Otherwise it returns the value it received.
  - Only that callback instance is removed, and other callbacks on the filter are left alone.
- **Explicit quality first.** `set_quality( $quality )` is still called before the save.
  - When it returns `WP_Error`, the variant fails with reason `quality_failed` and the error message (owner decision). Nothing is encoded, a path this run reserved is released, and the override is never added.
  - Why: on Imagick, a failed `setImageCompressionQuality()` leaves the editor reporting the requested value while the encoder keeps the old one.
- **What the row means.**
  - `quality` stays the settings quality the variant was made for. It is the AD-14 staleness key, and `is_stale()`, `count_outdated()` and the planner are unchanged.
  - After a successful save the converter reads `$editor->get_quality()`. When it equals the request, the `done` row's reason is NULL, as today. When it differs, the `done` row's reason is `quality_applied:<n>` (owner decision: no new column, and visible only in the database for now, with no new UI).
  - A deviation never fails, skips or re-plans the variant, so a later sync with the same settings answers `up_to_date`.
- **Skipped variants.** A `skipped` / `not_smaller` row keeps that reason. Its file is deleted, and no deviation is recorded.
- **Every conversion path** (upload queue, bulk, REST and WP-CLI sync) goes through `ImageConverter::convert()`. The fix changes that path in place, with no parallel old implementation.
- **CHANGELOG** `[Unreleased]` → Fixed, in the same commits.
  - The configured WebP/AVIF quality is now applied. Before, WordPress replaced it with its own default, usually 86 (WebP) and 82 (AVIF), since a third-party `wp_editor_set_quality` filter could change that default. The variant rows meanwhile showed the configured value.
  - Existing variants are not re-encoded automatically and still look current. To apply the quality to them, remove their variants and convert again.
  - Files can get larger when the configured quality is above those defaults.

**Never:**
- Change WordPress core, subclass or replace its editors in plugin code, or encode with GD or Imagick directly.
- Keep the override registered outside our save, or remove callbacks that are not ours.
- Reject a successful encode because of the applied quality.
- Migrate stored quality, mark existing variants outdated, or start reprocessing on install or update. The installs are test sites, and the owner reprocesses old variants separately.
- Touch originals or files without confirmed ownership (AD-13).
- Add a setting, a public hook, a schema change or general logging. AVIF speed (`heic:speed`), resource gate, pace and unrelated refactoring are out of scope.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error handling |
|---|---|---|---|
| Quality applied | WebP or AVIF, GD or Imagick (where the runtime can write it), test fixture, settings 30, then 90 | two different files; for this fixture the 30 one is smaller (a property of the fixture, not a general rule); the row has quality 30, then 90, reason NULL | — |
| Filter quality | `trust_optimize_webp_quality` returns 40 | encoded and recorded at 40 | — |
| Override gone after success | a conversion finished | the original behavior is back: `wp_editor_set_quality` gives, for each MIME, what it gave before the conversion, and a later core save without an explicit quality gets the same quality as one made before the conversion | — |
| Override gone after an error | the save returns `WP_Error`, or an exception is thrown inside the save | same as the row above | variant `failed` as today; the exception propagates |
| Third-party callback | a callback on `wp_editor_set_quality` (default priority and `PHP_INT_MAX`) was registered before | it is still registered afterwards, and the filter's results and core saves match those from before the conversion | — |
| Isolated defaults | a test that removes every other callback from `wp_editor_set_quality` | after the conversion, a core save gets 86 for WebP and 82 for AVIF; literal defaults appear only in this test | — |
| Other MIME during our save | the filter is asked for `image/jpeg` while our save runs | the value passes through unchanged | — |
| Deviation | the editor applies 77 where 30 was requested | `done`, file published, quality 30, reason `quality_applied:77`; the next sync gives `up_to_date` | — |
| `set_quality` error | the editor returns `WP_Error` for the requested value | `failed` / `quality_failed`, no file, reserved path released, no override registered | — |
| Not smaller | the output is not smaller than the source | `skipped` / `not_smaller` with the requested quality (as today) | — |

</frozen-after-approval>

## Code Map

- `includes/features/optimization/ImageConverter.php`:
  - `convert()`: l.120 quality, l.133 `set_quality`, l.140 `write_temporary`, l.156 `skipped` row, l.200-214 `done` row.
  - `fail()` (l.258) releases the reserved path.
- `includes/files/AtomicImageWriter.php:46-75` -- `write_temporary()`, the only `save()` call. Unchanged.
- `includes/settings/OptimizationSettings.php:72-91,164-172` -- requested quality and staleness. Unchanged.
- `includes/storage/VariantRepository.php:446-475` -- `count_outdated()`. Unchanged.
- `tests/Integration/ImageConverterTest.php` -- `settings()` helper at l.113; quality assertions at l.148 and l.400.
- Core references (WP 7.1.2): `class-wp-image-editor.php` `get_output_format()` l.410-419, `set_quality()` l.242-305, `get_default_quality()` l.317-329.

## Tasks & Acceptance

**Execution** (one commit per bullet):
- [x] `ImageConverter.php` + `ImageConverterTest` -- scoped override around `write_temporary()`, `quality_failed` on a `set_quality()` error; tests for the matrix rows on quality and override lifetime, per available editor × format via a test-only `wp_image_editors` list, skipped where the runtime cannot write the format; CHANGELOG.
- [x] `ImageConverter.php` + `ImageConverterTest` -- applied quality read after the save, `quality_applied:<n>` on a deviating `done` row (test editor subclass), and the next sync gives `up_to_date`; CHANGELOG.

**Acceptance Criteria:**
- Given each editor and format the test runtime can write, when the test fixture is converted with quality 30 and with 90, then the files differ, and each row records its request. For this fixture the 30 file is also smaller, which is checked as a property of the fixture, not as a general rule. On `main` this test fails.
- Given any outcome of `convert()` (done, skipped, failed, an exception), when it returns or throws, then `wp_editor_set_quality` behaves as before the conversion, third-party callbacks included.

## Implementation Notes

- Commits: 61ed8f9 (task 1: override around `write_temporary()`, `quality_failed`), 901b624 (task 2: `quality_applied:<n>`), 205f992 (tests: the filtered quality comes from `OptimizationSettings::from_options()`, the skipped row records its quality).
- The applied quality is read with `$editor->get_quality()` right after the save, before the not-smaller branch. Only the `done` write uses it. A `skipped` row keeps `not_smaller`.
- Incident, 2026-11-19: a first draft of `test_the_override_passes_other_mime_types_through` had a spy on `wp_editor_set_quality` that fired the same hook, which is unbounded recursion. wp-phpunit defines `WP_MEMORY_LIMIT` as -1, and the tools containers have no memory limit, so two full `ImageConverterTest` runs exhausted host memory (8.5 GB). Now the spy only records calls. The other-MIME check runs from `image_save_progressive`, which GD fires inside the save while the override is in force.
- Two draft test bugs were fixed:
  - the erroring and throwing editors collided on one attachment ID;
  - the refusing editor also refused the default quality `load()` resolves, which gave `no_editor`.
- Red checks: with `main`'s converter, all four editor × format cases fail with "Quality 30 and 90 must give different files" (Imagick AVIF included). With task 1 only, the deviation test fails on the reason.
- Every run used a per-run `docker run` wrapper with the tools image and the stand's network and volumes. Limits: 1–2 GB with no swap, 256 PIDs, a `timeout`. Its limits were checked on synthetic load first. The shared `docker-compose.yml` was not changed.
- The test database held one leaked pending `trust_optimize_restore_queue` action from 2026-11-18. It caused 14 queue-signal failures in the 8.5 integration run. It was removed with the AGENTS.md command, and the run was repeated.

- Stand check, 2026-11-19 (owner in wp-admin):
  - **Build.** `scripts/build-release.sh` timed out in `composer install` (slow network), so the stand copy `../trust-optimize` got `git archive` of 40cc1e1 over the #34 build. That is equivalent: `composer.lock` was unchanged, no class was added, and only `ImageConverter.php`, `readme.txt` and `CHANGELOG.md` differed. Backups: stand `backups/*before-gh33-20261119-2305*`.
  - **Quality 30, CLI.** Owner upload `test_1.jpg` (ID 272, 403 938 B); the queue converted it in the cron container (CLI, GD). The original's WebP (59 724 B) and AVIF (33 940 B) are byte-identical to GD encodes at 30, not at 86/82 (164 134 / 188 246 B). Rows: quality 30, reason NULL.
  - **Quality 90, PHP-FPM.** `POST /image/272/sync` from wp-admin answered `optimized`. The original's WebP (Imagick, 204 844 B) and AVIF (GD, 295 510 B) are byte-identical to WordPress editor encodes at 90, not at 86/82. Rows: quality 90, reason NULL. The smaller sizes became `skipped` / `not_smaller` at 90, and their quality-30 files were removed, as before #33.
  - **Repeat.** A second sync answered `up_to_date`: same hashes and variant timestamps, no new `debug.log` lines.
  - **Cleanup.** The test image and its variants were deleted, and the settings went back to 85/78. The stand keeps running the #33 build.
- Bulk check, 2026-11-19 (owner, settings WebP 80 / AVIF 62):
  - **Run.** Sync job #20 re-encoded the stand library through the queue (cron, CLI, GD) in 4 minutes: 28 images, 354 variants. All are `done` with quality 80/62; no `not_smaller`, `failed`, `quality_failed` or `quality_applied`.
  - **Proof.** For 2 PNG and 2 JPEG originals, WebP and AVIF are byte-identical to WordPress GD encodes at 80/62, not at 86/82.
  - **Totals.** WebP went from 10.63 MB to 7.94 MB (−25 %) and AVIF from 10.48 MB to 5.68 MB (−46 %). Sources total 105.7 MB. The variants before were made by earlier builds, at 86/82.
  - **AVIF vs WebP.** AVIF is now smaller than WebP for all 28 originals and all 149 sub-sizes.
  - **Health.** Cron container memory peak 345 MiB (cap 1 GiB), no OOM, no new `debug.log` lines. The settings stay at 80/62.

## Review Triage Log

Review 1 (diff c14c7f8..5db2bcf). Layers: blind-hunter (BH), edge-case-hunter (EC), verification-gap (VG).

The patches are fixed in 8d1e453 (refactor: the `only_editor()` test helper) and 03326bb (the remaining patches). With the override priority lowered to 10, the third-party test fails, so the new assertion catches a reordering. After the patches the spec's Verification passed: phpcs; unit 8.0/8.5 (81 tests each); integration 8.5 latest (448 tests, 9 skipped) and 8.0 / 7.0 (448, 10 skipped). Nothing was deferred.

| Finding | Layers | Verdict | Evidence | Route |
|---|---|---|---|---|
| The `PHP_INT_MAX` override is never shown to beat a third-party `PHP_INT_MAX` callback. The test with `$late_cb` (71 for WebP) asserts no row quality or reason. | VG, BH | medium | Lowering the priority to 10 leaves every test green and yields `quality_applied:71`. | patch |
| `readme.txt`: the quality filters line does not say that core's `wp_editor_set_quality` no longer changes variants; the FAQ omits `quality_failed`. | BH | low | readme.txt:164 and :64. AGENTS.md wants the readme to follow behavior changes integrators see. | patch |
| CHANGELOG says "remove their variants" without naming how. | BH | low | The actions exist: "Remove optimized files" on the Bulk tab, and `wp trust-optimize remove` / `remove-attachment` (readme.txt:114, :142-143). | patch |
| The comment above `set_quality()` explains the refusal poorly. | BH | low | The real reason: an editor that rejects the value cannot be trusted to encode with it. On Imagick it keeps reporting the requested value. | patch |
| CHANGELOG wording: "since" reads as a cause, and "encodes with another quality" overstates what is detected. | BH | low | The detection is `$editor->get_quality()`, the value the editor reports. | patch |
| AC "any outcome … skipped" has no override-lifetime test for `not_smaller`. | BH | low | Removal happens in `save_with_quality()`'s finally before the branch, but no test covers it. | patch |
| The deviating-editor test has no skip guard when GD cannot write WebP. | BH, EC | low | ImageConverterTest.php deviating test; the other new tests skip. | patch |
| The "offer only editor X" closure is repeated four times. | BH | low | AGENTS.md: extract on the third repetition (`use_editor`, the failed-save loop, the refusing test, the deviating test). | patch |
| Test helpers call `save()` on a possible `WP_Error` from `wp_get_image_editor()`. | BH, EC | low | `core_quality_behaviour()` and the defaults test: a fatal instead of a clear failure. | patch |
| `save_with_quality()` declares no parameter types. | BH | low | The wrapped `write_temporary()` types `WP_Image_Editor`. | patch |
| The override closure requires two arguments: `apply_filters( 'wp_editor_set_quality', $q )` during the save throws `ArgumentCountError`. | EC | low | WP_Hook passes only the given arguments when `accepted_args` ≥ their count (class-wp-hook.php:352). | patch |
| `quality_applied:<n>` is visible only in the database. | BH | false | The owner accepted database-only visibility, and the CHANGELOG promises no UI. | reject |
| A deviation is not recorded on `skipped` rows. | BH, EC | false | The frozen intent states that `skipped` keeps `not_smaller` with no deviation. | reject |
| The defaults test hard-codes 86/82. | BH | false | The owner allowed literal defaults in the isolated test only. | reject |
| The memory-exhaustion hazard is recorded only in the spec. | BH | false | Resolved outside this diff: per-container caps on the stand and `chore/tools-resource-limits`. | reject |
| Imagick may list AVIF but fail to encode it, so the matrix fails instead of skipping. | EC | low | Not reproducible in the test images (Imagick AVIF encodes on 8.0 and 8.5). Unlikely, and the fix needs a probe write. | reject |

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: clean.
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.5` -- expected: pass.
- `tools/bin/test integration 8.0 7.0` and `tools/bin/test integration 8.5 latest` -- expected: pass. Imagick AVIF is covered in both images, and GD AVIF only on 8.5.

**Manual checks:**
- On the test site, PHP-FPM: sync one test image at two qualities. The variant sizes differ, and the rows record the requests. Then remove the test image.
