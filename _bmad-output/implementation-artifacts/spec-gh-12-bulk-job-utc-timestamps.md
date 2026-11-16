---
title: 'Store and compare bulk job timestamps in UTC (GitHub #12)'
type: 'bugfix'
created: '2026-11-16'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BulkJobRepository` writes `started_at`, `updated_at` and `finished_at` in site-local time and computes the stale threshold from local time. When the site offset changes between a write and the stale check (DST switch, or the timezone changed in Settings → General), a running job looks hours old and `recover_stale_running()` pauses it, or a really stale job is detected hours late.

**Approach:** Write every bulk job timestamp in UTC and compute the stale threshold from UTC, as the attachment and variant repositories already do. Convert to local time only for display; nothing displays these fields today.

## Boundaries & Constraints

**Always:**
- Timestamps are written with `current_time( 'mysql', true )`; the threshold is `gmdate( 'Y-m-d H:i:s', time() - $seconds )`.
- REST `GET /trust-optimize/v1/bulk/status` and `wp trust-optimize status` keep returning the same fields in the same `Y-m-d H:i:s` format; only the values become UTC (owner decision).
- `CHANGELOG.md` under `## [Unreleased]`: a `### Fixed` entry (a running bulk job was paused as stale after a DST change or a site timezone change) and a `### Changed` entry (job timestamps in REST and WP-CLI are UTC; rows written before the update keep local time and are not converted, so a running job on a site west of UTC may be paused once after the update and needs Resume).

**Never:**
- No conversion of existing rows, no upgrade routine, no `DB_VERSION` bump (owner decision).
- No schema change, including the `DEFAULT CURRENT_TIMESTAMP` of `updated_at`: every insert sets the column explicitly.
- No change to `update()` semantics (it keeps overwriting `updated_at`), the mutex (`time()` epoch values), field names, the REST/CLI format, or the stale threshold filter.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Offset grows after write | Running job touched with the site at UTC+0, site then switched to UTC+3, `recover_stale_running()` | Job stays `running` (current code pauses it) | N/A |
| Really stale, site west of UTC | Site at UTC−5, running job whose `updated_at` is UTC now − 16 min, `recover_stale_running()` | Job becomes `paused`, returns 1 (current code returns 0) | N/A |
| Fresh job, site west of UTC | Site at UTC−5, running job touched now | Job stays `running` | N/A |
| Written values are UTC | Site at UTC+3; create, mark running, finish a job | `updated_at`, `started_at`, `finished_at` within 5 s of `gmdate( 'Y-m-d H:i:s' )` | N/A |

</frozen-after-approval>

## Code Map

- `includes/bulk/BulkJobRepository.php` -- the only file with the defect. Local-time writes: `create()` (insert `updated_at`), `mark_running()` (`$now`), `pause_unfinished()`, `update()`, `finish()` (`finished_at`), `transition()` (`updated_at`). Threshold: `find_stale_running()` (`strtotime( current_time( 'mysql' ) )`). `get_stale_after_seconds()` and the mutex code stay as they are.
- `includes/storage/AttachmentRepository.php`, `includes/storage/VariantRepository.php`, `includes/capabilities/CapabilityService.php` -- reference pattern, already UTC; do not change.
- `includes/bulk/JobProgress.php::describe()` -- passes the row to REST and WP-CLI unchanged; no change needed.
- `tests/Integration/BulkJobRepositoryTest.php` -- existing repository tests (`row()` helper, `transition()` via reflection); add the matrix tests here.
- `tests/Integration/BulkStatusRestTest.php::test_polling_does_not_pause_an_abandoned_job` -- ages the job through `update()`, which overwrites `updated_at` with now, so the job is never old and the test proves nothing.

## Tasks & Acceptance

**Execution:**
- [ ] `tests/Integration/BulkJobRepositoryTest.php` -- add one test per matrix row; set the site offset with `update_option( 'gmt_offset', … )` and `update_option( 'timezone_string', '' )`, age rows with a direct `$wpdb` UPDATE. Run them before changing the repository: the first two rows must fail, otherwise stop and report. Put the failure output in the report and commit the tests together with the fix -- proves the tests catch the defect without leaving a red commit.
- [ ] `includes/bulk/BulkJobRepository.php` -- switch every write and the threshold to UTC -- the fix.
- [ ] `tests/Integration/BulkStatusRestTest.php` -- age the job with a direct `$wpdb` UPDATE instead of `update()` -- the test must exercise an abandoned job.
- [ ] `CHANGELOG.md` -- the two entries from Boundaries -- documents the behavior change.

**Acceptance Criteria:**
- Given the fix, when `git grep -n "current_time( 'mysql' )" -- includes` runs, then it prints nothing.
- Given the repaired REST test, when it runs, then the job is a day old in UTC and stays `running` after `GET /bulk/status`.

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: no errors
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.4` -- expected: OK
- `tools/bin/test integration 8.0 6.5` and `tools/bin/test integration 8.4 latest` -- expected: OK, including the new tests
- `WP_MULTISITE=1 tools/bin/test integration 8.0 6.5 "--group ms-required"` -- expected: OK

## Implementation Notes

## Spec Change Log

## Review Triage Log
