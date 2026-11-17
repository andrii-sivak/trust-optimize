# Adversarial review: spine amendment for #30 (AD-7, AD-9, AD-10, Deferred)

- Reviewed: working-tree diff of `ARCHITECTURE-SPINE.md` against `62676d3`
- Code read: `includes/storage/AttachmentRepository.php`, `includes/queue/{Maintenance,ConversionQueue,Lifecycle}.php`, `includes/processing/AttachmentProcessor.php`, `includes/features/optimization/ImageConverter.php`, `includes/planning/VariantPlanner.php`, `includes/bulk/{BulkProducer,BulkJobRepository,JobProgress}.php`, `includes/service/ImageCleanupService.php`, and Action Scheduler 3.9.3 (`QueueRunner`, `Abstract_QueueRunner`, `QueueCleaner`, `FatalErrorMonitor`, `DBStore::get_claim_count`, `functions.php`)
- Method: for each hole, two units one level down that each follow every AD as written and still clash. Each hole names the code that shows it and proposes the wording that closes it.

## Verdict

**Not ready to bind.** The amendment fixes the right things: Resume no longer depends on guesses, the queue signals are diagnostics only, and death is no longer taken by age alone. But AD-10's "confirmed death" depends on two facts the rules do not provide:

- **Who started the attempt.** The spine defers this, yet the absence test depends on it. Inside an AS worker the test is never true, because the worker's own action is in progress.
- **Whether AS still shows a live worker.** In AS 3.9.3 it does not: the runner marks an in-progress action failed after its own failure period, 300 s by default.

The poison-file rule is also enforced in the wrong place. The current planner and `mark_queued` let a `failed`/`max_attempts` attachment back in, and a leftover `processing` variant turns it back into a phantom claim.

Holes H1–H4 are blocking.

---

## H1: Confirmed death cannot be decided as written; `claim()` can never recover [HIGH]

**Unit A, `claim()` takeover inside an AS worker.** AD-10 lists `claim()` as a recovery path, but only "when AS has no `pending` or `in-progress` action for that attachment". `claim()` runs inside `ConversionQueue::process()`, which AS runs as action `trust_optimize_process_attachment {attachment_id: N}`. In AS 3.9.3, `process_action()` sets that action to `in-progress` (`log_execution`) before calling the callback. So during every call to `claim()`, AS has an in-progress action for N: the caller's own. A literal implementation therefore never takes over. An implementation that excludes its own action needs that action's ID. The only way to get it is an AS hook (`action_scheduler_begin_execute`), and AD-5 allows only "`as_*` functions and the store status constants". The two rules cannot both be met.

**Unit B, Maintenance or job-health check.** "The absence test applies only where it is known that AS started the attempt." No column records who started an attempt. `claim()` writes only `state`, `attempts` and `updated_at` (`AttachmentRepository.php:248`), and the Deferred entry gives "how an attempt is identified" to the #30 spec. Until that spec exists, nothing is ever known to be AS-started. A literal Unit B never recovers anything. A pragmatic Unit B assumes AS and recovers synchronous attempts too, such as a REST sync running `run( $id, INF )` (`AttachmentProcessor.php:134`) for more than 900 s, while that attempt is still alive. That starts a second worker on the same file.

**Why this is a spine hole, not a spec detail.** Three ADs depend on the attempt's identity: the confirmed-death test, the CAS that "matches the dead attempt itself", and the late-worker rule. A spec cannot settle it without either breaking AD-5 or making AD-10's `claim()` path dead code.

**Fix (tighten AD-10, shrink the Deferred entry):**

> - **Attempt record.** `claim()` records the attempt it starts on the attachment row: who started it (`as` or `sync`) and when. The record is the attempt's identity; the death CAS, the late-worker checks and the absence test read it. Its columns are a schema change under AD-17.
> - **One recovery owner.** Only step 1 of the job-health check (AD-9) moves a dead attempt out of `processing`. `claim()` and `mark_queued()` never take over a `processing` attachment, by age or otherwise; they return busy.
> - The absence test applies to attempts recorded as `as`. An attempt recorded as `sync` is recovered as the #30 spec decides; until then it is never taken over.

