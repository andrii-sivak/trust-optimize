---
title: 'Dead worker recovery, safe publication, parking and Resume while the queue is stuck (#30)'
type: 'bugfix'
created: '2026-11-17'
status: 'ready-for-dev'
route: ''
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** On a site with no WP-Cron and no system cron, a dead worker causes four problems:
- **Blocked queue.** The worker leaves a claim that blocks the Action Scheduler (AS) async runner, and nothing in the plugin explains it.
- **Resume does not hold.** Resume marks the job running, and the next stale check stops it again.
- **Poison files.** `mark_queued()` and `claim()` take over the dead attempt by age without counting the death. A poison file then kills the worker again and again, and a `failed` / `max_attempts` file returns with the next bulk.
- **Unsafe takeover.** A worker that is still alive (a long WP-CLI sync, or a process AS has given up on) can be taken over and can later replace a file the site serves.

**Approach:** Spine AD-7, AD-9, AD-10 and AD-13 as amended on 2026-11-17, with AD-4, AD-5, AD-8 and AD-17:
- **Recovery.** One job-health check (`JobHealth`) recovers only queue attempts that AS confirms dead, through one death CAS keyed on an attempt record (schema 1.2.0).
- **Publication.** It happens under a lease on the attachment row that recovery respects.
- **Release.** Attempts that cannot be recovered automatically are released by the operator through WP-CLI.
- **Parking.** A paused job's pending actions park in the claim CAS.
- **Resume.** It always continues the job, warns while the queue is stuck, and is never undone by the same stoppage.

## Boundaries & Constraints

**Always:**
- **Schema 1.2.0** adds three attachments columns and three jobs columns:
  - attachments: `attempt_source varchar(16) NULL`, `attempt_token char(32) NULL`, `publishing_at datetime NULL`;
  - jobs: `resumed_at datetime NULL`, `resume_token char(32) NULL`, `resume_cursor_id bigint unsigned NULL`.

  The migration is repeatable: it is confirmed by `SHOW COLUMNS`, and the version is stored last. Writers check `has_schema( $version )`.
  - **Before 1.2.0 is in place**, conversion is blocked before `claim()`, so no attempt is spent and nothing becomes `failed`:
    - `process()` reschedules itself in 3600 s at priority 20;
    - REST sync, WP-CLI `sync --now` / `sync_attachment`, per-image actions, bulk start and Resume answer `trust_optimize_schema_pending` before any write;
    - pause writers keep working on 1.1.0, and readers of the new columns check `has_schema( '1.2.0' )`;
    - the notice says that the database update has not finished.
- **Attempt record.** `claim()` writes `attempt_source` (`queue` from `process()`, `sync` from synchronous paths) and a new `attempt_token`. It returns the token or false.
  - It increments `attempts`. A sync claim sets `attempts = 1`, so the explicit sync resets the count inside its own claim.
  - Neither `claim()` nor `mark_queued()` ever takes over a `processing` row.
  - **Orphan variants.** Right after a successful claim, the worker resets the attachment's `processing` variants to `pending`, guarded by its new token. No other attempt holds the row by then, so any such variant is an orphan.
    - Only `claim()` stores attachment state `processing`. Only the token-guarded `pending → processing` stores it for a variant.
    - The planner never changes a `processing` variant, the `replaced` reset included.
- **Sync order.** `sync()` checks the schema and claims first.
  - Busy (`trust_optimize_attachment_busy`, with the source and since when) or schema-pending is answered before any side effect: no `attempts` reset, no planning, no removals.
  - Planning (failed variants retried), removals and conversion run under the claim. A skip found by the planner lands through the attempt's closing write.
- **Recovery.** Only `JobHealth` step 1 recovers, at most 20 candidates per call, oldest first. A queue attempt is dead only when all of these hold:
  - it has been `processing` for longer than 900 s;
  - `publishing_at IS NULL`;
  - one `as_get_scheduled_actions()` call (statuses `pending` and `in-progress`) answers with no action. That answer only counts when `did_action( 'action_scheduler_init' )` is true and `$wpdb->last_error` is empty after the call.

  Sync attempts, legacy rows (source NULL) and leased rows are never recovered automatically.
