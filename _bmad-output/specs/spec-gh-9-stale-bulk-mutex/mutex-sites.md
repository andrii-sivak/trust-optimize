# Mutex sites for `trust_optimize_bulk_active`

Audit of `BulkJobRepository` at main `5c72440`. The option value is the owning job id, or `time()` between the insert of the row and the insert of the job. Callers outside this class only read an active job or, in `uninstall.php`, delete every `trust_optimize_*` option by name.

## What races

`acquire_mutex()` inserts, and on failure reads the holder. When that holder is stale it deletes by option name and inserts again. Two calls that both read the same stale value both delete. The later delete removes the row the earlier call just inserted. Both inserts then succeed, and both `create()` calls insert a job.

`finish()` commits the terminal status, reads the option, and when the value equals the job id deletes by option name. After that commit the job is not active, so a concurrent `create()` treats the job id as stale, deletes it, and inserts its own row. The finishing call's delete then removes that new row.

`create()` after a failed job insert deletes by option name. That removes a new owner's row once this call's timestamp has been replaced.

`write_mutex()` updates `option_value` by option name. It does not delete. A job insert slower than `MUTEX_STALE_SECONDS` can still store this call's job id over a value a new owner wrote. A compare-and-set on that update would not restore one active job: the job row is inserted first, so a write that matches nothing leaves an active job with no lock. Rolling that row back is out of this fix.

## Sites

| Site | Today | This fix |
| --- | --- | --- |
| `insert_mutex()` | `INSERT IGNORE` of `option_name` and `(string) time()`. One row wins. | Still one `time()` per insert. On success it returns that exact `option_value` string. |
| `read_mutex()` | `SELECT option_value` by name. | Unchanged. `finish()` stops using it to decide a delete. |
| `write_mutex()` | `UPDATE option_value` where `option_name` matches. | Unchanged in this fix. |
| `release_mutex()` | `DELETE` where `option_name` matches. | `DELETE` where `option_name` and `option_value` both match the holder string passed in. `$wpdb->delete()` with both columns. |
| `acquire_mutex()` stale branch | Calls the name-only delete, then inserts. | Deletes the holder it just read, then inserts once, including when the delete matched no row. `INSERT IGNORE` still refuses a second row. |
| `create()` when the job insert fails | Name-only delete. | `release_mutex()` gets the string `insert_mutex()` returned for that capture. No second `time()` and no read. |
| `finish()` (`cancel`, `complete`, `fail`) | Reads, then name-only delete when the value equals the job id. | Deletes where the value is the string form of that job id. No preceding read. |
| `get_active_job()` | Reads the newest job in `pending`, `running` or `paused`. | Unchanged. Still not a mutex write. |
| `pause`, `mark_running`, cursor and metric writes | Do not touch the option. | Unchanged. |
| `uninstall.php` | `delete_option()` for every `trust_optimize_*` name. | Unchanged. Teardown, not a live release. |

## Tests

In `tests/Integration/BulkProducerTest.php`, next to the existing mutex tests. The `query` filter is the seam. It runs once per case, then the original statement runs. No production hook.

- `acquire_mutex()` window. Plant a timestamp older than `MUTEX_STALE_SECONDS`. On the reclaiming `DELETE` of `trust_optimize_bulk_active`, before it executes, set `option_value` to a different holder. `create()` returns false. That holder is still the option value. The same stale row, with a second `create()` run to completion inside that first `DELETE` before the statement executes, leaves one job, and the option value is that job's id.
- `finish()` window. After `create()`, on the `DELETE` from `cancel()` or `complete()`, before it executes, set `option_value` to a different holder. The job is finished. That holder remains.
- Failed job insert. Force the jobs-table `INSERT` to fail. When the mutex was not replaced, the option row is gone. When the filter sets a different holder before the release `DELETE`, `create()` returns false and that holder remains.
- Uncontended. After `create()`, the option value is that job id. After `cancel()`, the option is absent and a following `create()` stores its own id. After `complete()`, the option is absent.

`test_a_second_job_cannot_be_created_while_one_is_active` and `test_a_mutex_left_behind_by_a_crashed_request_is_taken_over` keep their current assertions.

## Deferred slow create

Add this entry to `_bmad-output/implementation-artifacts/deferred-work.md`. Do not shorten the sequence.

- source_spec: `_bmad-output/specs/spec-gh-9-stale-bulk-mutex/SPEC.md`
  summary: A bulk create whose job INSERT takes longer than `MUTEX_STALE_SECONDS` (60) can still replace a new owner's mutex. `write_mutex()` updates `trust_optimize_bulk_active` by option name only. The conditional delete in #9 does not close this start, and #9 does not make every concurrent start safe.
  evidence: Request A inserts the mutex with timestamp T and keeps that exact string. A's INSERT into the jobs table lasts longer than 60 seconds. Request B reads T, treats it as stale, deletes the row whose `option_value` is T, inserts its own timestamp, inserts job Jb, and `write_mutex` sets the option to Jb. A's INSERT then commits job Ja. A calls `write_mutex(Ja)`, which matches `option_name` only and replaces Jb with Ja. Ja and Jb are then both active. The option names Ja. `get_active_job()` returns the newest active row, which can be Jb, so the mutex and that read disagree. `finish(Jb)` does not clear the option, because the value is Ja. `finish(Ja)` clears it while Jb is still active. `create()` refuses a third job only because an active row still exists. A compare-and-set on `write_mutex` would not restore one active job: the job row is inserted before the write, so a write that matches nothing leaves Ja active with no lock. Closing the race means rolling that row back as well. #9 does not.

## Files

- `includes/bulk/BulkJobRepository.php`
- `tests/Integration/BulkProducerTest.php`
- `CHANGELOG.md` under `## [Unreleased]`
- `_bmad-output/implementation-artifacts/deferred-work.md`