Also remove "and `mark_queued` or `claim()` when they take over a confirmed dead attempt" from the list of recovery paths. With one recovery owner, the two-owner and double-count races between `claim()` and Maintenance go away.

Deferred then shrinks to: "How a dead `sync` attempt is recovered. Owned by the #30 spec."

---

## H2: AS fails live workers by itself, so "no in-progress action" does not prove death; the late worker then corrupts ownership [HIGH]

**AS fact.** `ActionScheduler_Abstract_QueueRunner::run_cleanup()` calls `QueueCleaner::clean( 10 * get_time_limit() )`, which is 300 s by default. `mark_failures()` then marks failed every `in-progress` action not modified for that long. The time limit and the failure period are both filterable by any plugin (`action_scheduler_queue_runner_time_limit`, `action_scheduler_failure_period`). AD-5 forbids the plugin from changing either.

**Scenario.**

1. A WP-CLI runner (`wp action-scheduler run`, no PHP time limit) runs worker W1 on a large AVIF. One `convert()` call can run well past the 20 s budget, which is checked only *between* variants (`AttachmentProcessor.php:224`).
2. Web WP-Cron runs `run_cleanup` at 300 s, and W1's action becomes `failed` while W1 is still alive.
3. At 900 s since the claim, AD-10's two conditions hold. The death CAS sends the attachment back to `queued`, and a new action starts W2. This is exactly the "live long conversion taken over by age" that AD-10 says it prevents.

**Unit A: `AttachmentProcessor::run()` of W2.** Lines 200–205 reset every `processing` variant to `pending` ("a row stuck in processing belongs to a crashed run"). That includes the variant W1 is encoding right now. W2 then converts it again.

**Unit B: `ImageConverter::convert()` of W1.** Its publishing CAS is `transition( id, PROCESSING, DONE, … file_hash )` and is keyed only on status. W1 finishes first and its CAS hits, because the row is `processing`, now owned by W2. The row records W1's hash. W2 then renames its own temp file over the target (`AtomicImageWriter.php:89`), and its own CAS misses. The file on disk no longer matches the row's `file_hash`, so AD-13 ownership is lost. The next re-optimization marks the variant `target_exists_foreign`, and cleanup refuses to delete the file for good.

AD-10's late-worker rule does not stop this, because it covers only "closing writes". The variant transitions happen mid-run and are not closing writes.

**Second effect.** Each slow-but-healthy attempt is counted as a death, so a file that only needs more than 900 s per run ends as `failed`/`max_attempts`.

**Fix (AD-10):**

> - **Late worker.** Every write an attempt makes is conditional on still holding that attempt: variant transitions, file publication (rename) and the closing writes. A variant row in `processing` belongs to the attempt that set it. A new attempt never resets it; only the death CAS does.
> - **What "confirmed" means.** AS marks an in-progress action failed after its own failure period, which the plugin does not control (AD-5). The absence of an action therefore confirms death only when no single run of the worker can last longer than `STALE_CLAIM_SECONDS`. The #30 spec bounds a run, budget plus one encoder call, or else uses a liveness test that ends with the process.

Option for the #30 spec: a per-attempt `GET_LOCK()` held on the worker's own DB connection. MySQL releases it when the process dies, so `IS_FREE_LOCK()` confirms death without a liveness write, which AD-8 still forbids. It does not work behind connection poolers or with persistent connections; record that as a constraint.

If neither option is taken, change the Prevents line "a live long conversion taken over by age" to "… taken over while AS still shows it running", and accept the duplicate-encode risk explicitly.

---

## H3: Poison files re-enter the queue through four paths, and a phantom death is reported again [HIGH]

AD-10 says a `failed`/`max_attempts` attachment "is no longer queued by normal planning: bulk, metadata regeneration, Maintenance". Each unit below follows the ADs and still re-queues it.

