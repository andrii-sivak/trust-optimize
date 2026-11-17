---
title: 'Jobs table: pause_reason column, dead counters dropped (schema 1.1.0)'
type: 'feature'
created: '2026-11-17'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A paused job stores why it was paused in `last_error` (`deactivated`, `low_disk_space`, the stale-recovery sentence), so no query can select jobs by pause reason and the reason mixes with real errors. Six columns (`processed`, `skipped`, `failed_count`, `created_count`, `deleted_count`, `profile_hash`) are never written; progress comes from `JobProgress`.

**Approach:** Schema 1.1.0 per spine AD-17, AD-7 and AD-2: add `jobs.pause_reason`, move legacy reasons out of `last_error`, drop the six columns. It is one repeatable migration, and the version is stored only after every step succeeded. Every pause writer writes `pause_reason`. This PR merges before the #26, #30 and #34 changes that use the column.

## Boundaries & Constraints

**Always:**
- **`pause_reason` values.** `pause_reason` is `varchar(32) NULL` and takes exactly these values: `user`, `deactivated`, `low_disk_space`, `stopped`, `unknown`.
  - It is non-NULL only while the status is `paused`.
  - Every transition out of `paused` sets it to NULL: Resume, and cancel or finish from a pause.
- **Pause writers.**
  - A user pause (admin, REST, WP-CLI) writes `user`.
  - Deactivation writes `deactivated`.
  - The low-disk pause writes `low_disk_space`.
  - Stale recovery writes `stopped`.
  - None of them writes a reason into `last_error`.
- **A pause always lands, on either schema.**
  - Every pause writer changes the status to `paused`, even when the site's stored schema version is still below `1.1.0` (the migration has not finished).
  - In that state it writes the 1.0.0 shape: the status, plus the reason in `last_error` exactly as 1.0.0 did (a user pause leaves `last_error` untouched). The next successful migration maps that row like any legacy row.
  - The writers decide by the stored `trust_optimize_db_version`, not by a per-write `SHOW COLUMNS`.
- **Legacy mapping for paused rows with NULL `pause_reason`.**
  - Each rule is one UPDATE that sets `pause_reason` and `last_error` together, guarded by `status = 'paused' AND pause_reason IS NULL`. The rules run in this order:
    1. `last_error` = `deactivated` or `low_disk_space` (exact) → that reason, and `last_error` becomes NULL.
    2. `last_error` NULL or empty → `user`, because every other pause writer stores a non-empty `last_error`.
    3. Anything else → `unknown`, and `last_error` is kept.
  - On rows in any other status, the two exact codes are cleared from `last_error`, because they are not errors.
- **Repeatable migration.**
  - `dbDelta()` reports no failure. After it runs, `SHOW COLUMNS` must confirm `pause_reason`; only then come the backfill, the drops and the version.
  - Each drop checks the live schema first (`SHOW COLUMNS`); MySQL 5.7 has no `DROP COLUMN IF EXISTS`.
  - A second run is a no-op.
  - `trust_optimize_db_version` becomes `1.1.0` only after every step succeeded. A failed step leaves it unchanged, and the next request retries.
- **Output fields.** Admin, REST and WP-CLI keep every progress field, filled by `JobProgress::describe()`. The REST and CLI job gains `pause_reason` and loses `profile_hash`. Readers use `?? null` for `pause_reason`, so a site whose migration failed shows no warnings.
- **Docs.** `readme.txt` and `CHANGELOG.md` are updated in the same commit as the behavior they describe.
- **Panel (owner decision).** While the job is paused, the admin panel shows a "Paused because" row with a translated text per `pause_reason`. New pause reasons are stored apart from `last_error`. Existing `last_error` text, such as an old stale-recovery message, is kept and still shown as "Last error". REST and WP-CLI expose the raw `pause_reason`.

