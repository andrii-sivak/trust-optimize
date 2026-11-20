---
name: 'TrustOptimize'
type: architecture-spine
purpose: build-substrate
altitude: feature
paradigm: 'layered WordPress plugin with one composition root; producer-consumer on Action Scheduler; persistent state machines mutated by compare-and-set'
scope: 'TrustOptimize plugin as of main 61e8212 (amended for #34; AD-1 exceptions recorded at 0943f8c): queue and Action Scheduler, bulk jobs and their liveness, resource gate and pacing, format capability, file ownership, variant storage, multisite'
status: final
created: '2026-11-16'
updated: '2026-11-20'
binds: ['#10', '#19', '#21', '#22', '#26', '#28', '#29', '#30', '#31', '#32', '#34', '#35']
sources: ['code at 0943f8c and 61e8212', 'AGENTS.md', 'readme.txt', 'GitHub issues #1-#40', 'owner decisions 2026-11-17', 'owner decisions for #30 2026-11-17 (AD-7, AD-9, AD-10)', 'owner decisions for #34 2026-11-18 (AD-5, AD-8, AD-9, AD-10)', 'owner decision for #19 2026-11-20 (AD-13): occupied target stays failed/target_exists_foreign; skipped, its display and its retry rules are deferred', 'owner decision for #10 2026-11-20: attached file only; positive metadata trusted; unknown_dimensions keeps done variants under AD-14; too_large deletion unchanged']
companions: []
---

# Architecture Spine — TrustOptimize

## Design Paradigm

**Layered WordPress plugin with one composition root.** `Core\Plugin` builds every service through constructors and registers hooks. Background work is **producer–consumer on Action Scheduler (AS)**: producers hand attachment IDs to the queue, one AS action converts one attachment. Persistent state lives in **state machines in the plugin's own tables**, changed by **compare-and-set (CAS)** updates in repositories wherever writers can race.

| Layer | Namespaces (`includes/`) | Role |
| --- | --- | --- |
| Entry | `admin` (screens, notices, statistics), `api`, `cli`, `frontend`, `health`, `queue` (hook callbacks) | WordPress hooks, REST, WP-CLI, screens, delivery |
| Orchestration | `bulk`, `processing`, `planning`, `queue` | jobs, producer, worker loop, gate, planning, lifecycle, maintenance |
| Services | `capabilities`, `features/optimization`, `files`, `naming`, `service`, `settings`, `utils`, `Admin\Settings` (option reader) | conversion, file writes and cleanup, capability, settings |
| Storage | `storage`, `bulk/BulkJobRepository`, `database` | tables, CAS methods, schema |
| Shared | `domain`, `value` | status constants, result objects |

## Invariants & Rules

```mermaid
flowchart TD
  Core["Core\\Plugin (composition root)"] -.wires.-> Entry
  Entry["Entry: admin, api, cli, health, queue callbacks"] --> Orch["Orchestration: bulk, processing, planning, queue"]
  Entry --> Services
  Entry --> Storage
  Orch --> Services["Services: capabilities, optimization, files, naming, cleanup, settings"]
  Orch --> Storage["Storage: repositories, DatabaseManager"]
  Services --> Storage
  Front["Entry: frontend"] -->|read variant state only| Storage
  Shared["Shared: domain, value"]
  Entry --> Shared
  Orch --> Shared
  Services --> Shared
  Storage --> Shared
```

### AD-1 — One composition root, layered dependencies [ADOPTED]

- **Binds:** all
- **Prevents:** hidden service instances with diverging configuration; new upward dependencies; the front end writing files.
- **Rule:**
  - Services are created only in `Core\Plugin` and passed through constructors. The only exceptions are the static `Lifecycle::deactivate()` and `uninstall.php`, which run without the plugin booted.
  - Dependencies point down the diagram above. New code adds no upward edge. Existing exceptions at 0943f8c:
    - `Admin\Settings` is the option reader for every layer;
    - `VariantRepository` uses `OptimizationSettings`;
    - `ImageCleanupService` cancels tasks through `ConversionQueue`;
    - `Admin\Statistics` uses `PictureRenderer`.
  - Front-end rendering never writes files or loads an image editor; it only reads variant state.

### AD-2 — Plugin tables are the single source of truth [ADOPTED]

- **Binds:** storage, planning, processing, bulk, admin, REST, WP-CLI
- **Prevents:** two copies of state that drift (postmeta vs tables, stored counters vs rows).
- **Rule:**
  - Attachment, variant and job state lives in the tables `trust_optimize_attachments`, `trust_optimize_variants` and `trust_optimize_jobs` (behind the site prefix). Nothing is stored in postmeta.
  - The attachment state is derived from its variant rows by `recompute()`.
  - Job progress is derived from attachment states by `job_id`, or from the cursor (`JobProgress`). It is never stored as counters. Admin, REST and WP-CLI keep their progress fields, filled from `JobProgress`.

### AD-3 — State changes that can race are repository CAS [ADOPTED]

