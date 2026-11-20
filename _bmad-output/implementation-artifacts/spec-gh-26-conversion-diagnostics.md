---
title: 'Opt-in conversion diagnostics'
type: 'feature'
created: '2026-11-20'
status: 'done'
route: 'dispatch'
baseline_commit: '0d69d045e2a6c1c3987853d8bd367b946c0cb77a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-gh-26-conversion-diagnostics/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-gh-26-conversion-diagnostics/events.md'
  - '{project-root}/_bmad-output/specs/spec-gh-26-conversion-diagnostics/storage.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A killed encoder leaves no local record. The operator cannot tell a finished variant from one that died inside the editor, and the later resource work has no measurements from real sites.

**Approach:** One opt-in JSONL writer per site under `uploads/to-logs`. It records a flushed start line before the editor opens and a result line for what this `convert()` call returned. Default off. No gate, pace, or new memory estimate.

## Boundaries & Constraints

**Always:**
- Recording is on when `trust_optimize_diagnostics` is absent and when `get_option` returns the string `1`. The stored string `0` is off and survives updates. The existing settings screen checkbox "Keep diagnostic logs" writes that option, not a key of `trust_optimize_options`.
- Off does no new diagnostic measurement and no new event. Existing files stay. Periodic age cleanup still runs.
- `variant_start` is flushed after the source exists and before `get_editor_for_target_mime()`. `variant_result` records this call's status and reason. Match the variant row only when this attempt completed the transition (`done`, `not_smaller`, and `fail()` that updated the row). `not_pending`, `attempt_lost`, and `row_gone` are logged as returned and are not compared to the row. The writer updates no variant or attachment row.
- `conversion_id` is a fresh 16 random bytes per variant attempt, shared by that start and result, not stored, not an auth token, not a hash of the other fields.
- Directory is `wp_upload_dir()['basedir'] . '/to-logs'`, with `.htaccess`, `web.config`, and `index.php`. No secret directory. Never emit a URL.
- Caps: 1280-byte line (skip the whole line if longer), 8 MiB file, 512 MiB site. Size is checked on every write under the lock by deleting oldest rotated files. Age cleanup is at most hourly on `admin_init` for `manage_options` and on uninstall, not on every write. Rotated names are unique within one second.
- Lock try is non-blocking. If it fails, skip the line. Do not hold the lock across the editor or the encoder. Writer time stays inside the existing deadline at `AttachmentProcessor::convert_pending()`; do not add it back.
- A recording error is caught inside the writer and does not reach `convert_claimed()`.
- Uninstall deletes `to-logs` only when `remove_data_on_uninstall` is set and this request drops the site's options, directory first. An early stop that keeps the tables keeps `to-logs`.
- Fields, examples, and honesty rules are `events.md`. Storage and the check table are `storage.md`. AD-18 binds.

**Never:**
- Adaptive pace, the AD-11 gate, `ImageLimits` coefficient changes, AVIF speed, Imagick thread pin.
- A settings checkbox, clear-logs control, new hook, REST field, or WP-CLI command.
- Free-text errors, paths, URLs, file names, the attempt token, or `conversion_id` in a table, option, or transient.
- `fsync`, `sys_getloadavg`, PSI, a watcher process, or treating a missing line as a cause.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Off | Option absent | No `to-logs`. Zero cgroup and CPU samples. Variant matches a run with no writer | N/A |
| Flag | `update_option( ..., '1', false )` | String `1` writes a line. Integer `1` or `true` may still read back as that type in the same request and stay off. `0`, `yes`, `true`, absent stay off. Settings save does not touch the row | N/A |
| Start early | Test editor `load()` | Start line already on disk with `conversion_id`. Result repeats it. Id absent from rows and options | N/A |
| Row match | `done` / `not_smaller` / `fail()` that updated the row | Result status and reason match the row | N/A |
| Unmatched return | `not_pending`, `attempt_lost`, or `row_gone` | Logged as returned. Row need not show that reason. Writer issues no row update | N/A |
| Lock busy | `write.lock` already held | Returns at once, no new line, variant matches off | N/A |
| Write error | `to-logs` unwritable | Variant matches off. No exception escapes | Swallowed in the writer |
| Cap | Site over 512 MiB of rotated files | Oldest rotated file deleted, line appended, variant unchanged. Age cleanup not run by this write | N/A |
| Age | File mtime 61 days | Direct age cleanup deletes it. No Action Scheduler action | N/A |
| Line | Payload over 1280 bytes | Not appended. Previous line still valid JSON | Skip the write |
| Fields | `missing_file` | Reason code present. No path, URL, file name, `message`, or attempt token | N/A |
| Budget | A write during `convert_pending()` | Deadline value is not increased | N/A |
| Names | Two rotations in one UTC second | Two different `.jsonl` names | N/A |
| Sites | Multisite blogs 1 and 2 | Blog 2's line is absent from blog 1's `to-logs` | N/A |

</frozen-after-approval>

## Code Map