- **Death CAS.** It is keyed on `attachment_id`, `state = processing`, the observed token (null-safe) and `publishing_at IS NULL`, and it clears the attempt record.
  - It is one multi-table `UPDATE` (attachments `LEFT JOIN` variants), followed by invalidating the variants cache.
  - **`attempts >= 3`:** the attachment becomes `failed` / `max_attempts`, and its `processing` and `pending` variants follow.
  - **Otherwise:** its `processing` variants go back to `pending`, and the attachment state is chosen in the same UPDATE:
    - parked (`none` / `paused`, `job_id` kept) when its job is `paused` at that moment;
    - otherwise `queued`.
  - **Scheduling.** The caller re-reads the row and schedules an action at priority 20 only when it is `queued`.
    - If that scheduling fails for an attachment with a `job_id`, the row goes back to parked by CAS, and the producer picks it up.
    - Without a job, it stays `queued` for Maintenance's lost-`queued` repair.
  - **Unfinished job:** `BulkJobRepository` replaces the job's `last_error` with `attachment N, death k of 3`. It never appends and never touches `updated_at`.
- **Publication** of one variant by attempt T:
  1. The image is written to a temporary file, and its size check runs on the temporary file.
  2. The lease CAS sets `publishing_at` `WHERE state = processing AND attempt_token = T AND publishing_at IS NULL`. Lease writes never touch `updated_at`.
  3. The occupied-target check runs, then the rename, then `processing → done` (token-guarded).
     - **Ownership.** An existing target may be replaced only when the variant row owns its path, `hash_file()` of the target equals the row's non-NULL `file_hash`, and no attachment registered it as its original. This is AD-13 ownership; v1.0.0 already wrote `file_hash` on every `done`, and a reset keeps it.
     - **Otherwise** the target is kept, and the variant ends `failed` / `target_exists_foreign` with the writer's message. That is the interim outcome AD-13 allows until #19 makes it `skipped`.
  4. The lease is released.

  Error outcomes:
  - **Lease missed** (attempt lost): delete the temporary file; the target is untouched; the worker stops.
  - **Lease query error:** delete the temporary file; the target is untouched; the variant becomes `failed` / `publish_unsafe`.
  - **Rename failed:** the target is unchanged (`rename_failed`); the lease is released.
  - **`done` missed because the row is gone:** the existing `row_gone` path runs, and the lease is released.
  - **`done` failed by a DB error after the rename:** the lease is kept and the worker stops. The attachment becomes held "publication incomplete" (below).
  - **Rename and `done` succeeded, but the release failed:** `done` stands. The worker publishes nothing more and goes straight to its closing write, which clears the lease. If that fails too, the attachment is held "lease not released".
- **Late worker.** Every other write of the worker is token-guarded: variant transitions, the `attempts` reset, the closing write, and scheduling `more`.
  - A lost attempt schedules nothing.
  - `run()` no longer resets `processing` variants of earlier attempts.
  - `recompute()` and `set_state()` without a token never touch a `processing` row.
  - **Closing write.** It is one token-guarded statement that does all of this:
    - stores the final state and reason (never `processing`);
    - resets `attempts`;
    - clears `attempt_source`, `attempt_token` and `publishing_at`.
  - **Exception.** On an exception the attempt makes that closing write as `failed` / `exception`, then rethrows, as today.
- **Held attachments.** A row is held when step 1 may not recover it:
  - a sync or legacy row `processing` for longer than 900 s (by `updated_at`);
  - a row whose `publishing_at` is older than 900 s.

  The reason is "publication incomplete" when the lease is set and variants are `processing`; the notice names those variants' paths. It is "lease not released" when the lease is set and no variant is `processing`.

  The non-dismissible notice gives instructions only:
  - what holds it: a synchronous conversion, a conversion started before the update, publication incomplete, or lease not released;
  - since when;
  - for publication incomplete: the variant's path, and that "the file at this path may already be the new version while its record keeps the previous hash. It is not served while its record is not `done`, and it will be kept and reported as `target_exists_foreign`";
  - "make sure that process has ended or stop it; the age of the record does not prove it; then run `wp trust-optimize release N`".

  REST and WP-CLI syncs answer busy. The in-flight count includes the held row, and its bulk does not finish.