1. **Planner plus `mark_queued`.** `VariantPlanner::plan()` checks the poison count only through `reconcile( …, $retry )` (line 97), and that gates only `FAILED` *variant* rows. Death recovery fails the *attachment*. Its variants remain `pending`, plus the one the worker died on, which remains `processing`. The planner returns pending rows, so `has_work()` is true. `ConversionQueue::enqueue()` calls `mark_queued()`, whose WHERE clause `state NOT IN (queued, processing)` accepts `failed`. The attachment is re-queued, claimed (`attempts` = 4) and kills another worker. This happens on every new bulk sync (`BulkProducer::hand_over`) and every metadata regeneration (`handle_new_metadata`).

2. **Phantom claim from a leftover `processing` variant.** If the only variant left is the one the worker died on, `$pending` is empty and the planner calls `recompute()` (line 130). `derive_state()` returns `PROCESSING` whenever any variant is `processing` (line 92). `write_state()` then overwrites `failed`/`max_attempts` with `processing`, sets `updated_at` to now and clears `reason`. The result is a claim with no worker and no AS action:
   - `JobProgress::in_flight()` counts it, so `finish_or_wait()` keeps the job open;
   - 900 s later step 1 "confirms a death" and the CAS rewrites the job's `last_error` with a death that never happened.

   This is double counting through the data, not through `attempts`.

3. **Lifecycle.** `suspend_unfinished()` moves every `queued`/`processing` row to `none`/`deactivated`, including a dead attempt at `attempts >= 3` that is not yet confirmed. `restore()` then calls `enqueue()`, `mark_queued()` accepts `none`, and the file is re-queued. This leaves `processing` without the one death CAS, and Lifecycle restore is missing from AD-10's poison list.

4. **Explicit sync loses the count.** `AttachmentProcessor::sync()` calls `reset_attempts()` (line 113) *before* `claim()`. When the claim then fails (`busy`, because a dead attempt still has its in-progress action), the count is already 0. When the death is later confirmed, the CAS sees `attempts < MAX` and requeues instead of failing, which allows three more worker kills.

**Fix (AD-10 and AD-3):**

> - **Poison file.** The poison guard lives in the queueing CAS itself: `mark_queued`, `requeue` and the Lifecycle restore never queue an attachment that is `failed`/`max_attempts` or whose `attempts >= MAX_ATTEMPTS`. Only an explicit sync passes it, by resetting the count inside its own claim CAS, so a sync that does not get the claim changes nothing.
> - **The death CAS moves the attempt's variants too.** The attempt's `processing` variant rows go to `pending` (requeue or park) or to `failed` with reason `max_attempts` (poison), in the same transaction.
> - **`processing` is a claim, never a derivation.** `recompute()` never writes `processing`. A variant in `processing` without a live attempt is the death CAS's business.
> - **Deactivation** leaves `processing` through the death CAS rules: it keeps `attempts`, and the restore applies the poison guard.

---

## H4: The death CAS chooses between "parked" and "queued" by reading the job's status, and loses work during Resume [MEDIUM-HIGH]

**Unit A, death CAS (Maintenance, an AS action).** It reads job J as `paused` and parks attachment N: `none`/`paused`, keeping its `job_id`.

**Unit B, Resume** (admin, REST or WP-CLI). AD-7 fixes "the order of the status write and the queueing" in the spec. Whichever order the spec picks, there is an interleaving where:

1. Unit A reads `paused`;
2. Resume writes `running` and queues the parked set (N is not parked yet);
3. Unit A parks N.

Now a running job has a parked attachment, and nothing re-queues it. The gate parks only while the job is paused, and Lifecycle restore handles only `deactivated`. `JobProgress::in_flight()` counts only `queued` and `processing`, so `BulkProducer::finish_or_wait()` completes the job while N is still unconverted. Nothing reports it.

AD-7's promise that every unfinished attachment stays reachable after Resume covers the queueing calls of Resume, not parkers that run concurrently. AD-10 has just added a new parker.

**Fix (AD-7):**

> - A job never completes while its parked set is non-empty. The producer of a running job queues its parked set before it decides to finish. Every parker (gate, deactivation, death CAS) is covered by this, whatever the order of Resume's writes.

An alternative is for the death CAS to decide parked or queued in the same SQL statement, through a join on the job's status. The sweep is simpler and also covers the gate.

---

## H5: The job's `last_error` has two owners, an undefined order and a side effect on liveness [MEDIUM]