**Never:**
- No notices, auto-resume, parking, liveness or gate changes. Those belong to #26, #30, #34 and #35.
- No change to the attachments or variants tables.
- No `DROP COLUMN IF EXISTS`, and no data loss in `last_error` beyond the two exact legacy codes.
- No network-wide migration loop: sites migrate lazily through `check_version()`.
- No writer for `settings_snapshot.metrics`. It has no current user, and the JSON column needs no schema change.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error handling |
|---|---|---|---|
| Fresh install | no tables | 1.1.0 schema without the six columns, version `1.1.0` | — |
| Upgrade | 1.0.0 table, paused jobs with `deactivated` / `low_disk_space` / NULL / stale sentence | columns dropped; reasons `deactivated` / `low_disk_space` / `user` / `unknown`; non-paused rows NULL | — |
| Second run | already migrated | nothing changes, version stays `1.1.0` | — |
| Concurrent first requests | two requests migrate at once | both finish; final schema identical | a DROP failing because the column vanished counts as a failed step for that request only; the next request completes |
| Step fails | `pause_reason` missing after dbDelta, or an ALTER returns false | version stays `1.0.0`, error not fatal | retried on next request; until it succeeds, reads fall back to NULL and pause writers use the 1.0.0 shape, so a low-disk pause, a deactivation or a stale recovery still leaves the job `paused` |
| Ambiguous legacy row | job resumed after a low-disk or deactivation pause, then paused by the user (Resume never cleared `last_error`) | mapped to `low_disk_space` / `deactivated` | accepted: no data tells it apart; Resume is manual either way |

</frozen-after-approval>

## Code Map

- `includes/database/DatabaseManager.php`:
  - `DB_VERSION` becomes `1.1.0`.
  - In the jobs `CREATE TABLE` (lines 53-74), drop the six columns and add `pause_reason`.
  - `check_version()` stores the version only after `create_tables()`, the `pause_reason` column check and the migration steps succeed.
  - Reuse `table_exists()` and `get_plugin_table_names()`.
  - Expose whether the stored version equals `DB_VERSION`, for the pause writers.
- `includes/domain/` — new `PauseReason` final class with the five constants and `COLUMN_LENGTH`, like `JobStatus`.
- `includes/bulk/BulkJobRepository.php`:
  - `UPDATABLE_COLUMNS`: remove the six columns, add `pause_reason`.
  - `pause()` (l.315) takes a `PauseReason` value, default `user`.
  - `pause_unfinished()` (l.331) keeps its `$reason` parameter, now a `PauseReason` value; its only caller passes `deactivated`. It and `recover_stale_running()` (l.463, which stops appending to `last_error`) write `pause_reason`.
  - `mark_running()` (l.278) and `finish()` (l.535) set `pause_reason = NULL`.
  - Every writer that touches `pause_reason` falls back to the 1.0.0 shape while the schema is not current. The fallback matters most in `BulkProducer::produce()` (l.250: it pauses for low disk and returns without rescheduling) and in `Lifecycle::deactivate()` (l.75: it unschedules the group before pausing).
- `includes/bulk/BulkProducer.php`:
  - `REASON_LOW_DISK` (l.64) is replaced by `PauseReason::LOW_DISK_SPACE`.
  - `pause()` (l.203) passes `user`.
- `includes/queue/Lifecycle.php:84` — the job pause uses `PauseReason::DEACTIVATED`. `Lifecycle::REASON` stays for attachments.
- `includes/admin/Notices.php:224` — `paused_for_disk()` reads `pause_reason`.
- `includes/bulk/JobProgress.php:53` — `describe()` already merges the counters over the row; keep it as is.
- `assets/js/admin.js:249-254`, `templates/admin/admin-page.php:168` — the counters already come from `describe()`. Add the "Paused because" row next to "Last error", hidden unless `pause_reason` is set; its texts go through the i18n object in `includes/admin/Admin.php` (~l.462).
- Tests:
  - `tests/Integration/DatabaseUpgradeTest.php` asserts `1.0.0` at line 58; update it and add the migration cases.
  - Adjust `BulkProducerTest.php:418`, `LifecycleTest.php:88`, `NoticesTest.php:105` and `BulkJobRepositoryTest.php`.
