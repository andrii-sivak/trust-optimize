---
title: 'Job liveness from attempt evidence, slow attempts, AD-5 priorities and the claim poison guard (#34)'
type: 'bugfix'
created: '2026-11-18'
status: 'ready-for-dev'
route: ''
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A running bulk job is stopped (`paused` / `stopped`) while its queue is still working.
- **Stop.** Step 2 of the job-health check judges liveness only by the job row's `updated_at`, and only the producer writes it. Conversions run at the producer's priority, so the next producer waits behind its whole batch. When that batch takes longer than `trust_optimize_bulk_stale_after_seconds`, the next admin page load stops the job, and nothing continues it until Resume. On nono-mimi this happened three times and once cost 8 h 49 min.
- **Slow attempt.** A live queue attempt whose single encode outlasts 900 s is recovered as dead once Action Scheduler (AS) has failed its action (deferred from #30).
- **Poison bypass.** A late AS action can claim a `failed` / `max_attempts` attachment, and its closing write resets the count.

**Approach:** Spine AD-5, AD-8, AD-9 and AD-10 as amended on 2026-11-18:
- **Liveness.** One function judges it from producer steps and attempt evidence: claims, and variant transitions made by a worker. An attachment's stored state is never evidence.
- **Recovery wait.** Automatic recovery of a queue attempt waits `max( 900 s, trust_optimize_bulk_stale_after_seconds )`.
- **Priorities.** Every background conversion runs at priority 20, and service actions at 10.
- **Claim guard.** A queue claim refuses an attachment with `attempts >= MAX_ATTEMPTS`.

## Boundaries & Constraints

**Always:**
- **Attempt evidence (AD-8)** is one repository predicate over the job's attachments (`job_id`), taking two moments: `T` for variant evidence and `C` for running claims. An attachment row qualifies when either of these holds:
  - **a running claim:** `a.state = processing AND a.updated_at >= C`. Only `claim()` stores `processing`, and lease writes do not touch `updated_at`, so the timestamp is the claim time.
  - **a worker variant transition:** `a.updated_at >= T`, and a variant `v` of that attachment has `v.updated_at >= T`. Its status is `processing`, `done` or `skipped`, or `failed` with a reason other than `max_attempts` and `delete_failed`.
- **Moments.**
  - **Liveness:** `T = now − threshold` and `C = now − recovery wait`, where the recovery wait is `max( 900 s, threshold )`. A job is never stopped while its attempt is still protected from recovery.
  - **Progress since Resume:** `T = C = resumed_at`.
- **Why the attachment filter loses nothing.** A worker's variant transition is followed by the closing write of its attempt within the 20 s budget plus one encode, and that write refreshes `a.updated_at`. A running attempt counts from its claim, as AD-8 states.
- **What is not evidence.** These writes refresh `a.updated_at` but write no qualifying variant:
  - `recompute()` and `set_state()`, called by the planner, regeneration, cleanup and `plan_and_enqueue`. They rewrite state, reason and `updated_at` and touch no variant.
  - `mark_queued`, `requeue`, park and suspend.
  - The death CAS and `release`. Their variants become `pending`, or `failed` / `max_attempts`, which is excluded.

  The only variant writers are:
  - planner upserts, always `pending`. `VariantRepository::upsert()` refreshes `updated_at` only when the data sets `status`, so a racing second insert of an existing row cannot fake evidence (AD-3);
  - worker token transitions;
  - the death CAS;
  - cleanup's delete failure (`failed` / `delete_failed`, excluded).

  A re-planned final state therefore never counts. That includes a `failed` / `max_attempts` row that `recompute()` turns into `failed` with a NULL reason.
- **Liveness function.** `BulkJobRepository::find_stale_running( $threshold )` returns running jobs that have neither of these newer than `now − threshold`:
  - **a producer step:** the job row's `updated_at`, written while running only by `set_cursor`, `touch` in `wait()`, `merge_snapshot`, start and Resume;
  - **attempt evidence.**

  It adds no write, column, index or schema change.
  - A producer `wait()` keeps a job alive even while an in-flight row cannot move by itself, as before #34. A poison row is closed by the poison CAS, and a held attempt by release after the notice.
  - It never treats the existence of an AS action, or the job row's age alone, as proof.
  - Pending deferrals are not an input until #26 adds them to the same function.
- **Progress since Resume** uses the same predicate. For a sync job, `has_progress_since_resume()` asks for attempt evidence since `resumed_at` in place of today's attachment-state test. That test has the same flaw: `recompute()` of an old `optimized` row, or of a `failed` / `max_attempts` row, counts as progress and ends the #30 skip. Other job types keep `cursor_id > resume_cursor_id`. A `touch()` is still not progress since Resume.
- **Step 2 order** is unchanged: liveness, then the #30 Resume skip (`waits_for_the_queue`), then `stop()`.
- **Who stops a job (AD-9, unchanged).** Only normal admin page loads stop a job, never with `wp_doing_ajax()` or `wp_doing_cron()`.
  - Maintenance and Resume run step 1 only, and Resume then continues the job.
  - REST and WP-CLI status only read.
- **Recovery wait (AD-10).** Step 1 passes `max( AttachmentRepository::STALE_CLAIM_SECONDS, $jobs->stale_after_seconds() )` to `find_death_candidates()`. Every other condition stays:
  - source `queue`;
  - a successful AS answer with no `pending` or `in-progress` action;
  - `publishing_at IS NULL`;
  - the death CAS on the token.

  The held list, `release`'s too-young refusal, the queue signals and the lost-queued repair keep 900 s.
- **Closing write.** `finish()` never stores `processing`. A caller that derives `processing` (the sync `up_to_date` branch after a failed orphan reset) ends the attempt `failed` / `exception`, so only `claim()` stores `processing`.
- **Claim poison guard (AD-10).** A queue `claim()` also requires `attempts < MAX_ATTEMPTS`, in the same statement. A sync claim does not: it sets `attempts = 1`, which is the explicit retry.
  - **Busy comes first.** A `processing` row is busy whatever its count. `process()` takes the existing busy path for it: park, which misses because the row is not `queued`, then one more claim, which misses. It converts nothing, schedules nothing, and leaves state, `attempts` and the token as they are.
  - **Poison refusal.** When a queue claim misses only because of the count (the row is not `processing`), `process()` converts nothing, schedules nothing, and leaves `attempts` as it is. It does not take the busy path.
  - **Poison CAS.** A refused row that is not yet `failed` can only be left from before the guard, for example `queued` with 3 attempts. One CAS ends it `failed` / `max_attempts`, together with its `pending` variants, so its job can complete. The CAS requires `state <> processing AND attempt_token IS NULL`: it closes only rows that no attempt holds. It never changes a `processing` row or revokes a live token. A held attempt stays with AD-10: the attempt's own closing write, the death CAS or `release`.
  - **A confirmed source change** keeps its retry right: the planner's `failed_source_changed` resets `attempts` before `mark_queued`.
- **Priorities (AD-5).**
  - Every `trust_optimize_process_attachment` action is scheduled at priority 20, in every path:
    - the first enqueue: uploads, the producer's hand-over and the Lifecycle restore;
    - a `more` continuation;
    - a recovered attempt;
    - Maintenance's `requeue`;
    - the parked set queued again after Resume;
    - the schema-pending retry.
  - The producer (async and +30 s), Maintenance and Restore are scheduled at priority 10, passed explicitly.
- **Backpressure stays as it is.** At the start of a sync run, the producer counts `pending` `trust_optimize_process_attachment` actions of the group, whoever queued them.
  - When **more than** `trust_optimize_bulk_max_pending` (default 200) are pending, it waits: `touch`, then a producer at +30 s.
  - Otherwise it queues up to one batch (`trust_optimize_bulk_batch_size`, default 25) of the job's parked attachments, then up to one batch after the cursor, within the 20 s time budget.
  - So pending conversions can exceed the limit by up to two batches (225–250 by default).
- **Accepted consequence.** With the producer at 10 and conversions at 20, the producer refills the queue up to that bound. A new upload during a bulk may wait behind that many conversions.
- **Docs.**
  - **`readme.txt`:** `trust_optimize_bulk_stale_after_seconds` governs both job staleness and the minimum wait before a dead conversion attempt is recovered. A higher value gives slow encodes time and delays recovery of an attempt that really died. An encode longer than the chosen value can still be recovered while its PHP process lives.
  - **`CHANGELOG.md`** `[Unreleased]` changes in the same commits. It warns that a site which raised the filter to work around #34 now also waits that long before a dead attempt is recovered.

**Never:**
- Write anything to signal liveness.
- Read an attachment's stored state as evidence of work.
- Change AS globally: runner filters, claims or `QueueCleaner` (AD-5).
- Stop a job from Maintenance, Resume, REST, WP-CLI, AJAX or cron.
- Recover an attempt on age alone.
- Add a filter, option or schema change.
- Limit encoder time: that is a separate task.
- Change the backlog rule or add adaptive pace (#26), add deferral data (#26), or touch Site Health's overdue test (#25).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error handling |
|---|---|---|---|
| Issue #34 case | job row 18 min old; the job's conversions claimed and finished in the last minutes | admin load: job stays `running`; the producer later runs and schedules its successor | — |
| Producer waits | `wait()` touched the job within the threshold | alive | — |
| Re-planned old final state | X `optimized` with variants `done` long ago; regeneration or `plan()` runs `recompute()` (fresh `updated_at`, reason NULL); nothing else | not evidence: the job is stopped like a job without progress; a resumed job keeps its skip | — |
| Re-planned `failed` / `max_attempts` | X `failed` / `max_attempts`, variants `failed` / `max_attempts`; `recompute()` rewrites `failed` with NULL reason | not evidence for liveness or for progress since Resume | — |
| Dead worker, no cron | X `processing` claimed > threshold ago; AS action `in-progress`; no producer step | admin load stops the job `stopped` (unchanged) | — |
| Only recoveries / repairs | death CAS, `release`, `requeue`, park in the window | not evidence: job stopped | — |
| Slow attempt, raised threshold | filter 3600; X claimed 1200 s ago; AS failed its action | step 1 does not recover X; job alive through X's claim | — |
| Same, past the threshold | X claimed > 3600 s ago, no AS action, no lease | recovered once by the death CAS, otherwise not | AS not ready or query failed → no recovery |
| Low filter | filter 300; X claimed 600 s ago, AS action gone, no other evidence | job alive through X's claim until 900 s; X not recovered before 900 s; after 900 s X recovered (AD-10) and the job judged by 300 s | — |
| Held attempt in flight | a sync attempt of the job held > 900 s; producer waits | job stays `running` (wait is a producer step); held notice shown | operator `release` |
| Racing planner insert | second insert of an existing `done` variant | `updated_at` unchanged; not evidence | — |
| Resumed, stuck queue | `resumed_at` set, no attempt evidence since, producer scheduled or signal | not stopped (#30 skip) | — |
| Late AS action on a poison file | X `failed` / `max_attempts`, `attempts` 3; its action runs `process()` | no claim, no conversion, nothing scheduled, `attempts` 3, row unchanged | — |
| Duplicate action during the third attempt | X `processing`, `attempts` 3, token T (live worker); a second action runs `process()` | busy: no conversion, nothing scheduled, X stays `processing` with `attempts` 3 and token T, no poison CAS; T's worker then finishes normally (closing write, `attempts` 0) | — |
| Legacy queued with 3 attempts | X `queued`, `attempts` 3, action runs | X and its `pending` variants `failed` / `max_attempts`; the job can complete | — |
| Retry right kept | sync of X; or X's source replaced and re-planned | sync claims (`attempts` 1); the replaced source resets `attempts`, queues, and the queue claim succeeds | — |
| Priorities | upload, hand-over, restore, `more`, recovery, `requeue`, parked after Resume, schema retry | priority 20 | — |
| Service actions | producer (async, +30 s), Maintenance, Restore | priority 10 | — |
| Backlog | `max_pending` + 1 conversions pending | producer waits, hands nothing over | — |
| Backlog at the limit | exactly `max_pending` pending, parked and new attachments exist | producer queues one batch of parked and one batch of new | — |
| AJAX / cron / Maintenance / Resume / status | a stale job | never stopped | — |

</frozen-after-approval>

## Code Map

- `includes/bulk/BulkJobRepository.php`:
  - New private attempt-evidence predicate.
  - `find_stale_running()` (l.566) uses it.
  - `has_progress_since_resume()` (l.367) uses it in its sync branch.
- `includes/bulk/JobHealth.php`:
  - `recover_attempts()` (l.150) passes the recovery wait.
  - `stop_stale_jobs()` (l.351): order unchanged.
  - The class docblock: step 2 is no longer "producer vanished".
- `includes/storage/AttachmentRepository.php`:
  - `claim()` (l.302): `attempts < MAX_ATTEMPTS` for queue claims.
  - New CAS that ends a refused non-failed row as `failed` / `max_attempts` with its `pending` variants.
  - `find_death_candidates()` (l.567) is unchanged.
- `includes/processing/AttachmentProcessor.php:249-256` -- `run()` reports the poison refusal apart from `BUSY`; `sync()` `up_to_date` branch (l.170-172) never passes `processing` to `finish()`.
- `includes/storage/VariantRepository.php:110` -- `upsert()` refreshes `updated_at` only with a `status`.
- `includes/queue/ConversionQueue.php`:
  - `PRIORITY_RESUMED` (l.42) becomes the conversion priority.
  - `schedule_or_park()` (l.243) and `schedule()` (l.261) default to it.
  - `process()` (l.185-202) skips park and retry on the poison refusal.
- `includes/bulk/BulkProducer.php:556` -- `schedule()` passes priority 10. `has_backlog()` (l.516) is unchanged.
- `includes/queue/Maintenance.php:77` and `includes/queue/Lifecycle.php:95,115` -- pass priority 10.
- `readme.txt:169-171` and `CHANGELOG.md` `[Unreleased]`.
- Tests:
  - `JobHealthTest`, `BulkJobRepositoryTest`, `ConversionQueueTest`, `QueueRecoveryTest`, `MaintenanceTest:114`, `SchemaPendingTest:72`, `BulkProducerTest`, `LifecycleTest`, `AttachmentRepositoryTest`, `DatabaseUpgradeTest:324`.
  - Clean leaked pending AS actions first (`deferred-work.md`).

## Tasks & Acceptance

**Execution** (one commit per bullet, refactorings first):
- [ ] `ConversionQueue.php`, `BulkProducer.php`, `Maintenance.php`, `Lifecycle.php` -- refactor: rename `PRIORITY_RESUMED` to the conversion priority, and add a named service priority 10 passed explicitly; no behavior change.
- [ ] `ConversionQueue.php` + `ConversionQueueTest`, `BulkProducerTest`, `MaintenanceTest`, `LifecycleTest`, `QueueRecoveryTest` -- every conversion path at 20, service actions at 10, backlog tests (limit + 1 waits, limit queues); CHANGELOG.
- [ ] `AttachmentRepository.php`, `AttachmentProcessor.php`, `ConversionQueue.php` + `ConversionQueueTest`, `AttachmentRepositoryTest` -- queue claim poison guard and the legacy CAS, tested through `process()`; sync and replaced-source retries; CHANGELOG.
- [ ] `VariantRepository.php`, `AttachmentRepository.php`, `AttachmentProcessor.php` + tests -- `upsert()` keeps `updated_at` without a status; `finish()` never stores `processing`.
- [ ] `BulkJobRepository.php`, `JobHealth.php` + `JobHealthTest`, `BulkJobRepositoryTest` -- attempt-evidence predicate (claims over the recovery wait), liveness and progress since Resume, the matrix rows on liveness and re-planning; CHANGELOG.
- [ ] `JobHealth.php`, `readme.txt` + `JobHealthTest`, `QueueRecoveryTest` -- recovery wait `max( 900, filter )`; readme; CHANGELOG.

**Acceptance Criteria:**
- **Issue scenario.** Given a sync job whose row is older than the threshold, while its 25 conversions keep being claimed and finished, when `admin_init` fires between them on a normal admin page load, then the job stays `running`, and its producer, when it runs, schedules its successor.
- **#25 behavior kept.** Given a running job with no runner and no attempt evidence for longer than the threshold, when an admin page loads, then it is stopped `stopped`. That also holds when the only writes in the window were `recompute()` of old final states, recoveries or repairs.
- **Raised threshold.** Given the filter at 3600 s and a queue attempt claimed 1200 s ago whose AS action is `failed`, when step 1 runs from an admin load, Maintenance and Resume, then the attempt is not recovered and the job is not stopped. Once the claim is 3600 s old, the attempt is recovered only if no AS action is pending or in progress, there is no lease, and the token matches.
- **Poison through `process()`.** Given X `failed` / `max_attempts` with 3 attempts and an AS action for it, when `ConversionQueue::process()` runs, then nothing is claimed, converted or scheduled, and `attempts` stays 3. When a sync of X runs, or X's source is replaced and re-planned, then X converts.
- **Third attempt keeps its owner.** Given X `processing` with `attempts` 3 and token T, when a second action runs `ConversionQueue::process()` for X, then nothing is converted or scheduled, and X keeps `processing`, `attempts` 3 and token T. When T's worker then completes, its closing write lands and X ends in its worker state.

## Design Notes

- **Why variants, not attachment state.** The planner calls `recompute()` whenever nothing is pending, and `plan_and_enqueue()` and cleanup call it too. It rewrites state, reason and `updated_at` without any worker. Variants change to `processing`, `done` or `skipped` only through a worker's token-guarded transition; there are two non-worker `failed` writes, and both are excluded by reason.
- **No-op attempts.** An attempt that moves no variant (for example its source became ineligible under the claim) leaves evidence only while it is `processing`. Queueing requires pending variants, so such attempts are rare, and the producer step covers the job meanwhile.
- **Query cost.** The predicate runs only for running jobs whose producer is silent past the threshold, which is rare with the producer at 10. It reads the job's attachments through `job_state`, then at most the variants of rows changed in the window. No new index.
- **Two consumers, one predicate.** Liveness adds the producer step; progress since Resume does not, so a `wait()` keeps a job alive without ending the #30 skip.
- **Why a heartbeat cannot fix slow encodes.** No plugin code runs during a blocking encode, so one setting governs both waits. An encoder time limit is a separate task.

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: clean
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.5` -- expected: pass
- `tools/bin/test integration 8.0 7.0` and `tools/bin/test integration 8.5 latest` -- expected: pass
- `WP_MULTISITE=1 tools/bin/test integration 8.0 7.0 "--group ms-required"` -- expected: pass

**Manual checks** (Docker stand, `DISABLE_WP_CRON`, queue from the `cron` container):
1. Start a bulk with the filter at 120 s and slow conversions, and load wp-admin during the batch: the job stays running.
2. In `wp action-scheduler action list --group=trust-optimize`, conversions have priority 20 and the producer 10; pending conversions stay within the limit plus two batches.
3. Kill the worker and stop the runner: after the threshold an admin load stops the job (unchanged).
