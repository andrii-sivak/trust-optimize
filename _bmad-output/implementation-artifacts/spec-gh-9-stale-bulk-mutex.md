---
title: 'A bulk mutex release cannot delete a new owner (#9)'
type: 'bugfix'
created: '2026-11-20'
status: 'done'
route: 'oneshot'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-gh-9-stale-bulk-mutex/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-gh-9-stale-bulk-mutex/mutex-sites.md'
  - '{project-root}/AGENTS.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BulkJobRepository` deletes `trust_optimize_bulk_active` by option name. A request that saw a stale holder, a request that just finished its job, or a request whose job insert failed can remove a lock a newer owner already installed. Two overlapping `create()` calls can then both leave an active job.

**Approach:** Every delete of that option inside `BulkJobRepository` goes through `release_mutex()` with the holder this call observed or wrote, matching both `option_name` and `option_value`. `acquire_mutex()` passes the holder it just read, then inserts once. `finish()` passes the string form of the finished job id and does not read the option first. On a failed job insert, `release_mutex()` receives the exact `option_value` string returned by the successful `insert_mutex()` for that capture: no second `time()`, no read. `write_mutex()` stays an update by option name. AD-6 is not amended. Tests in `tests/Integration/BulkProducerTest.php` use the suite's `query` filter and no production hook. They change the holder after `acquire_mutex()` has chosen its value and before that `DELETE`, and the same for `finish()`; the new value remains. A failed job insert leaves a replaced holder and still removes its own captured timestamp when nothing replaced it. An uncontended create, cancel, and complete still store and clear the job id. One interleaved pair of `create()` calls on one stale mutex leaves a single job. The two existing mutex tests keep their assertions. `deferred-work.md` gains the slow-create entry written in `mutex-sites.md`, sequence and consequences included. The changelog bullet does not say this closes every concurrent start.

</frozen-after-approval>

## Implementation Notes

- `insert_mutex()` calls `time()` once and returns that exact string. `create()` keeps it and passes it to `release_mutex()` when the job insert fails.
- `acquire_mutex()` passes `(string)` of the int `read_mutex()` returned. The plugin only writes canonical decimals, and that int is the holder the stale check already used.
- `finish()` releases `(string) (int) $job_id` after a successful transition, with no preceding read. `cancel()`, `complete()`, and `fail()` all go through `finish()`.
- `write_mutex()` is unchanged. AD-6 is unchanged.
- The slow-create sequence is the last entry in `deferred-work.md`, under its own heading, so it is not part of the #34 review list.
- PHP 8.0 / WordPress 7.0: `BulkProducerTest` 28 tests, 153 assertions. PHP 8.5 / WordPress 7.1.5: the same class, same result. After the review patches, the eight mutex tests passed again on PHP 8.0. `phpcs` is clean on the two PHP files.

## Review Triage Log

- low, rejected — `read_mutex()` casts to int before the stale delete. The plugin writes `(string) time()` and `(string) job_id`, both of which round-trip through that cast on the 64-bit runtimes the suite uses. The stale check already used the int. A non-canonical option value is not a state this code writes. Left as-is.
- low, patched — the failed-insert replacement test did not look at the `DELETE` predicate, so a later `time()` in another second could still pass. The test now records the holder before the swap and requires that string in the delete, and rejects the replacement value there.
- medium, patched — `query` filters and `suppress_errors( true )` were removed only after assertions. A failed assertion left them on for later tests. Each of those tests now clears them in `finally`.
- low, patched — the replacement test set `$stage` to `delete` and never read it. Removed with the predicate assertion.
- false — the two-create test nests `create()` inside the `query` filter. It passed on WordPress 7.0 and 7.1.5 and is the interleaving the spec asked for. The outer delete then leaves the inner job's id in the option.
- low, patched — the deferred entry sat under the #34 review heading. Moved to its own heading at the end of `deferred-work.md`. The extra sequences (a `write_mutex` that matches nothing, and clock skew) are not the agreed race: one options table shares one clock, and the recorded sequence is A overwriting Jb.
- low, patched — the changelog said each delete matches "that request's own value." Reclaim deletes the stale holder it read, which the crashed request wrote. The bullet now says each delete matches the value that call saw or wrote, and that the slow insert can leave two active jobs.
- false — `active_job_count()` reads the three statuses `JobStatus::active()` returns (`pending`, `running`, `paused`). There is no fourth.
- false — `fail()` calls `finish()`. The holder swap covers that delete through `cancel()`, and the uncontended path covers it through `complete()`.