- **Binds:** storage, bulk, queue, processing, cleanup
- **Prevents:** lost updates and double work between concurrent workers, admin requests and WP-CLI.
- **Rule:**
  - Transitions that several writers can attempt are conditional `UPDATE … WHERE <expected state>` in a repository: queueing, claiming, requeueing, parking, failing after a death, and every job status change. The caller acts only if the update hit a row.
  - Unconditional writes (`set_state`, `recompute`, `assign_job`, `VariantRepository::upsert`) are made only by the current claim holder, by the planner, or by the producer for its own job.
  - **Variant evidence statuses.** Only a worker's token-guarded transition writes or refreshes a variant row in `processing`, `done`, `skipped` or `failed`. The exceptions are the death CAS and the poison CAS (`failed` / `max_attempts`) and cleanup (`failed` / `delete_failed`). The planner writes only `pending`, and refreshing an existing row never touches its `updated_at` unless it changes its status to `pending`. A new writer of these statuses (AD-14 keep-done, #26, #35, #40, and the deferred occupied-target status change) uses its own reason and is added to the AD-8 exclusions in the same change.
  - Status values are constants of `Domain\AttachmentState`, `VariantStatus` and `JobStatus`, within `COLUMN_LENGTH` (32).

### AD-4 — One AS action per attachment, scheduled by one place [ADOPTED, amended]

- **Binds:** queue, bulk, processing, gate, lifecycle, maintenance, #26, #30, #35
- **Prevents:**
  - batch actions that hide which file killed a worker;
  - duplicate or competing next actions for one attachment;
  - actions outside the plugin's cleanup reach.
- **Rule:**
  - **Hook and group.** Conversion is the action `trust_optimize_process_attachment` with args `attachment_id => N`. Every plugin action is in the group `trust-optimize`.
  - **Background entry points.** Every one of them (uploads via `wp_generate_attachment_metadata`, imports, bulk, retries) creates work through this queue. The first action is enqueued only after a queueing CAS (`mark_queued`, `requeue`) hit a row. Deduplication is that CAS; AS `$unique` is used only for actions without args, because its meaning differs between AS 3.x and 4.x.
  - **Next action.** Only `ConversionQueue::process()` schedules an attachment's next action, from the worker result: `finished`, `continue` (time budget spent), `defer` (at the gate's next attempt time) or `parked`. The gate and the worker never schedule. Pending variants alone are not a reason to continue.

### AD-5 — The plugin is a guest in the shared Action Scheduler

- **Binds:** queue, bulk, maintenance, lifecycle, #1, #26, #30, #34
- **Prevents:** one task slowing or reconfiguring a queue other plugins share; plugin code fighting AS over claims.
- **Rule:**
  - **Priorities.** Service actions (producer, Maintenance, Restore) are scheduled with priority 10. Every conversion uses priority 20: the first enqueue (uploads, imports, bulk hand-over, restore), continuations, recoveries, requeues, the parked set after Resume and deferred retries. #34 implements this.
  - **Backpressure.** The producer runs ahead of its conversions only as far as the existing limit allows: at the start of a run it waits while more than `trust_optimize_bulk_max_pending` conversions are pending, otherwise it queues up to one batch of parked and one batch of new attachments, so pending conversions may exceed the limit by up to two batches. Adaptive pace is #26 (AD-11). Accepted: during a bulk, a new upload may wait behind that backlog, and every conversion yields to other plugins' default-priority actions. Priority orders only due actions at claim time; a batch already claimed runs on.
  - **Pacing.** The plugin never changes AS globally: no runner filters (batch size, concurrency, time limit, timeouts). Pacing lives in the plugin's own tasks.
  - **API.** The plugin uses only AS's documented API: `as_*` functions and the store status constants. It never calls `ActionScheduler_QueueCleaner`, never touches claims or stores directly.
  - **Dead claims.** They are released only by AS's own runner (`run()` from WP-Cron, system cron or WP-CLI), when it actually runs, by AS's own thresholds and settings. A site without any of these keeps a dead claim, and the async runner does not start. The plugin detects and reports that state (AD-7, AD-9); it does not fix it.

### AD-6 — Bulk job = one producer that never converts [ADOPTED]

- **Binds:** bulk, queue, REST, WP-CLI, admin
- **Prevents:** a bulk doing conversions in its own request; two producers for one job; two active jobs per site.
- **Rule:**
  - One active job per site, guarded by the `trust_optimize_bulk_active` mutex option (`INSERT IGNORE`).
  - Each job has one self-rescheduling producer action. The producer moves an ascending ID cursor, assigns `job_id`, and hands attachments to the queue (AD-4) under backpressure on pending actions. It never converts itself.

### AD-7 — Pause parks the job's work; only an explicit Resume continues