1. **Two owners of the job row.** AD-10 says "the CAS … replaces the job's `last_error`", and that CAS is an `AttachmentRepository` CAS on the attachments table. AD-3 says every job status change is a CAS in a repository, and the jobs table belongs to `BulkJobRepository`. One unit puts the jobs-table write inside `AttachmentRepository` (two owners of the job row). Another makes it a second, non-atomic `BulkJobRepository` call after the attachment CAS, so a crash between the two loses the notice text.

2. **"Running job" only.** The step 3 pause (`stopped`) can come *before* the death is confirmed: on a no-cron site the dead action stays `in-progress`, see H6. When the death is confirmed later, the job is `paused`, so `last_error` is not written. The AD-9 notice keeps saying "cause unknown" although the cause is now known. The same happens for a death confirmed after a `user` or `low_disk_space` pause.

3. **Resume order.** Resume runs step 1, which may write `last_error`, and Resume also clears `last_error`. The ADs do not order the two:
   - clear first, then step 1: a resumed job carries a stale error;
   - step 1 first, then clear: the death is never shown.

4. **Recovery that counts as progress.**
   - Interim test (AD-9 step 2): the job's `updated_at`. `BulkJobRepository::update()` and `transition()` both set `updated_at = now`. A death write through either one refreshes the staleness clock in the same pass that should judge it, because step 1 runs before step 2. The job is never paused as stopped while deaths keep arriving.
   - AD-8 target: "the latest change among the job's attachments". The death CAS sets the attachment's `updated_at`, which looks like progress.

   With either test, the recovery path works as a heartbeat. AD-8 forbids liveness writes on the conversion path but says nothing about recovery.

5. **Data shape.** "Attachment ID and death count" is written into a free-text column (the conventions say human text goes to `last_error`). The notice needs the ID for a link and the count for wording, so the notice would have to parse the text. Also unclear: is the death count the attachment's `attempts`, or the job's total deaths?

**Fix (AD-10 Job error, AD-7, AD-8):**

> - **Job error.** In the same transaction as the death CAS, `BulkJobRepository` records on the attachment's job, if the job is unfinished (running or paused): the attachment ID and its `attempts`. These are stored as data, not inside human text; the notice builds the sentence. The write does not change the job's `updated_at`.
> - **Resume** first runs step 1, then clears `last_error` in the CAS that writes the status.
> - **AD-8:** a change made by recovery (the death CAS, requeue) is not progress. Liveness reads only changes made by a worker or the producer.

---

## H6: No cron and SIGKILL: recovery never runs and the escape hatch is closed [MEDIUM]

The spine's own operational envelope combines containers with memory limits below what AVIF needs with sites that have `DISABLE_WP_CRON` and no system cron. A cgroup OOM kill is a SIGKILL, so no shutdown function runs: `FatalErrorMonitor::handle_unexpected_shutdown` never marks the action failed, and the action keeps its claim.

`DBStore::get_claim_count()` counts that claim. With the default concurrency of 1, `has_maximum_concurrent_batches()` is true, so:

- `AsyncRequest_QueueRunner::allow()` refuses, and `run()` together with `run_cleanup()`/`mark_failures()` never runs;
- the action stays `in-progress` for ever;
- AD-10's death is never confirmed, and Maintenance, itself an AS action, never runs.

AD-5 accepts that the queue stays blocked. The amendment adds two things on top:

- Under H1's literal rule, the user's escape hatch, an explicit sync, cannot take over while an in-progress action exists. `sync()` returns a generic `busy` with no explanation.
- The AD-9 notice shows `last_error` "written by death recovery", which never ran, so it says "cause unknown". Meanwhile the stuck-claim signal and the attachment that holds it are both known.

**Fix (AD-9 notice, AD-11 synchronous paths):**

> - When the stuck-claim signal is present, the notice names the attachments held in `processing` by an action that has not finished, and says what releases them: WP-Cron, system cron or `wp action-scheduler run`.
> - A sync refused because an AS action still holds the attachment says so, rather than a generic "busy".