- `readme.txt:135` lists routes only. Add one sentence after it: the bulk job object includes `pause_reason` and no longer `profile_hash`. Also `CHANGELOG.md` `[Unreleased]`.
- `tests/Unit/StatusLengthTest.php` — add `PauseReason`.

## Tasks & Acceptance

**Execution:**
- [ ] `includes/domain/PauseReason.php` -- add the constants -- one vocabulary for writers and readers
- [ ] `includes/database/DatabaseManager.php` -- new schema plus repeatable `migrate_1_1_0` steps (backfill `pause_reason`, drop the six columns when present); store the version only on success -- AD-17
- [ ] `includes/bulk/BulkJobRepository.php`, `BulkProducer.php`, `queue/Lifecycle.php`, `admin/Notices.php` -- write and read `pause_reason`, clear it when leaving `paused` -- AD-7
- [ ] `templates/admin/admin-page.php`, `assets/js/admin.js`, `includes/admin/Admin.php` -- "Paused because" row with translated texts for the five reasons -- owner decision
- [ ] `tests/Integration/DatabaseUpgradeTest.php` -- the matrix
  - Start from a real 1.0.0 jobs table: drop `pause_reason`, re-add the six columns, set the version to `1.0.0`, and insert the legacy rows of the matrix.
  - Upgrade, then run a second time.
  - Force a failed `ADD COLUMN` with a `query` filter: the version stays `1.0.0`. Remove the filter, re-run, and the migration completes at `1.1.0`.
  - DDL commits implicitly, so restore the schema and delete the rows in `finally`, as `test_a_current_schema_is_left_alone` does.
- [ ] `tests/Integration/*`, `tests/Unit/StatusLengthTest.php` -- update the pause assertions to `pause_reason`; assert NULL after Resume and after cancel from a pause; add a stale-recovery test (`stopped`, `last_error` not appended); cover `PauseReason` lengths
- [ ] `readme.txt`, `CHANGELOG.md` -- REST job gains `pause_reason` and loses `profile_hash`; changelog says replacing the plugin with an older version after this update is not supported, and rollback uses a consistent backup of files and database

**Acceptance Criteria:**
- Given a 1.0.0 multisite network, when a subsite gets its first request after the update, then only that site's jobs table is migrated and its version is `1.1.0`.
- Given a paused job, when it is resumed or cancelled, then `pause_reason` is NULL.
- Given any job, when REST `GET /bulk/status` or `wp trust-optimize status` returns it, then the progress fields are present with the same values as before. Key order may change.
- Given a running job past the stale threshold, when stale recovery runs, then it is paused with `stopped` and `last_error` is unchanged.
- Given a sync job, when the disk runs low, then it is paused with `low_disk_space` and the low-disk notice still shows.
- Given a site whose migration has not finished, when the disk runs low or the plugin is deactivated, then the job is `paused` with the reason in `last_error`, and a later successful migration maps it to `pause_reason`.
- Given the admin panel, when the job is paused, then the "Paused because" row shows the reason text (a generic text for an unrecognized value); otherwise the row is hidden.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Design Notes

Accepted risks:
- A migration that fails permanently retries `dbDelta()` and the ALTERs on every request, front end included. Today `check_version()` already runs on every request; only the failing case does extra DDL.
- The local stand runs MariaDB. MySQL 5.7 is covered by the CI matrix (`.github/workflows/ci.yml`, `mysql:5.7` with PHP 8.0 and WP 7.0), and that run is the compatibility check.

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: clean
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.5` -- expected: pass
- `tools/bin/test integration 8.0 7.0` and `tools/bin/test integration 8.5 latest` -- expected: pass
- `WP_MULTISITE=1 tools/bin/test integration 8.0 7.0 "--group ms-required"` -- expected: pass
