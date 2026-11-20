---
id: SPEC-gh-9-stale-bulk-mutex
companions:
  - mutex-sites.md
  - ../../planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md
sources: []
---

# A bulk mutex release cannot delete a new owner's lock (#9)

## Why

A pain in the one-active-job rule. `BulkJobRepository` takes `trust_optimize_bulk_active` with `INSERT IGNORE`, then releases it by option name. Two requests that both saw the same stale holder each delete and insert, so both `create()` calls leave a job. The same name-only delete, after a job has just finished, removes a lock a newer request already installed. AD-6 then no longer holds: a second bulk can own the library, or the new owner's lock disappears.

## Capabilities

- **CAP-1**
  - **intent:** Two requests that both find the same stale bulk mutex end with one active job, and the loser does not remove the winner's lock.
  - **success:** After a planted stale mutex, the holder changes once `acquire_mutex()` has chosen the value it will delete and before that `DELETE` runs. The new value remains and that `create()` does not own the lock. One interleaved pair of `create()` calls on the same stale mutex still leaves a single job whose id is the option value.
- **CAP-2**
  - **intent:** A request releasing the library removes the mutex only while the stored value is still the one that request holds.
  - **success:** The holder changes after `finish()` has chosen the finished job id and before that `DELETE` runs. The new value remains and the job is still finished. A failed job insert whose mutex was replaced leaves the replacement and returns false. A failed job insert that still holds the captured timestamp removes that row.
- **CAP-3**
  - **intent:** An uncontended start still records the new job id, and cancel or complete still clears it so the next start can take the library.
  - **success:** Create, then cancel, then create, and create then complete, show the option matching the live job and absent after finish. `test_a_second_job_cannot_be_created_while_one_is_active` and `test_a_mutex_left_behind_by_a_crashed_request_is_taken_over` stay green with their current assertions.

## Constraints

- Every delete of `trust_optimize_bulk_active` inside `BulkJobRepository` matches both `option_name` and the holder string this call observed or wrote. A name-only delete from that class is gone. `delete_option()` cannot express the predicate, so this path stays on `$wpdb->delete()`. `acquire_mutex()` then calls the insert once.
- Stale detection is unchanged. A timestamp older than `MUTEX_STALE_SECONDS`, or a job id whose job is outside `JobStatus::active()`, is stale. A fresh timestamp and an active job still refuse the second create. `get_active_job()` stays a read.
- The failure path of `create()` passes `release_mutex()` the exact `option_value` string returned by the successful `insert_mutex()` for that capture. It does not call `time()` again and does not read the option. `finish()` passes the string form of the finished job id and does not read the option first. `acquire_mutex()` passes the holder it just read.
- The new tests live in `tests/Integration/BulkProducerTest.php` beside the existing mutex tests. They use the suite's `query` filter and no production hook. For `acquire_mutex()` and for `finish()`, a test changes the holder after this call has chosen the value it will delete and before that `DELETE` runs. The new holder's value remains. A failed job insert whose mutex was replaced leaves the replacement and returns false. A failed job insert that still holds its captured timestamp removes that row. One interleaved pair of `create()` calls after a planted stale mutex still leaves a single job.
- `CHANGELOG.md` gains one bullet under `## [Unreleased]`. The bullet describes the name-only delete. It does not say that every concurrent start is now safe. `readme.txt`, public hooks, settings, REST routes, WP-CLI and the schema do not change. Comments on the mutex functions describe the conditional delete in their own words and do not name the issue.
- `_bmad-output/implementation-artifacts/deferred-work.md` gains the slow-create entry in `mutex-sites.md`. The spine, including AD-6, is not edited.

## Non-goals

- Redesigning the queue, the producer, or job-status transitions. AD-4, AD-5 and the producer model stay as they are.
- Making `write_mutex()` compare-and-set, and rolling back a job row when that write loses. A create whose job insert outlasts 60 seconds can still leave two active jobs. This change does not close every concurrent start. The sequence and the consequences are the deferred-work entry.
- Amending AD-6 or any other spine rule.
- Changing uninstall. It still deletes every `trust_optimize_*` option by name while removing the plugin.
- A new multisite test, a new option, hook or table, or a change to the 60-second threshold or to who may start a job.

## Success signal

The window tests leave a replaced holder in place for `acquire_mutex()`, `finish()`, and a failed job insert. An uncontended start stores the job id, cancel and complete clear it, and the two existing mutex tests pass unchanged. The deferred-work entry records the start that still races when the job insert outlasts 60 seconds. Nothing in the changelog or this spec says every concurrent start is safe.

## Assumptions

- Job ids stay below 1000000000, so the existing comparison still separates a timestamp from a job id. A new owner's value differs from the value the losing call observed: the new owner writes a new `time()` or a new job id, and the same unix second is written only after the old row is gone.
- Interleaving through the `query` filter inside one PHP request reproduces an owner change between the check and the delete. The suite already uses that filter to intercept queries.