Add to Deferred: "Whether an administrator may force-recover an attempt held by an in-progress action on a site without a runner. Owned by #30, within AD-5."

---

## H7: The ADs bypass their own "one CAS" rule [MEDIUM]

AD-3 allows unconditional writes "by the current claim holder, by the planner, or by the producer for its own job". Under those exemptions, these writes move an attachment that another worker holds out of `processing`, which AD-10 reserves for the one death CAS:

- `BulkProducer::hand_over()` catch: `set_state( FAILED, 'exception' )` on an attachment an upload worker holds right now. The producer is acting "for its own job", so AD-3 allows it. `mark_queued()` then accepts `failed`, and the late-worker rule cannot tell this write from a recovery.
- `VariantPlanner::plan()`: `recompute()` and `set_state()` during another worker's run (metadata regeneration), which is the planner exemption.
- `ImageCleanupService` line 100: `recompute()` after a per-image removal.
- `ConversionQueue::process()` catch: `set_state( FAILED )`, the late worker's exception path. Its write is unconditional today.

**Fix (AD-3):**

> - Unconditional writes never change the state of an attachment in `processing` unless the writer holds that attempt. Others use a CAS that excludes `processing`.

---

## H8: Smaller seams [LOW]

- **AD-4 vs the death CAS: who schedules.** AD-4 names `mark_queued` and `requeue` as the queueing CASes, and says only `process()` schedules a *next* action. The death CAS's `queued` outcome is neither. One implementer schedules from the health check; another leaves the attachment `queued` with no action until Maintenance's queued-without-action sweep. Fix: list the death CAS among AD-4's queueing CASes; its caller schedules through `ConversionQueue`.
- **`mark_queued()` takes over by queued age.** It retakes a `queued` row older than one day (`STALE_QUEUED_SECONDS`) even when its pending action still exists. After a long runner outage, an upload or regeneration adds a second action for the same attachment, which AD-4 forbids. AD-10's "nothing takes the attachment over by age while such an action exists" should say it covers `queued` as well as `processing`.
- **False "no progress" signal.** On traffic-driven WP-Cron, the first admin load after more than 15 min without traffic always sees due pending actions and nothing finished within the window, and shows the queue-blocked notice that the same request's cron then clears. Fix: "no progress" also requires that a runner had a chance in the window, for example that the first due action is older than threshold + WP-Cron's interval, or that the signal persisted over two checks.
- **Old schema.** On a subsite whose jobs-table migration has not run (`is_current()` false), the pause reason still lives in `last_error` (`pause_data`, `recover_stale_running` appends). A death write that "replaces, never appends" erases it. Fix: the job-error write is skipped until the site's schema is current (AD-17).

---

## Summary of proposed changes

| Hole | Severity | Change |
| --- | --- | --- |
| H1 | High | AD-10: attempt record (`as`/`sync`, claimed-at) written by `claim()`; one recovery owner (health-check step 1); `claim()`/`mark_queued()` never take over `processing`; shrink the Deferred entry |
| H2 | High | AD-10: every write of an attempt (variant transitions, rename, closing writes) is conditional on holding it; new attempts never reset `processing` variants; bound a run below `STALE_CLAIM_SECONDS` or use a process-bound lock; else weaken the Prevents line |
| H3 | High | AD-10/AD-3: poison guard inside the queueing CAS and the restore; death CAS also moves the attempt's variants; `recompute()` never writes `processing`; sync resets the count inside its own claim |
| H4 | Med-High | AD-7: no job completes while its parked set is non-empty; the running producer queues its parked set |
| H5 | Medium | AD-10/AD-7/AD-8: job-error data written by `BulkJobRepository` in the death transaction for unfinished jobs, without touching `updated_at`; Resume runs step 1, then clears in its status CAS; recovery is not progress |
| H6 | Medium | AD-9: the stuck-claim notice names the held attachments; sync says when an AS action holds the attachment; Deferred: forced recovery on sites without a runner |
| H7 | Medium | AD-3: no unconditional write to an attachment in `processing` except by its attempt |
| H8 | Low | AD-4: list the death CAS among the queueing CASes; the age rule also covers `queued`; debounce "no progress"; no job-error write on an old schema |