- **`wp trust-optimize release <id>`** is an operator command that requires 1.2.0.
  - **Without options** it changes nothing. It prints:
    - the source;
    - the attempt (the token, or `legacy`);
    - `since` (`updated_at`);
    - `attempts`;
    - the lease state;
    - the confirming command `release <id> --attempt=<token|legacy> --since=<updated_at>`.
  - **With options**, one CAS (the same multi-table `UPDATE` as the death CAS) applies the death-CAS outcome and clears the lease. It is keyed on:
    - the attachment and `state = processing`;
    - `attempt_token` equal to the given one (NULL for `legacy`);
    - `updated_at = since`;
    - `publishing_at <=> lease`.

    The printed command quotes the values: `--since="…" --lease="…|none"`.
    - **Why the CAS cannot match a changed attempt.** A held row is older than 900 s, so any attempt write since the inspection has changed `updated_at`, old code included. Taking a lease changes `publishing_at`. Either way the CAS misses: "the attempt changed; inspect again".
    - **Refusals.** It refuses rows younger than 900 s. It refuses queue rows whose AS action still exists: "Action Scheduler still holds it; let its runner run".
    - Release never deletes or overwrites a file. A publication-incomplete variant follows the death-CAS outcome:
      - **`attempts` < 3:** back to `pending`. The next attempt finds the target's hash different from the row's (or the row's hash NULL on a first publication), keeps the file, and ends the variant `failed` / `target_exists_foreign`. The attempt itself ends normally, so `attempts` resets and nothing counts as a death.
      - **`attempts` = 3:** the attachment becomes `failed` / `max_attempts` with its open variants, the file is kept, and normal planning skips it. An explicit sync retries it and reaches the same `target_exists_foreign`.
      - Either way the file stays until an operator or #19 resolves it.
- **Parking.**
  - A queue `claim()` also requires `NOT EXISTS` (the owning job is `paused`), in the same statement. A Pause committed before the claim always wins.
  - When the claim misses, `process()` parks by CAS (`queued → none/paused` while the job is paused) and schedules nothing. When the park also misses, it tries the claim once more.
  - Sync claims ignore the pause.
  - The parked set is one repository method: rows with `none` and reason `paused` or `deactivated`, and the job's `job_id`.
  - Each producer run first queues up to a batch of its job's parked set, regardless of the cursor. `finish_or_wait()` never completes a job whose parked set is not empty.
  - `JobProgress` counts parked attachments as in flight.
- **Poison guard.**
  - `mark_queued()` and `requeue()` refuse `attempts >= 3`.
  - The planner evaluates `source_changed` for `failed` rows too. It resets `attempts` before it decides whether to retry.
- **Stale step.** Only on admin page loads (`! wp_doing_ajax() && ! wp_doing_cron()`), and it replaces the unguarded hook in `BulkProducer`. It keeps the old `updated_at` test, but skips a job that has `resumed_at` and no progress since it.
  - **Progress, sync job:** an attachment of the job reached `optimized`, `partial`, `skipped`, or `failed` with a reason other than `max_attempts`, with `updated_at > resumed_at`.
  - **Progress, other types** (remove, inventory): `cursor_id > resume_cursor_id`, the cursor captured by the Resume. `updated_at` is not used, so a `touch()` cannot count.
    - These types never reach `wait()`: the backlog branch of `produce()` is sync-only, and `finish_or_wait()` waits only on in-flight or parked attachments, which only sync jobs have. The rule does not depend on that.
  - `touch()` in `wait()` is never progress.
  - The skip applies only while a producer action of the job is `pending` or `in-progress`, or a queue signal is present. A job whose producer is gone is judged by the old test and stops.
- **Queue signals.** Computed only while an active job exists, through `as_get_scheduled_actions()`, group `trust-optimize`, threshold 900 s:
  - `stuck_claim`: an action is `in-progress`, or `pending` and claimed, and its `modified` is older than the threshold;
  - `no_progress`: a `pending` action is due by `date` older than the threshold, and no `trust_optimize_process_attachment` action has been `complete` within it.

  They are diagnostics only.
- **Callers.** Step 1 runs only from admin page loads, Resume and Maintenance. REST and WP-CLI status read the signals and the held list, and never recover.
- **Resume:**
  1. Schema check.
  2. For a sync job, `DiskSpace::is_low()` refuses with `trust_optimize_disk_low` before anything else runs, so the job and its attachments stay as they are.
  3. Step 1 of the health check.
  4. Read `pause_reason`, `last_error` and `resumed_at`.
  5. `mark_running()` from `paused` writes a new `resume_token` R, `resumed_at` and `resume_cursor_id`, and clears `pause_reason` and `last_error`.
     - On a job that is already `running` it writes nothing. Resume then only schedules a missing producer and reports a failure without any revert.
     - **Bulk start (`launch()`).** When the first producer scheduling is confirmed to fail (0 or an exception), the new job becomes `failed` with `last_error` "producer could not be scheduled", the mutex is released, and start answers `trust_optimize_start_failed`. A producer that is already pending counts as scheduled.
  6. Schedule the producer unless one is pending.

  Failure handling:
  - When scheduling fails (0 or an exception), one CAS `WHERE status = running AND resume_token = R` restores `paused`, `pause_reason` and `resumed_at`, and sets `last_error = COALESCE( last_error, previous )`. A death recorded meanwhile is kept. Resume answers `trust_optimize_resume_failed`.
  - When the revert misses (a Pause or another Resume came first), the error is reported together with the job's current state.
  - When signals are present, Resume succeeds with a warning.
- **Notice.**
  - **Paused job.** For a job paused for any reason except `user`, the notice names the reason, then shows `last_error` or says plainly that the cause is unknown. Schema-pending, signals and held attachments come first. It links to Bulk Optimization and has the Resume button.
  - **Running job.** With signals, a running job gets the warning without the button.
- **Output and docs.**
  - The REST and WP-CLI job gains `queue_signals`.
  - The texts about queued work after Pause become "parked until Resume": `BulkProducer:194`, `Command:388` and `admin-page.php:150`. Cancel keeps its own sentence, and the shared admin text is split.
  - The `release` command and the error codes go into `readme.txt`; `CHANGELOG.md` changes in the same commits.

**Never:**
- Call `ActionScheduler_QueueCleaner`, touch claims through the store, or use undocumented AS methods.
- Return `409 trust_optimize_queue_blocked`, or refuse Resume on action ages.
- Recover or release an attempt on age alone, clear a lease automatically, or rename outside a held lease.
- Hold a lock for a whole run. Add admin UI beyond notices and the existing panel. Stop a job from Maintenance or Resume, or resume one automatically.
- Use transactions. Change Site Health's overdue test (#25), the rest of the gate (#26), the panel's pausing state (#35) or liveness (#34).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error handling |
|---|---|---|---|
| Worker killed, no cron | X `processing` > 900 s, AS action `in-progress` | not recovered; `stuck_claim`; admin load stops the job `stopped` | — |
| AS runs again, job paused | AS failed the dead action | step 1 recovers X once into the parked set; not converted before Resume | — |
| Recovered while running, then Pause | X `queued` with action | claim CAS misses, X parked, nothing scheduled | park misses → one more claim try |
| Resume behind cursor | parked X below the cursor | next producer run queues X; job completes only after X | — |
| Third death | `attempts` 3 at recovery | X and open variants `failed` / `max_attempts`; bulk, regeneration, Maintenance and restore skip it | — |
| Poison: sync / new source | X `failed`, `attempts` 3 | sync claim sets 1; changed source resets | sync of `processing` X → busy, no side effect |
| Late worker after new publication | T1 recovered; T2 published hash h2; T1 reaches publication | lease misses; temp deleted; served file hash stays h2 | — |
| Recovery during publication | lease held by T1 | death CAS misses | — |
| Lease query error | DB error | target untouched; variant `failed` / `publish_unsafe` | error shown |
| `done` error after rename | DB error on transition | lease kept; worker stops; later held "publication incomplete" with path | operator release; file kept |
| Long WP-CLI sync / legacy row | `sync` or NULL source, > 900 s | never recovered; held notice; other syncs busy | — |
| Next attempt after release | publication incomplete released at `attempts` < 3; target hash ≠ row `file_hash` | file kept; variant `failed` / `target_exists_foreign`; attempt ends normally | message from the writer |
| Release on the third attempt | same, `attempts` = 3 | attachment and open variants `failed` / `max_attempts`; file kept; explicit sync → `target_exists_foreign` | — |
| Re-optimization | `done` row, target hash = row `file_hash` | target replaced under the lease as today | — |
| Release, token row | `--attempt=T --since=S` unchanged | death-CAS outcome, lease cleared, files untouched | changed → miss, "inspect again" |
| Release, legacy row | `--attempt=legacy --since=S`; old code claimed again since | `updated_at` ≠ S → miss | "inspect again" |
| Schema 1.2.0 missing | stored 1.1.0 | `process()` reschedules +3600 s without claim; syncs, start and Resume → `trust_optimize_schema_pending`; pauses still write `pause_reason` | retried next request |
| Resume while stuck | `stopped`, signals | `running` + `queue_signals` warning | — |
| Producer only waits | producer pending, never ran | later admin loads past the threshold keep the job running | — |
| Producer waited once | `wait()` ran (touch), no conversion finished since Resume | admin load past the threshold keeps the job running | — |
| Resume scheduling fails | `as_enqueue_async_action` → 0 | revert by R: `paused`, previous reason, `resumed_at`; `last_error` previous unless a death wrote one | `trust_optimize_resume_failed` |
| Pause between `mark_running` and failure | status `paused`, R unchanged | revert misses; job stays as the Pause left it | error reported with current state |
| Resume on low disk | sync job, disk low | job and attachments unchanged; step 1 not run | `trust_optimize_disk_low` |
| Remove job after Resume | cursor not moved since Resume | no stop for the same stoppage; a cursor move ends the skip | — |
| Start, producer not scheduled | `as_enqueue_async_action` → 0 | job `failed`, mutex released | `trust_optimize_start_failed` |
| AS unavailable | not initialized / query error | no recovery, no signals | — |
| Orphan variant | deactivation or a died recovery left a variant `processing` | the next claim resets it to `pending` under its token | — |
| Release vs lease | after inspection a live worker took the lease | `publishing_at` ≠ shown lease → miss | "inspect again" |
| Release of a live queue row | AS action still exists, or row < 900 s | refused | message |
| Lease release failed | rename + `done` ok | no further publication; closing write clears lease | else held "lease not released" |
| Producer gone after Resume | no producer action, no signal, no progress | old stale test stops the job `stopped` | — |
| Resume on running job | job already `running` | no token, no revert; missing producer scheduled | failure reported, job unchanged |
| Parked re-scheduling fails | producer queued parked X, `as_enqueue_async_action` → 0 | X parked again by CAS | — |
| Deactivated rows of a job | `none/deactivated` with `job_id` | count as parked; job does not complete before they convert | — |

</frozen-after-approval>

## Code Map

- `includes/database/DatabaseManager.php`:
  - `DB_VERSION` becomes `1.2.0` (l.22).
  - `is_current()` (l.51) becomes `has_schema()`.
  - `migrate()` (l.61) confirms the six columns.
  - Add them to `create_tables()` (l.140-177).
- `includes/storage/AttachmentRepository.php`:
  - `claim()` (l.241): attempt record, no takeover, pause condition for queue claims.
  - `mark_queued()` (l.267) and `requeue()` (l.383): no `processing` takeover, poison guard, parked rows accepted.
  - New: candidate and held finders, death CAS, release CAS, lease set and release, park CAS, parked set and count.
  - Token-guarded `reset_attempts()`, `recompute()` and `set_state()`.
  - `find_stuck()` (l.306) keeps only lost `queued` rows.
  - `suspend_unfinished()` (l.338) skips leased rows.
- `includes/storage/VariantRepository.php:157` -- `transition()` takes an optional token, guarded by `EXISTS` on the attachment holding it.
- `includes/files/AtomicImageWriter.php:56-97` -- split into a temporary write and `publish()`. The occupied-target check (l.65) moves into `publish()` and adds the `file_hash` comparison (a behavior commit of its own, after the refactoring).
- `includes/features/optimization/ImageConverter.php:71-205` -- size check on the temporary file; publication steps and error outcomes; new reasons `publish_unsafe` and `publish_incomplete`.
- `includes/processing/AttachmentProcessor.php`:
  - `sync()` (l.111-135) claims first.
  - `run()` (l.195-205) passes the token, drops the reset of `processing` variants, and stops on a lost attempt.
- `includes/queue/ConversionQueue.php:251` -- schema check, claim, park or retry, priority 20.
- `includes/planning/VariantPlanner.php:89-98, 255-268, 284` -- skip through the attempt when called under a claim; forced retry for sync; `source_changed` evaluated for `failed` rows too, and `attempts` reset before the retry decision; `processing` rows never reset.
- New `includes/bulk/JobHealth.php` (wired in `Plugin.php`) -- step 1, held attachments, stale step, signals.
  - Used by `Maintenance::run()` (l.88), `BulkProducer::resume()` (l.183), `Notices`, `RestController` and `Command`.
  - `BulkProducer::register()` (l.155) drops `recover_stale_jobs`.
- `includes/bulk/BulkProducer.php`:
  - `produce()` (l.239) queues the parked set first.
  - `finish_or_wait()` (l.364) waits for parked attachments.
  - `resume()` and `launch()` (l.164-191) follow the order, the revert and the start failure above.
- `includes/bulk/JobProgress.php:63,127` -- parked attachments count as in flight.
- `includes/bulk/BulkJobRepository.php`:
  - `mark_running()` (l.274) writes R and `resumed_at`.
  - New: revert CAS, progress-since-Resume query, death-error writer.
  - `recover_stale_running()` (l.484) applies the skip.
- `includes/admin/Notices.php:161-230` -- generic, schema-pending and held notices; resume errors.
- REST and WP-CLI:
  - `includes/api/RestController.php:376,444`: busy, schema-pending, disk and resume errors, `queue_signals`.
  - `includes/cli/Command.php`: new `release` subcommand; resume and pause texts (l.375-392).
- `assets/js/admin.js` (~l.236), `templates/admin/admin-page.php:150,169` and `includes/admin/Admin.php` (~l.462) -- signal row, resume errors, pause text.
- `readme.txt` (l.126-154) and `CHANGELOG.md` `[Unreleased]`.
- Tests:
  - `QueueRecoveryTest`, `MaintenanceTest`, `AttachmentRepositoryTest`, `ImageConverterTest`, `AtomicImageWriterTest`, `ImageGuardTest`, `AttachmentSyncTest`, `ConversionQueueTest`, `BulkProducerTest`, `NoticesTest`, `BulkStatusRestTest`, `DatabaseUpgradeTest` and `LifecycleTest`.
  - Before judging failures, clean the leaked pending AS actions (`deferred-work.md`).

## Tasks & Acceptance

**Execution** (one commit per bullet, refactorings first):
- [ ] `DatabaseManager.php`, `BulkJobRepository.php` -- refactor `is_current()` into `has_schema()`, no behavior change
- [ ] `AtomicImageWriter.php`, `ImageConverter.php` + tests -- refactor into a temporary write plus `publish()`, no behavior change
- [ ] `DatabaseManager.php`, `DatabaseUpgradeTest.php` + schema-pending paths -- schema 1.2.0: 1.0.0→1.2.0, 1.1.0→1.2.0, second run, failed step; conversion blocked before claim
- [ ] repositories, `AttachmentProcessor`, `ImageConverter`, `ConversionQueue`, `VariantPlanner`, `Maintenance` + tests -- attempt record, sync order, death CAS, publication lease and its error outcomes, late-worker guards, poison guard
- [ ] `AttachmentRepository`, `ConversionQueue`, `BulkProducer`, `JobProgress` + tests -- pause condition in the claim, parking, parked set picked up by the producer
- [ ] `JobHealth.php`, `Plugin.php`, `BulkProducer.php`, `BulkJobRepository.php` + tests -- step 1, held attachments, stale step with progress since Resume, signals, Resume order, disk check, revert by token
- [ ] `Command.php` + tests -- `release` inspection and confirmed release
- [ ] `Notices.php`, `RestController.php`, `Command.php`, `admin.js`, `admin-page.php`, `Admin.php`, `readme.txt`, `CHANGELOG.md` + tests -- notices, errors, `queue_signals`, texts
- [ ] `tests/Integration/*` -- the matrix. AS states are set up through the store in tests only.

**Acceptance Criteria:**
- **Late worker.** Given T1 recovered and T2 already published V (served file hash h2), when T1's worker continues and reaches publication of V, then `hash_file()` of the served file equals h2 and the row's `file_hash`, and T1 changed no row and scheduled nothing.
- **Publication incomplete.** Given a DB error on `done` after the rename, then the lease stays, and the held notice names the path and the possible hash mismatch.
  - When `release` runs with the shown attempt at `attempts` < 3, the variant returns to `pending` without touching the file. The next attempt keeps the file, ends the variant `failed` / `target_exists_foreign`, and resets `attempts`.
  - At `attempts` = 3 the attachment ends `failed` / `max_attempts` with the file kept.
- **Long sync.** Given a WP-CLI sync running for longer than 900 s, when `JobHealth` runs from admin, Maintenance and Resume, then the attachment stays with that attempt, and another sync answers busy before any write.
- **Pause.** Given a paused bulk, when any pending action of the job runs (recovered ones included), then nothing is converted and the attachment is parked with its `job_id`. After Resume, it is converted even behind the cursor, and the job completes only afterwards.
- **Failing producer.** Given a stopped job with `last_error` E, when Resume runs and producer scheduling fails, then the job is `paused` with `stopped` and E, and the error is shown.
- **Waiting producer.** Given a successful Resume while the queue is stuck, after which the producer ran `wait()` once and no conversion finished, when wp-admin loads past the stale threshold, then the job stays `running` with the warning. When AS runs again, the bulk continues without a second Resume.
- **Schema pending.** Given stored schema 1.1.0, when a queued action runs, then no claim happens, `attempts` is unchanged, the action is rescheduled, and a user pause still writes `pause_reason`.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Design Notes

These calls were made by the spec and are confirmed with the frozen block:
- **Progress since Resume** is computed from data (worker-final attachment states, or cursor writes for other job types) and never from `touch()`.
- **The producer, not Resume, queues the parked set.** That gives one path, and it covers failed queueing calls.
- **Step 1** handles 20 candidates per call.
- **Schema-pending retry** every 3600 s. Each retry leaves an AS row, so the interval is long.
- **Resume revert keeps a newer death.** It uses `COALESCE( last_error, previous )`, so a death recorded between `mark_running()` and the revert is not overwritten. This matches AD-7 as amended.
- **Inspection is read-only.** It does not stamp a token. The `since` and `lease` values identify the attempt; this replaces the stamping idea from the spec memlog.
- **`not_smaller`.** The publication refactor keeps today's outcome: the row becomes `skipped` and the row's previous file is discarded. AD-14 re-optimization is not part of #30.

Accepted risks:
- **Live process recovered.** A queue attempt is recovered once AS gave up its action, while the PHP process may still run (owner decision). The lease keeps it from changing a served file; its work is wasted.
- **Leftover temporary files.** A worker killed after writing its temporary file leaves `*.tmp-*` next to the target. Cleanup of such files is #19.
- **Second precision.** Progress uses `updated_at > resumed_at` at second precision. An attachment finishing in the same second as the Resume does not count, so the job is not stopped early.

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: clean
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.5` -- expected: pass
- `tools/bin/test integration 8.0 7.0` and `tools/bin/test integration 8.5 latest` -- expected: pass
- `WP_MULTISITE=1 tools/bin/test integration 8.0 7.0 "--group ms-required"` -- expected: pass

**Manual checks** (Docker stand, `DISABLE_WP_CRON`, no system cron):
1. Start a bulk and kill the PHP-FPM worker during a conversion.
2. Load wp-admin: the job is paused with the notice.
3. Press Resume, then reload wp-admin after 15 minutes: the job is still running and the warning is still shown.
4. Turn WP-Cron back on: the bulk continues without a second Resume, and the killed attachment is never converted while the job is paused.
5. Run `wp trust-optimize release <id>` on a held row: inspection changes nothing, and the confirmed release frees it.