- **Binds:** bulk, gate (AD-11), lifecycle, maintenance, job-health check (AD-9), admin, REST, WP-CLI, #30, #35
- **Prevents:** paused jobs that keep converting; deferral loops while paused; work lost or duplicated by Resume; automatic resumes that loop on a poison file; a pause reason lost in `last_error`; a Resume refused on a guess about the queue.
- **Rule:**
  - **Reason.** A paused job stores `pause_reason` apart from `last_error`. Values: `user`, `deactivated`, `low_disk_space`, `stopped` (dead worker or stale job), `unknown` (migrated pause whose reason cannot be determined).
  - **Parking.** When the gate meets an attachment whose job is paused, the action ends without converting and without scheduling. The attachment moves by CAS to state `none` with reason `paused`, keeping its `job_id`. A conversion already running may finish.
    - **Before the gate exists (#26), `process()` parks.** The claim CAS itself refuses an attachment whose job is paused, so a Pause committed before the claim always wins. There is no separate check before the claim.
    - That applies to every pending action of the job, recovered ones included.
    - A parked attachment is neither a deferral nor a death.
    - It counts as unfinished work of its job.
    - Maintenance and Restore never queue it.
    - Deactivation parks the same way, with reason `deactivated` (AD-16).
    - One repository method defines the parked set.
    - A job never completes while its parked set is not empty.
  - **Resume** (admin screen, REST `POST /bulk/resume`, `wp trust-optimize resume`) is the only way a job continues, for every reason. Nothing resumes a job automatically.
    - After Resume, every unfinished attachment of the job (parked, queued or behind the cursor) remains reachable for queueing. That holds even if a runner picks up an action at once, or some queueing calls fail. The order of the status write and the queueing is fixed in the spec.
    - A parked attachment behind the cursor is still picked up.
    - A Resume that fails restores the job's previous `pause_reason`. It restores the previous `last_error` too, unless a death recorded a newer one in the meantime; that newer error is kept.
    - Resume runs step 1 of the job-health check first (AD-9). Its status CAS then clears the job's `last_error`.
    - **Queue signals do not block Resume.** When the queue signals of AD-9 are present, Resume still continues the job. It warns that the work runs only once Action Scheduler's own runner can run again.
    - **Refusal on queue grounds** needs a confirmed block. The documented AS API cannot confirm one, so the age of actions is never grounds for refusal. The disk re-check on Resume (AD-11) stays.
    - A Resume that fails by itself (the status write or the producer scheduling) reports that error.
  - **Outside the bulk.** Attachments that do not belong to the paused job (uploads, imports) keep converting.

### AD-8 — Job liveness is derived from data by one function

- **Binds:** job-health check, admin notices, Site Health, REST/WP-CLI status, variant and job-row writers, #34, #30, #26, #19, #35, #40
- **Prevents:** each consumer inventing its own staleness test; a heartbeat write on the conversion path; healthy but slow or deferred jobs declared stale.
- **Rule:**
  - **One function.** It decides whether a running job is alive, reading existing data only:
    - the producer's own steps (cursor, hand-over, wait), read from the job row's `updated_at`;
    - attempt evidence among the job's attachments (below);
    - pending deferrals: reason, next attempt time, and an AS action that really exists.
  - **Attempt evidence** is the only kind of attachment change that counts:
    - a claim (only `claim()` stores `processing`);
    - a worker's variant transition: to `processing`, `done` or `skipped`, or to `failed` for a reason other than `max_attempts` and `delete_failed`.

    An attachment's stored state is not evidence. `recompute()` and `set_state()` re-derive it outside any worker, and so do the planner, regeneration and cleanup. A claim is read from the row while it is still `processing`; once the attempt ends, its variant transitions carry the evidence (AD-3 keeps them reliable).
  - **Job row.** While a job runs, only producer steps, start and Resume refresh its `updated_at`. Every other job-row write (`record_death`, metrics, snapshots written from outside the producer) leaves it unchanged.
  - **Two consumers.** Liveness = a producer step, attempt evidence or a pending deferral within the threshold. Progress since Resume (AD-9) = attempt evidence since the Resume for a sync job, a cursor move since the Resume for other job types. A `wait()` keeps a job alive but is never progress since Resume.
  - **Deferral data.** It is read through one repository method, owned by whoever implements AD-11.
  - **No liveness writes.** The conversion path makes no write whose only purpose is to signal liveness.
  - **Not stale by itself.** None of these alone makes a job stale or alive:
    - the age of the job row;
    - waiting behind higher-priority actions;
    - the mere existence of an AS action.
  - **Threshold.** The public filter `trust_optimize_bulk_stale_after_seconds` is the time without progress: no attempt evidence, no producer step, and no pending deferral whose next attempt is still ahead. Operator release, the held list and the queue signals keep 900 s.
  - **Recovery is not progress.** AD-10 recovery and release, Maintenance repairs, parking, suspension and cleanup's delete failures never count as a change.
  - **Slow attempt.** A running claim counts as evidence for the AD-10 recovery wait, `max( 900 s, threshold )`, so a job is never stopped while its attempt is still protected from recovery. Other evidence counts for the threshold. A single encode longer than the recovery wait looks like no progress; the plugin makes no liveness write during an encode (a decision: GD and AVIF encoders offer no callback, and an Imagick progress monitor would cover WebP only).
  - **Stuck in-flight work.** A producer `wait()` keeps a job alive even when some in-flight row cannot move by itself. Such rows are closed where they arise: a poison row by the poison CAS (AD-10), and a held attempt by the operator's release after the notice.

### AD-9 — One job-health check, in the site's context

- **Binds:** bulk, maintenance, admin, REST, WP-CLI, multisite, #25, #30, #34
- **Prevents:** recovery logic scattered over hooks with different exclusions; recovery triggered by `admin_init` inside an AS async-runner request; each consumer guessing on its own whether the queue is stuck.
- **Rule:**
  - **Ownership.**
    - #30 builds the entry point and removes the unguarded `admin_init` stale recovery from `BulkProducer`.
    - #34 replaces step 2.
    - #26 supplies the deferral data.
  - **Callers.** One entry point is called from:
    - admin page loads, guarded by `! wp_doing_ajax() && ! wp_doing_cron()`;
    - Resume (AD-7);
    - the Maintenance action when it runs.
  - **Order.**
    1. Handle confirmed worker deaths (AD-10).
    2. Judge liveness (AD-8).
    3. Pause a stopped job with `stopped` (AD-7).
  - **Who runs which steps.**
    - Steps 2 and 3 run only on normal admin page loads. Resume and Maintenance run step 1 and never stop a job; REST and WP-CLI status only read. The AD-8 function changes how liveness is judged, not who may stop a job.
    - **One stop per stoppage.** A queue stoppage that began before the job's last Resume never stops the job again. It only keeps the warning.
    - Once AS can run tasks again, the job continues from its saved progress without another Resume.
  - **Queue signals.** Read only through `as_get_scheduled_actions()`, with a threshold of 900 s (`AttachmentRepository::STALE_CLAIM_SECONDS`).
    - **Stuck claim.** An action of the group `trust-optimize` is `in-progress`, or `pending` and claimed, and has not changed for more than the threshold.
    - **No progress.** Due `pending` actions of the group have waited longer than the threshold, and no conversion action of the group reached `complete` within it. `failed` does not count, because AS 4.x stamps a timeout failure with the failure time.
    - Never-claimed `pending` actions carry no attempt time, so the age tests on `modified` apply to claimed actions only.
    - They are diagnostics only. They feed the notice and the Resume warning (AD-7). They never pause a job, refuse a Resume or touch a claim (AD-5).
  - **Notice.** Administrators see a notice with a link to Bulk Optimization while a job is paused for any reason except `user`: `stopped`, `low_disk_space`, `deactivated` after reactivation, `unknown`.
    - It names the `pause_reason` first. For `stopped` and `unknown` it then shows the job's `last_error` (written by death recovery, AD-10), or says plainly that the cause is unknown.
    - When the disk is still full or a queue signal is present, the notice first explains what prevents continuing.
    - A running job with a queue signal gets the same warning, without a Resume button.
  - **Scope.** The check works only on the current site's tables, options and queue. There is no separate night-time check.

### AD-10 — A worker death is counted once, by the claim [ADOPTED, amended]

- **Binds:** storage, processing, planning, optimization, lifecycle, maintenance, job-health check, queue, REST, WP-CLI, #30, #31, #34
- **Prevents:**
  - a poison file re-queued forever;
  - one death counted two or three times;
  - a live conversion taken over while AS still holds its action;
  - a late worker overwriting a recovery or publishing over a newer attempt.
- **Rule:**
  - **Counting.** `claim()` increments `attempts`; it is the only place that counts. A worker that ends normally (finished, continue, defer, parked) resets them. A wait or a deferral is never a death.
  - **Attempt record.** `claim()` records the attempt: who started it (an AS action or a synchronous request) and a token that identifies this claim.
    - The record lives in columns of `trust_optimize_attachments`, added by schema 1.2.0 (AD-17).
    - Recovery and the late-worker checks key on that record.
  - **Confirmed death.** An attempt that AS started is dead when both hold:
    - its attachment has been `processing` for longer than the recovery wait: `max( AttachmentRepository::STALE_CLAIM_SECONDS (900 s), trust_optimize_bulk_stale_after_seconds )`, one setting for job staleness (AD-8) and this wait;
    - AS answers that it has no `pending` or `in-progress` action for that attachment. This is one `as_get_scheduled_actions()` query covering both statuses.

    No answer is not a death: AS not initialized, a failed query, or AS tables missing on a site.
    - AS fails an action that has been running longer than its own failure period (300 s by default) without checking the worker. Past that, a live conversion is protected only by the late-worker rule below.
    - Even then the old PHP process may still be running, so a recovery never relies on the worker being gone. Publication is guarded separately (below).
    - **Slow encode.** An encode longer than the recovery wait can be recovered while its process lives. A higher filter value gives slow encodes time and delays recovery of a real death. A host whose AS failure period (10 × the runner time limit) exceeds the wait is protected by AS's own answer for that long. A time limit on the encoder is a separate task.
    - **Never by age alone.** A synchronous attempt (REST, WP-CLI) and a legacy attempt without a recorded source are never recovered automatically. No age, a day included, proves them dead.
    - While such an attachment stays `processing`, the operator sees why: what holds it, since when, and how to release it (the spec defines this).
    - A synchronous request for an attachment that any attempt holds answers busy.
  - **One recovery owner.**
    - Step 1 of the job-health check (AD-9) recovers dead attempts, whether it runs from admin page loads, Resume or Maintenance.
    - `claim()` and `mark_queued()` never take over a `processing` attachment by age. `claim()` runs inside the attachment's own AS action, so it could never confirm a death; it answers busy.
  - **Death CAS.** Leaving `processing` after a death is one repository CAS, keyed on the dead attempt, so it moves that attempt once:
    - `failed` with reason `max_attempts` when `attempts >= AttachmentRepository::MAX_ATTEMPTS` (3), and the attempt's `processing` and `pending` variants become `failed` / `max_attempts`;
    - otherwise the attempt's `processing` variants go back to `pending`, and the attachment back to `queued`, or parked if its job is paused. The recovering path then schedules the action at priority 20 (AD-4).
  - **Only the attempt, the death CAS or deactivation's suspension (AD-16) leaves `processing`.** The unconditional writes AD-3 allows (planner, producer, `recompute`, `set_state`) never move an attachment into or out of `processing`.
  - **Poison file.**
    - **One predicate:** `attempts >= MAX_ATTEMPTS`, whatever the stored state or reason (`recompute()` may rewrite them).
    - Every queueing CAS (`mark_queued`, `requeue`, the Lifecycle restore) refuses such an attachment. So bulk, metadata regeneration, Maintenance and reactivation never queue it.
    - A queue `claim()` refuses it too, so a late AS action neither converts it nor resets its count. A refused row that no attempt holds and that is not yet `failed` ends `failed` / `max_attempts` with its `pending` variants (the poison CAS), so no job keeps it in flight.
    - **The poison CAS only closes rows without an owner.** It requires `state <> processing` and no attempt token, so it never changes a `processing` row and never revokes a live token. A held attempt, the third included, stays with the attempt itself, the death CAS and release (above).
    - Only two things reset the count:
      - an explicit sync, inside its own claim, so a refused sync leaves the count intact;
      - a replaced source file, detected as in AD-14, which planning then queues as usual.
  - **Late worker.** Every write of an attempt is conditional on still holding it:
    - the `attempts` reset;
    - the attachment state writes;
    - variant transitions out of `processing`;
    - the publication: the final token check, the rename and the `done` write (AD-13);
    - next-action scheduling.

    A worker that finds its attempt recovered stops, and `process()` schedules nothing for it. A new attempt never resets variants that another attempt still holds.
  - **Publication excludes revocation.**
    - Between an attempt's final token check and its rename, no recovery can revoke that token and no other attempt can take the attachment over.
    - When the plugin cannot establish that exclusion, it does not rename: the file at the target path stays as it is, and the variant records an error.
    - A hash check proves ownership (AD-13); it does not close this window. The mechanism is the #30 spec's.
  - **Job error.** When the death CAS moves an attachment of an unfinished job, `BulkJobRepository` replaces the job's `last_error` (never appends) with the attachment ID and the death count.
    - The write does not touch the job's `updated_at`. A recovery is never progress (AD-8).
    - It is made on the current schema only, because the old schema keeps pause reasons in `last_error`.
  - **Normal errors.** A conversion error of one file is recorded on its variant and never stops the bulk.

### AD-11 — One resource gate before every encoder call

- **Binds:** processing, queue, bulk, REST, WP-CLI, #26, #31, #32, #35
- **Prevents:** disk, memory, pace and pause checks spread over planner, producer and worker with different reactions; background conversions escaping the pace by entering outside the bulk.
- **Rule:**
  - **Where.** The gate runs in the executing request, per variant (size × format), right before an image editor is opened, even if planning already passed.
  - **Answers.**
    - `start`;
    - `park` (owning job paused, AD-7);
    - `defer`, with reason and next attempt time;
    - `skip`, with a reason stored on the variant.
  - **Inputs.**
    - the owning job's pause;
    - free disk on the variants' filesystem;
    - a memory estimate for size, format and encoder, checked against PHP `memory_limit` and against container headroom when known (a conservative estimate otherwise);
    - the background pace.
  - **One at a time.** At most one background TrustOptimize conversion runs per site, whatever runner executes it (WP-Cron, async request, system cron, WP-CLI) and whatever the entry point (uploads, imports, bulk, retries). Waiting for that slot is part of the pace.
  - **Defer.**
    - The worker ends like a continuation: attempts reset, the claim is released to `queued`, and reason and next attempt time are recorded.
    - `process()` then schedules the retry at priority 20 (AD-4).
    - A failed scheduling must be visible to recovery.
    - Every resource deferral has an explicit exit condition, so one attachment cannot hold a bulk open forever.
    - Waiting for the slot or for the pace is not a resource deferral and never uses up that limit.
  - **Low disk.**
    - In a bulk it pauses the whole job with `low_disk_space` (AD-7). Queued actions still check disk before converting, and Resume checks again.
    - Outside a bulk it defers.
  - **Synchronous paths** (REST `/image/<id>/sync`, `wp trust-optimize sync --now`, `wp trust-optimize sync_attachment`, per-image actions) convert in the request.
    - They apply the disk and memory checks and return the result at once. They never defer.
    - They stay outside the one-at-a-time slot.

### AD-12 — Write capability belongs to the runtime that converts

- **Binds:** capabilities, planning, optimization, admin, WP-CLI, readme, #21, #22
- **Prevents:** a web check taken as proof for CLI; a planner in one runtime deciding for an executor in another; a shared editor cache choosing for the wrong runtime; one bad file disabling a format.
- **Rule:**
  - **Probe.** Whether a format can be written is known per (runtime fingerprint × editor × format). It is proven by a probe write of a small image in that runtime and cached under the fingerprint. A disabled format counts only in the runtime that recorded it.
  - **Planner.** It decides desired variants from settings and the source image only.
  - **Executor.** It checks write capability in its own runtime.
    - `format_unavailable` describes an attempt to create a file. When no editor can write the format, that attempt ends as `skipped` with reason `format_unavailable`, and the user setting stays on.
    - An existing `done` variant follows AD-14: it keeps being served while its replacement cannot be created.
    - Planning retries `format_unavailable` attempts, so a runtime that can write the format processes them later.
  - **Editor choice.** The executor creates the editor class its own runtime's probe chose, e.g. GD when Imagick cannot write and GD can.
    - It never relies on core's cached editor choice, which is shared across runtimes and sites.
    - It never changes the editor WordPress uses for its own sizes.
  - **Errors.** A single file error never disables a format.
  - **Visibility.** The admin shows web and WP-CLI results per format: the chosen editor, or the reason the format is unavailable. Until a CLI probe has run, it shows "WP-CLI: not checked". A CLI conversion reports each format it skipped.

### AD-13 — The plugin touches only files it provably owns [ADOPTED, amended]

- **Binds:** optimization, files, cleanup, uninstall, per-image actions, #19, #30, #40
- **Prevents:** overwriting or deleting originals, WordPress sizes, other plugins' files or a foreign file that appeared on a reserved path.
- **Rule:**
  - **Naming and writing.** A variant file is `<source file>.<format>` (`photo.jpg.webp`) next to its source inside uploads. It is written as a temporary file plus rename, after its row reserved `relative_path`.
  - **The rename is part of a publication.** It never replaces a file that another attempt published. A served file only changes through a publication that still holds its attempt (AD-10).
  - **Ownership** is confirmed only when a variant row owns the `relative_path` and the file's hash equals the row's `file_hash`, which is recorded after a successful write. A reservation row plus a matching path is not confirmation.
  - **What may be changed.** The plugin changes or deletes only files with confirmed ownership. Originals, WordPress sizes and other attachments' files are never touched.
  - **Occupied target.** When the target path holds a file without confirmed ownership, the writer records `failed` / `target_exists_foreign` and a message saying the file exists and is not confirmed as TrustOptimize's. Work continues. The plugin never adopts, overwrites or deletes such a file, in any action. The file is kept, and the attempt ends normally, not as a death.
  - **Deletion.** A file shared by several rows is deleted by its last owner. Every deletion is verified on the filesystem; a row whose file could not be deleted is kept as `failed`.

### AD-14 — Variant storage, staleness and delivery during re-optimization [ADOPTED, amended]

- **Binds:** planning, storage, frontend, bulk sync
- **Prevents:** serving unfinished files; a delivery gap while a variant is re-optimized; serving a variant that no longer matches its source; per-job profiles diverging from current settings.
- **Rule:**
  - One row per `(attachment_id, size_name, format)`.
  - A variant is servable only through `VariantRepository::servable_path()`, which requires status `done` and a `relative_path`.
  - A row is stale when its stored parameters differ from the current settings or its source file changed. There is no profile hash.
  - **Re-optimization.** A `done` variant keeps status `done` and keeps being served until its replacement is successfully published, as long as it still matches the current source file and its format is enabled.
    - It leaves delivery when the source is replaced or deleted, the format is disabled, or the user removes the variant explicitly.
    - A deferred or failed replacement, including `format_unavailable`, never removes it from delivery.
    - How a changed source is detected is left to the spec.
  - A sync job brings the library to the current settings.

### AD-15 — Multisite: everything is per site, network operations iterate sites

- **Binds:** database, queue, bulk, lifecycle, uninstall, job-health check, #28, #29
- **Prevents:** a network action that only reaches the current site; state leaking between sites; writes to a site whose schema is not migrated.
- **Rule:**
  - **Per site.** Each site has its own plugin tables, AS queue, options, mutex, capability cache and uploads. Table names are resolved per call. Tables are created and migrated lazily by `check_version()`.
  - **Network operations.** Network activation, deactivation and uninstall apply the per-site operation to every site, inside `switch_to_blog()` for that site. An operation writes only through the schema version that site actually has: activation and deactivation may write to a schema they know, and uninstall never migrates. AS calls are skipped when AS tables are missing on that site.
  - **Health check.** The job-health check and the gate run only in the current site's context.
  - **Reactivation.** Network reactivation restores service actions but never resumes a paused bulk (AD-7).

### AD-16 — Deactivation parks, activation restores without resuming jobs [ADOPTED, amended]

- **Binds:** lifecycle, queue, bulk, #28
- **Prevents:** actions firing with no callback after deactivation; reactivation silently restarting a paused bulk.
- **Rule:**
  - **Deactivation** unschedules the whole `trust-optimize` group, parks unfinished attachments (`none` with reason `deactivated`) and pauses active jobs with `deactivated`.
  - **Activation** schedules Maintenance and a restore in batches. The restore query excludes, in SQL, attachments whose job is paused; those wait for that job's Resume.

### AD-17 — Schema changes ship as one versioned, repeatable migration

- **Binds:** database, bulk, REST, readme, #34, #26, #30
- **Prevents:** half-migrated sites; agents writing to columns that no longer mean anything; a migration that fails forever on its second run.
- **Rule:**
  - **Version bump.** Every schema change bumps `DatabaseManager::DB_VERSION` in the same commit.
  - **Running the migration.**
    - Each step checks the current schema before acting, because MySQL 5.7 has no `DROP COLUMN IF EXISTS`. A second run is a no-op.
    - The new version is stored only after every step succeeded.
    - Removed columns are dropped explicitly, because `dbDelta()` does not drop them.
  - **Ownership.** The jobs-table change is its own pull request. It is merged before any change in #30, #34 or #26 that uses the new schema; the order among those three is not fixed.
  - **Rollback.** The changelog states that replacing the plugin with an older version after the migration is not supported; rollback uses a consistent backup of files and database.
  - **The jobs-table change is one bump:**
    - Add `pause_reason` and move known reasons (`deactivated`, `low_disk_space`) out of `last_error`. A paused job whose old reason is unknown gets `unknown` and stays paused for manual Resume.
    - Display-only batch metrics go to `settings_snapshot.metrics`.
    - Drop `processed`, `skipped`, `failed_count`, `created_count`, `deleted_count` and `profile_hash`. The REST response loses `profile_hash`, and `readme.txt` records that. Progress fields stay in every output (AD-2).

### AD-18 — Diagnostics go to private files, never into decisions

- **Binds:** processing, gate, pacing, admin, uninstall, #26, #31, #32
- **Prevents:** logs carrying secrets, paths, image names, or free text; a recording failure changing a conversion; an update turning a saved off back on; the event stream in the database.
- **Rule:**
  - **What.** JSONL diagnostics per site. A recorded conversion writes a start line flushed before the heavy call and a result line for what that call returned. The start line does not say why a process stopped. Pace and gate lines are written only by those features.
  - **Switch.** The option is `trust_optimize_diagnostics`, its own row, not a key of `trust_optimize_options`. Recording is on when the row is absent and when `get_option` returns the string `1`. The stored string `0` is off. A checkbox on the existing settings screen writes `'1'` or `'0'`. A saved `0` stays through later updates. While off, code whose only purpose is a diagnostic record does not run and does not write a new event. Existing files stay. Periodic age cleanup still runs.
  - **Privacy.** Files live in that site's `uploads/to-logs`. The plugin writes the usual deny files and never emits a URL. A server that ignores those files can still serve the directory; that residual risk is accepted. The files hold ids, reason codes, and numbers only.
  - **Retention.** Files are kept 60 days from their last write. Starting caps are 512 MiB per site and 8 MiB per file. They are maxima, so a busy site keeps less than 60 days. The size cap is checked on every write. Age cleanup is periodic and does not depend on Action Scheduler. Uninstall deletes `to-logs` only when remove-data is set and that request drops the site's options.
  - **Isolation.** A recording failure, including a lock that is not acquired, does not interrupt a conversion or change its outcome. Writer time stays inside the worker deadline. `conversion_id` joins a start to its result, authorizes nothing, and is not stored.
  - **DB vs files.** The database keeps only job state and summary metrics (AD-17). A missing or skipped line is not a cause.

## Consistency Conventions

| Concern | Convention |
| --- | --- |
| Prefixes | Hooks, options, transients and tables `trust_optimize_`; AS group `trust-optimize`; WP-CLI `wp trust-optimize` |
| Reason codes | `snake_case` strings in `reason` columns and `pause_reason` (`paused`, `deactivated`, `max_attempts`, `target_exists_foreign`, `format_unavailable`, `low_disk_space`, `stopped`, `unknown`); human text goes to `last_error` |
| Dates | UTC from `current_time( 'mysql', true )`; local time only for display; never rely on a column's `CURRENT_TIMESTAMP` default |
| SQL | `$wpdb->prepare()` with table names as `%i`; interpolated `{$this->table()}` in the repositories is legacy, converted when touched |
| Errors | One failing attachment or variant never stops the job; exceptions in a worker mark the attachment `failed` and rethrow so AS records the action |
| Public surface | Existing hooks, filters, settings, REST routes and WP-CLI commands keep working. A spec that changes the meaning of one, or removes one, asks the owner. Any change updates `readme.txt` in the same commit |
| Authorization | Starting, pausing, resuming or cancelling a bulk and acting on notices require `manage_options`; per-image routes keep their current checks |
| WordPress APIs | Check `@since` of every core call against WP 7.0; guard newer ones with `function_exists()` |

## Stack

As of 2026-11-17.

| Name | Version |
| --- | --- |
| PHP | 8.0–8.5 |
| WordPress | 7.0+ (tested up to 7.1) |
| Action Scheduler (bundled) | 3.9.3; a newer copy from WooCommerce may load first (4.x seen); upgrade tracked in #1, latest stable 4.2.0 |
| Image editors | WordPress `WP_Image_Editor` (GD, Imagick) |

PHP 8.6 and WordPress 7.2 are not yet covered.

## Structural Seed

```mermaid
erDiagram
  ATTACHMENT_POST ||--o| ATTACHMENTS : "state row"
  ATTACHMENTS ||--o{ VARIANTS : "one per size x format"
  JOBS ||--o{ ATTACHMENTS : "job_id"
  JOBS ||--|| PRODUCER_ACTION : "one pending at a time"
  ATTACHMENTS ||--o| PROCESS_ACTION : "one while queued"
```

```mermaid
flowchart LR
  Upload["upload / import<br/>wp_generate_attachment_metadata"] --> Plan[VariantPlanner]
  Producer["bulk producer<br/>(AS, priority 10)"] --> Plan
  Plan --> Q["queueing CAS<br/>+ process action (priority 20)"]
  Q --> Runner["AS runner<br/>(WP-Cron, async request, system cron, WP-CLI)"]
  Runner --> Proc["ConversionQueue::process"]
  Proc --> Worker[AttachmentProcessor]
  Worker --> Gate{"resource gate<br/>per variant"}
  Gate -->|start| Conv["ImageConverter<br/>+ AtomicImageWriter"]
  Gate -->|skip| Rows[(variant rows)]
  Conv --> Rows
  Worker -->|"finished / continue / defer / parked"| Proc
  Proc -->|"next action"| Q
  Health["job-health check<br/>(admin, Resume, Maintenance)"] -.reads.-> Rows
```

Runtimes that may execute conversions, each with its own capability fingerprint (AD-12):
- PHP-FPM web requests (the AS async runner, admin, REST);
- WP-Cron over HTTP;
- system cron;
- WP-CLI (`wp action-scheduler run`, `wp trust-optimize sync --now`).

The operational envelope assumes:
- shared hosts and containers with memory limits below what AVIF encoding of large originals needs;
- sites with `DISABLE_WP_CRON` and no system cron;
- an AS queue shared with WooCommerce and other plugins.

```text
includes/
  core/           # composition root, requirements
  domain/ value/  # status constants, result objects
  database/       # schema, DB_VERSION, migrations
  storage/        # attachment and variant repositories (CAS, parked set, deferral data)
  bulk/           # jobs, producer, eligibility, progress, liveness, job-health check (#34, #30)
  queue/          # conversion queue (next-action scheduling), maintenance, lifecycle
  planning/       # variant planner, image limits
  processing/     # per-attachment worker loop, resource gate and pace (#26)
  features/optimization/  # encoder call
  files/ naming/  # atomic writes, ownership, variant names
  service/        # cleanup
  capabilities/   # format capability per runtime, probe (#21, #22)
  admin/ api/ cli/ health/ frontend/  # entry points
uninstall.php     # per-site uninstall over all sites
```

Where the diagnostics writer (AD-18) lives is decided in the #26 spec.

## Deferred

- **Pace algorithm, signals, intervals and bounds; memory coefficients per format and encoder.** Calibration inside AD-11, owned by #26 (pace) and #32 (coefficients).
- **Mechanism of the one-at-a-time slot** (lease or lock and its expiry). Owned by the #26 spec; the rule is fixed in AD-11.
- **Where deferral data lives (attachment or variant), its columns, and the deferral limit.** Owned by the #26 spec; AD-8 and AD-11 fix what must be visible and through what.
- **A confirmed AS block as grounds to refuse Resume.** The documented AS API cannot show one, so the queue signals of AD-9 stay diagnostic. Revisit with the AS upgrade (#1), or when AS exposes its runner state.
- **One-at-a-time slot vs attempt record.** The #26 spec says whether the slot of AD-11 reuses the attempt record of AD-10 or sits beside it; it never replaces the late-worker conditions. It also decides:
  - whether the slot stays blocked after recovering a live encode (AD-10 accepts that the process may still run);
  - that slot and pace deferrals count as liveness (AD-8) only while the slot holder itself shows attempt evidence, so a holder that is never recovered automatically (sync, legacy, lease) cannot keep a job alive through deferrals.
- **"Pausing" status while a running conversion finishes, and Cancel of queued attachments.** Owned by #35; Pause behavior is fixed in AD-7.
- **Location and file mechanism of diagnostics.** Owned by the #26 spec, within AD-18.
- **Site traversal order and limits on large networks.** Activation and deactivation in #28, uninstall in #29, within AD-15.
- **Action Scheduler upgrade to 4.x.** Owned by #1; AD-4 and AD-5 hold on 3.9.3 and 4.x.
- **Overdue-tasks notice per environment, and readme guidance on runners and CLI vs web.** Owned by #25 (notice) and #22 (readme).
- **Per-image settings.** Owned by #40, within AD-13 and AD-14.
- **AVIF encoder speed and AVIF-vs-WebP preference.** Owned by #27, after the measurements in #32.
- **Showing an occupied target as `skipped`, and when planning retries it.** AD-13 records `failed` / `target_exists_foreign`. The file is kept and the attempt ends normally. Changing that outcome to `skipped`, together with how the result is shown and the retry rules, is a later change. It is not part of #19. When it lands, that writer uses its own reason and joins the AD-8 exclusions in the same change (AD-3).
- **Dimensions of an intermediate size file.** #10 applies the pixel and memory checks to the attached file only. A size file whose own width and height are missing is still decoded when the attached file passes. Checking each file is the per-variant gate of AD-11, owned by #26 and #32. #10 does not add it.
- **Positive metadata that is smaller than the file header.** #10 trusts two positive metadata dimensions and reads the header only when they are absent. A smaller lie still bypasses both limits. Not part of #10.
- **Pending rows a queue worker already holds.** `AttachmentProcessor::run()` does not plan again. #10 stops a new plan from encoding an attachment whose dimensions cannot be established. It does not reclaim actions scheduled before that plan.
- **A header that understates the decoded bitmap.** The check uses the dimensions the header reports. A decompression bomb is outside it. Memory coefficients stay with #32.
- **`too_large` and `insufficient_memory` remove existing done variants.** AD-14 does not list those reasons as leaving delivery. That deletion is older than #10 and stays. `unknown_dimensions` keeps a done row and its file only while the existing source-size check says the source is unchanged and the format is still enabled. #10 adds no new way to detect a source change. Bringing the older skip reasons into line with AD-14 is a later change.
