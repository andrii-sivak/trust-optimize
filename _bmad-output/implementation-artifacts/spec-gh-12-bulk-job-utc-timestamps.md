---
title: 'Store and compare bulk job timestamps in UTC (GitHub #12)'
type: 'bugfix'
created: '2026-11-16'
status: 'done'
baseline_commit: '78a9d45aaf5c2912689d8d6dfa088ce9de8ba68c'
route: 'dispatch'
review_loop_iteration: 1
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
- `CHANGELOG.md` under `## [Unreleased]`, exactly this wording (owner-approved after review):
  - `### Fixed`: "A running bulk job was paused as stale after a DST change or a change of the site timezone, and a job that had really stopped could be detected as stale hours late, because job timestamps and the stale threshold used site-local time."
  - `### Changed`: "Bulk job timestamps (`started_at`, `updated_at`, `finished_at`) in the REST API and WP-CLI are now UTC, in the same `Y-m-d H:i:s` format. Values written before the update are not converted."

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
- [x] `tests/Integration/BulkJobRepositoryTest.php` -- add one test per matrix row; set the site offset with `update_option( 'gmt_offset', … )` and `update_option( 'timezone_string', '' )`, age rows with a direct `$wpdb` UPDATE. Run them before changing the repository: the first two rows must fail, otherwise stop and report. Put the failure output in the report and commit the tests together with the fix -- proves the tests catch the defect without leaving a red commit.
- [x] `includes/bulk/BulkJobRepository.php` -- switch every write and the threshold to UTC -- the fix.
- [x] `tests/Integration/BulkStatusRestTest.php` -- age the job with a direct `$wpdb` UPDATE instead of `update()` -- the test must exercise an abandoned job.
- [x] `CHANGELOG.md` -- the two entries from Boundaries -- documents the behavior change.

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

- 364763b: the fix, four matrix tests and the repaired REST test; red phase confirmed (the first two matrix rows and the UTC-write test failed on the old code). 69f373f: review patch (UTC writes of `touch()` and `pause_unfinished()` at offset +3) and the owner-approved CHANGELOG wording.
- Verified locally on PHP 8.0: phpcs clean, unit 80 OK, integration 8.0/6.5 236 OK (8 skipped), multisite 8 OK. PHP 8.4 checks were not run locally (the `tools-php84` image could not be built on a slow network); CI runs them.
- A pre-existing test-isolation leak in the local test database made four unrelated tests fail; recorded in `deferred-work.md`.

## Spec Change Log

- Review pass 1, triage row 3: the CHANGELOG wording in the frozen block named only the west-of-UTC transition effect and only the false-pause symptom. Owner renegotiated the wording (2026-11-16): the Fixed entry names both symptoms; the Changed entry drops the transition effects because no bulk job runs on any installed site at update time. Code was not reverted: the root cause is the CHANGELOG text only, which the code does not depend on (owner-approved). Avoids: a changelog that describes half of the bug and a transition nobody will meet. KEEP: the repository change, the four matrix tests and the repaired REST test as committed in the first pass.

## Review Triage Log

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | edge-case | Pre-update running row on a site east of UTC: a dead job is recovered up to offset hours late and keeps the mutex | medium | Real, but the frozen intent excludes converting old rows (owner decision). Rejected as out of scope by intent; its documentation is row 3. |
| 2 | edge-case | Resumed pre-update job keeps a local `started_at` (COALESCE) next to UTC `updated_at`/`finished_at` | low | Real; nothing in the plugin computes durations. Rejected: intent excludes conversion, and the Changed entry already says pre-update values stay local. |
| 3 | edge-case, blind, verification-gap | CHANGELOG describes only the west-of-UTC effect; east of UTC a dead pre-update job is detected late; the Fixed entry names only the false pause, not the late detection | medium | Real: the owner was told about both directions, the spec's CHANGELOG wording kept only one. Wording sits inside the frozen block -> intent_gap, owner to approve the amended wording. |
| 4 | blind | Schema default `DEFAULT CURRENT_TIMESTAMP` uses the DB session time | low | Pre-existing; `create()` always sets `updated_at`, so the default is never used. Frozen intent forbids schema changes. Rejected. |
| 5 | blind | REST/CLI consumers cannot tell UTC values from old local ones; no `_gmt` names or schema note | low | Owner decision A keeps names and format. Rejected by intent. |
| 6 | blind | No test for an offset that shrinks after a write | low | The fixed code no longer reads the site offset; the west-of-UTC and growing-offset tests already pin the threshold to UTC from both sides. Rejected: unlikely to catch anything the existing tests miss. |
| 7 | blind | The fresh-job test passes on the old code too | false | Intended: it is a matrix row (regression guard for the fix not over-pausing), not a red-phase test. |
| 8 | blind | DST through a named `timezone_string` is not tested | low | `current_time( 'mysql', true )` and `time()` ignore the site zone; a named-zone test exercises the same path as `gmt_offset`. Rejected. |
| 9 | blind, verification-gap | UTC writes of `update()`/`touch()` and `pause_unfinished()` are only exercised at offset 0, so a regression in the producer heartbeat would pass every test | medium | Verified: `test_written_timestamps_are_utc` never calls them under a non-zero offset; `fail`/`cancel` share `finish()` with `complete()` and are covered. Route: patch (test-only). |
| 10 | blind | Tests hard-code `$wpdb->prefix . 'trust_optimize_jobs'` | low | Same as the existing `row()` helper; `get_table_name()` is private; a rename fails loudly. Rejected. |
| 11 | blind | `tear_down()` deletes the options instead of restoring them | low | The test case rolls back the database and flushes the cache in `set_up()`; deleted options read as offset 0, the default. Harmless. Rejected. |
| 12 | verification-gap | The old REST test never aged the job; the new one does | false | Not a defect: confirms the intended repair. |