- `includes/core/Plugin.php` -- construct one diagnostics writer and pass it into `ImageConverter` and `AttachmentProcessor`. Do not `new` it elsewhere.
- `includes/features/optimization/ImageConverter.php` `convert()` -- start after the source file is known (`wp_filesize` already runs) and before `get_editor_for_target_mime()` near the editor assignment. Result on every return, including `not_pending` before the editor. Do not change transition decisions. `fail()` messages stay out of the log.
- `includes/processing/AttachmentProcessor.php` `sync()` plan skip and `convert_pending()` -- pass `job_id`, `attempts`, and `attempt_source` from the claimed row once per attachment. Deadline is `microtime( true )` plus the budget; leave that assignment alone.
- `includes/admin/Settings.php` -- do not add a checkbox or a key in `trust_optimize_options`.
- `uninstall.php` `trust_optimize_uninstall_site()` -- delete `to-logs` only on the path that drops options, and before `trust_optimize_delete_plugin_options()`. The incomplete-cleanup return keeps the directory.
- `readme.txt`, `CHANGELOG.md` `## [Unreleased]` -- document the string `update_option` and the residual URL risk. No settings-list checkbox.
- `composer.json` classmap -- run `tools/bin/composer dump-autoload` after adding the class.
- Reuse `UploadsPath`, `current_time( 'mysql', true )`, and `wp_json_encode`. Do not add a hook.

## Tasks & Acceptance

**Execution:**
- [x] `includes/diagnostics/ConversionDiagnostics.php` -- one writer: flag read, non-blocking lock, JSONL append, size cap, age cleanup, environment fingerprint -- so the converter and the processor share one implementation
- [x] `includes/core/Plugin.php` -- construct the writer and pass it in -- composition root is the only place that creates it
- [x] `includes/features/optimization/ImageConverter.php` -- emit start and result around the existing editor calls -- without changing outcomes
- [x] `includes/processing/AttachmentProcessor.php` -- emit `attachment_skip` and supply attempt context; leave the deadline assignment unchanged -- planner skips are not starts
- [x] `uninstall.php` -- delete `to-logs` only with the option drop -- a partial uninstall must keep the directory
- [x] `readme.txt` and `CHANGELOG.md` -- document the flag and the residual risk -- public behavior of a new option
- [x] `tests/Integration/ConversionDiagnosticsTest.php` -- cover every matrix row -- the checks are the acceptance
- [x] `tools/bin/composer dump-autoload` -- refresh the classmap -- tests fail on a stale map

**Acceptance Criteria:**
- Given the option is absent, when the test JPEG converts, then no `to-logs` directory exists and the variant matches a run that never constructs the writer.
- Given the string `1`, when a variant finishes, then the start line is on disk before the editor loads and the result line repeats its `conversion_id`.
- Given `not_pending`, `attempt_lost`, or `row_gone`, when the call returns, then the log shows that return and the writer has not updated the row.
- Given the write lock is held or the directory is unwritable, when a variant converts, then the outcome matches the off run and no exception escapes.

## Implementation Notes

- Commit `de49958` on `spec/gh-26-conversion-diagnostics`. Planning artifacts stayed uncommitted.
- Follow-up, uncommitted: `AttachmentProcessor::bind_attempt()` returns before the attachment query when the flag is off.
- phpcs, unit 8.0 and 8.5, integration 8.0/7.0 and 8.5/latest, and the multisite `ms-required` group passed in the implementation run. After the follow-up, `ConversionDiagnosticsTest` passed 23 tests (1 skipped: the multisite case) and the multisite filter of that case passed 1 test, 5 assertions.
- Overhead on tools-php80, not a CI gate: 1000 start+result pairs, median 0.425 ms, p95 0.471 ms. Three WebP syncs off: 9.0, 8.0, 9.1 ms. On: 8.5, 10.6, 9.8 ms. The on run is inside the noise of the encode. `cgroup_memory_current_bytes` is an integer on this stand (`test_readable_cgroup_current_is_an_integer`).
- `getrusage()` is called without `RUSAGE_SELF` when that constant is undefined. The tools PHP 8.0 image defines it; the fallback is the function default.
## Spec Change Log

## Review Triage Log

- high — `ConversionDiagnostics` / `ImageConverter::convert()` — `random_bytes` and environment probes run outside the writer's try, so a throw is caught by `convert_claimed()` and the attachment is finished as `exception`. A recording failure must not change the attempt.
- medium — `ConversionDiagnostics::append()` — a short `fwrite` is flushed and left in the file, so the next line follows a torn JSON object.
- medium — `ConversionDiagnostics` size checks — `filesize` is cached for the request, so one long process can pass 8 MiB and 64 MiB without rotating.
- medium — `maybe_write_environment()` — the env hash is remembered even when the environment line was not written, and later lines cite it.
- medium — `enforce_site_cap()` — if the oldest file cannot be deleted, the loop does not stop.
- medium — `to-logs` as a symlink — create and uninstall follow the link out of the uploads tree.
- medium — `ConversionQueue::plan_and_enqueue()` — a planner skip on upload or bulk never writes `attachment_skip`.
- medium — done + `quality_applied` — the result reason is null while the row reason is the note this call wrote.
- low — rejected — extra assertions for wall time, CLI status, deny-file bytes, header dimensions, hourly `maybe_prune`, and ImageMagick URL stripping. The code paths exist; the missing assertions are not a defect a user meets, and each fix is a new test rather than a direct correction.
## Verification

**Commands:**
- `tools/bin/composer dump-autoload` -- expected: classmap includes the new writer
- `tools/bin/composer phpcs` -- expected: clean on the touched PHP
- `tools/bin/test unit 8.0` -- expected: pass
- `tools/bin/test integration 8.0 7.0` -- expected: pass, including the new diagnostics test
- `tools/bin/test integration 8.5 latest` -- expected: pass
- On `tools-php80`, 1000 start+result pairs with no editor, then three WebP converts of the test JPEG with the flag off and on under the same deadline -- expected: median and p95, the wall delta, and whether the on run returned `more` sooner, recorded in the pull-request text. Not a CI gate. Also confirm `cgroup_memory_current_bytes` is an integer when `/sys/fs/cgroup/memory.current` is readable.
