# Adversarial review: spine amendment for #34 (AD-5, AD-8, AD-9, AD-10)

- **Reviewed:** `ARCHITECTURE-SPINE.md` working-tree diff on `fix/gh-34-progress-liveness` (2026-11-18), the last six `.memlog.md` decisions, and the draft `_bmad-output/implementation-artifacts/spec-gh-34-progress-liveness.md`.
- **Code read at:** 61e8212. Files: `bulk/JobHealth.php`, `bulk/BulkJobRepository.php`, `bulk/BulkProducer.php`, `bulk/JobProgress.php`, `storage/AttachmentRepository.php`, `storage/VariantRepository.php`, `planning/VariantPlanner.php`, `queue/ConversionQueue.php`, `queue/Maintenance.php`, `queue/Lifecycle.php`, `processing/AttachmentProcessor.php`, `features/optimization/ImageConverter.php`, `service/ImageCleanupService.php`, `cli/Command.php`.
- **Method:** for each hole, two units one level down that each follow every AD as written, and the way they still build incompatibly.

## Verdict

The amendment fixes the #34 symptom, but it does not yet make liveness a property of conversions. The producer's `wait()` still counts as a producer step. Since AD-5 now runs the producer before every conversion, that step keeps any job alive whenever AS runs at all. Several in-flight states that no automatic path can leave then keep a job running forever, and it never completes. The new attempt-evidence rule is correct in direction, but it depends on a writer invariant the spine never states. It also has edges that do not match: the threshold vs the recovery wait, sync attempts, jobs without attachments, and the poison predicate.

| # | Severity | Hole |
|---|---|---|
| H1 | critical | The producer's `wait()` is unconditional liveness: a job never stops and never completes |
| H2 | high | Slot and pace deferrals (#26) keep a job alive forever behind an attempt that AD-10 never recovers |
| H3 | high | AD-10 recovers a live encode, while AD-11 promises "at most one conversion runs" and plans memory on that |
| H4 | high | The poison predicate can be read two ways, and `recompute()` erases the reason, leaving an orphan queued or parked row |
| H5 | high | Attempt evidence relies on an unstated rule about who writes variant statuses; the #34 spec draft still reads stored state |
| H6 | medium | Threshold T < 900 s: the job is stopped while AD-10 still treats its attempt as possibly alive |
| H7 | medium | "Progress since Resume" from attempt evidence: sync attempts end the skip, and jobs without attachments never end it |
| H8 | medium | A library-wide `sync --now` counts as an "explicit sync" and resets every poisoned file |
| H9 | medium | One filter for two waits: an upgrade hazard, and `release()` still at 900 s |
| H10 | low | The AD-9 "no progress" signal and AD-8 use two notions of progress |
| H11 | low | Claim evidence can be read only while the attachment is `processing` |

---

## H1 — The producer's `wait()` is unconditional liveness: a job never stops and never completes [critical]

**Units.**
- **A: producer (AD-5 Backpressure, AD-6).** Waits on the backlog and on in-flight work.
- **B: liveness function (AD-8).** It counts "the producer's own steps (cursor, **wait**)".

**Incompatibility.** Both units follow the letter of their ADs. The producer waits in two situations:
- at the start of a run, when the backlog is over the limit;
- at the end of the cursor, when `in_flight > 0`.

Each wait is a `touch()` of the job row: `BulkJobRepository::touch()`, whose docblock reads "Record that the job is alive". AD-8 counts that touch as a producer step. AD-5 schedules the producer at priority 10, ahead of every conversion, so whenever any AS runner runs, the producer runs every 30 s and the job is alive. AD-8 then never needs its attempt evidence. In practice the liveness test is still "did the producer run", which is the old job-row test, now with a 30 s heartbeat.

That heartbeat is a liveness write. AD-8's "No liveness writes" covers only the conversion path, so the spine allows it. It also gets around "the mere existence of an AS action is not alive": the producer's own action writes the liveness.

**Never completes, never stops.** `JobProgress::in_flight()` = queued + processing + parked (`JobProgress.php:67-68, 131-141`). `finish_or_wait()` waits as long as it is > 0. Each of these in-flight states can last forever while AS runs normally:
- **A sync or legacy attempt left in `processing`.** AD-10: "Never by age alone"; release is WP-CLI only. Example: a REST or WP-CLI sync of a job attachment dies.
- **A lease-held attempt whose process died during publication.** `find_death_candidates` requires `publishing_at IS NULL`.
- **A poisoned row left queued or parked** (H4).
- **Conversions starved behind other plugins' priority-10 actions.** The producer, also at priority 10, still runs. AD-8 says "waiting behind higher-priority actions" makes a job neither stale nor alive, but through the producer's wait it does make it alive.

**Result.** The job stays `running` indefinitely and never reaches step 3 or `complete()`. The only signal is the held-attempt notice, and only for the first two cases. The poisoned-row and starvation cases produce no notice at all, because no queue signal fires: producer actions complete every 30 s.

**Backlog counter, same pair.** `has_backlog()` counts every pending `trust_optimize_process_attachment` action of the group, whatever its date and source (`BulkProducer::has_backlog`, `status = pending`, no `date` bound). That includes:
- uploads;
- schema-pending retries scheduled one hour ahead;
- under #26, deferrals scheduled in the future.

So the backlog wait, and the liveness that comes with it, can be produced by actions that cannot run yet.

**Evidence.**
- `BulkProducer::produce()` → `has_backlog()` → `wait()` → `touch()`.
- `BulkProducer::finish_or_wait()`.
- `JobProgress::in_flight()`.
- AD-8 "producer's own steps (cursor, wait)".
- Spec I/O matrix row "Producer waits … alive".

**Proposed AD-8 wording.**
> **Producer steps.** A producer step is a cursor move or a hand-over to the queue. A `wait()` is not a step: while the producer waits, the job is alive only through attempt evidence and pending deferrals. One exception: the backlog wait counts as alive while at least one conversion of the group (any source) produced attempt evidence within the threshold. That is the "waiting behind its own backlog" case, and it stays bounded by real conversions. The backlog counts only due `pending` conversions (`date <= now`).
>
> **In-flight that cannot move.** An attachment that no automatic path can move (an attempt AD-10 never recovers automatically, a row refused by the poison guard) does not keep the job waiting forever. After the threshold the job is stopped with `stopped`, and `last_error` names the attachment and why it is held. Completion ignores such rows only if the owner prefers "complete with errors" to a stop.

The second paragraph is a public-behavior choice. It goes to the owner.

---

## H2 — Slot and pace deferrals (#26) keep a job alive forever behind an attempt that AD-10 never recovers [high]

**Units.**
- **A: #26 gate (AD-11).** "Waiting for the slot or for the pace is not a resource deferral and never uses up that limit."
- **B: #34 liveness (AD-8).** A "pending deferral … whose next attempt is still ahead" keeps the job alive.

**Incompatibility.** Whether the slot reuses the AD-10 attempt record is left to the #26 spec (Deferred). If #26 lets any `processing` attempt hold the slot, including a lease-held one, a dead holder that AD-10 never recovers automatically holds it forever. Every other background conversion on the site then:
1. claims (attempts +1);
2. meets the gate's "slot busy";
3. defers (attempts reset, back to `queued`, a new future action at priority 20).

That repeats with no limit. Each round leaves a pending deferral with a next attempt ahead, so AD-8 keeps the job alive forever. Every deferral action also ends `complete` in AS, so the AD-9 "no progress" signal ("no conversion action reached `complete`") never fires (H10).

**Result.** A site-wide conversion stall with no stop, no completion and no queue warning. The only visible clue is the held-attempt notice, and only if the holder is classified as held.

**Evidence.** AD-11 "One at a time" and "Defer"; AD-8 inputs and Threshold; AD-10 "Never by age alone"; `AttachmentRepository::find_death_candidates` (`publishing_at IS NULL`); `JobHealth::queue_signals()` counts any `complete` conversion action.

**Proposed wording.**
- **AD-11 One at a time:**
  > Only a background attempt that AD-10 may recover automatically holds the slot. An attempt that AD-10 never recovers automatically (sync, legacy, lease-held past the recovery wait) never blocks the background slot.
- **AD-8 Deferrals:**
  > A slot or pace deferral is transparent: it keeps a job alive only while the slot holder itself has attempt evidence within the threshold. A resource deferral keeps it alive until its next attempt time.

---

## H3 — AD-10 recovers a live encode, while AD-11 promises "at most one conversion runs" and plans memory on that [high]

**Units.**
- **A: AD-10 recovery as amended for #34.** "An encode longer than the recovery wait can be recovered while its process lives."
- **B: #26 slot and memory gate (AD-11).** "At most one background TrustOptimize conversion runs per site"; memory is checked "against container headroom".

**Incompatibility.**
1. AS fails the in-progress action after 300 s.
2. After W = max(900, T) the death CAS clears the attempt record and requeues the attachment at priority 20.
3. If the slot is the attempt record, or is released with it, the slot is free, and the next background conversion takes it. That is often the same heavy file: its count is below 3, and it was requeued at priority 20.
4. The zombie encode is still running. Two to three encodes of the same decompression bomb can run at once, one per death until `MAX_ATTEMPTS`.

AD-11's memory gate counts headroom as if one conversion runs, so it lets each new attempt in. That is the OOM pattern of the original nono-mimi host. Each unit obeys its AD; together they break AD-11's central guarantee.

**Evidence.** AD-10 "Slow encode"; AD-11 "One at a time" and "Inputs"; `JobHealth::recover_attempts()` → `recover_dead()` → `schedule_recovered()`; AS failing in-progress actions after 300 s (AD-10 note).

**Proposed wording.**
- **AD-11:**
  > The one-at-a-time guarantee is per held attempt, not per process. After the death CAS recovers an attempt, the slot stays blocked for one more recovery wait (or until AS reports no `in-progress` action of the group older than the wait). The memory gate treats a recently recovered attempt's file as still resident during that window.
- **AD-10** must also say that a recovered attachment is not rescheduled into the slot ahead of others.

Alternatively, the owner accepts the overlap explicitly in AD-11 ("at most one, except after a recovery of a live encode").

---

## H4 — The poison predicate can be read two ways, and `recompute()` erases the reason [high]

**Units.**
- **A: a queueing CAS written to AD-10's letter.** For example #35 "Cancel queues parked work", a #26 release of a deferral, or a new restore path. AD-10: queueing CASes refuse "an attachment that is `failed` / `max_attempts` with `attempts >= MAX_ATTEMPTS`" (state + reason + count).
- **B: the #34 claim guard.** "A queue `claim()` refuses an attachment with `attempts >= MAX_ATTEMPTS`" (count only).

**Incompatibility.** `recompute()` → `write_state()` rewrites the state with `reason = NULL` and keeps `attempts`. A poisoned attachment with some `done` variants becomes `partial`, attempts 3 (`derive_state`: failed > 0 and done > 0 → PARTIAL). One without them becomes `failed` with reason NULL. `recompute()` runs from:
- `ConversionQueue::plan_and_enqueue` when the plan has no work: any metadata regeneration;
- `ImageCleanupService::cleanup_attachment`.

From there:
1. Unit A's state predicate admits the row and moves it to `queued`.
2. Unit B's claim refuses it, and `process()` follows its BUSY path: it parks the row when the job is paused, otherwise it runs once more, is refused again, and schedules nothing.
3. The row now sits `queued` (or parked, `none/paused`) with attempts 3. Every repair skips it:
   - `find_stuck` and `requeue` filter `attempts < MAX`;
   - `mark_queued` refuses it, so the producer's `queue_parked()` and Resume never move it.
4. `in_flight()` and `count_parked()` still count it. Through H1 the job never completes. AD-7 says "a job never completes while its parked set is not empty", so a paused job never completes either.

The same rewrite breaks today's `has_progress_since_resume()`. It counts `failed AND (reason IS NULL OR reason <> 'max_attempts')`, so a regeneration of a poisoned file after Resume counts as progress and ends the one-stop skip.

**Evidence.**
- `AttachmentRepository::write_state`, `recompute`, `mark_queued`, `requeue`, `find_stuck`, `park_where`, `find_parked`.
- `ConversionQueue::process` BUSY branch.
- `VariantPlanner::plan` (`$retry` keyed on the count).
- `BulkJobRepository::has_progress_since_resume`.

**Proposed AD-10 wording.**
> **Poison is the count.** An attachment is poisoned when `attempts >= MAX_ATTEMPTS`, whatever its stored state or reason. One repository predicate defines it, and every queueing CAS, the queue claim, the parking CAS and the parked set use that predicate. A queue claim refused for poison moves a `queued` or parked row by CAS to `failed` / `max_attempts`, so it leaves the in-flight and parked sets. `recompute()` and `set_state()` keep reason `max_attempts` while the row is poisoned.

---

## H5 — Attempt evidence relies on an unstated rule about who writes variant statuses [high]

**Units.**
- **A: the liveness function (#34).** It reads "a worker's variant transition" from `trust_optimize_variants.status` + `updated_at`. Nothing on the row records who wrote it.
- **B: any later writer of variant rows.** AD-3 allows unconditional `VariantRepository::upsert` by the planner and the producer, and `upsert` writes `status` (it is in `DEFAULTS`). Candidates:
  - #19 deciding `skipped` / `target_exists_foreign` at planning time (AD-13 "Occupied target");
  - #40 per-image settings;
  - a future adoption path.

**Incompatibility.** The memlog decision ("variants are written only by planner upserts (always pending), worker transitions, the death CAS and cleanup") is true of the code today, but it is not a rule in the spine. A planner-side `skipped` or `done` written to AD-3's letter is evidence by AD-8's letter. A metadata regeneration of the whole library would then look like conversion progress, which is exactly the failure the amendment was written to remove. The exclusion "failed other than `max_attempts` and `delete_failed`" is a list of reasons, not a rule about writers, so a new non-attempt reason silently becomes evidence.

**Same hole, today: spec vs spine.**
- The draft #34 spec defines "an attempt that ended" as attachment state `optimized` / `partial` / `skipped` / `failed ≠ max_attempts` (the stored state AD-8 now forbids).
- It keeps `has_progress_since_resume()` "unchanged" (also stored state), although AD-8 now says "progress since Resume" uses attempt evidence.
- AD-8's own Threshold bullet still says "no new change among the job's attachments", which can be read as `attachments.updated_at`.

**Evidence.** `VariantRepository::upsert` (writes `status` from `$data`); `VariantPlanner::plan` lines 115-131; `ImageCleanupService::remove` (`delete_failed`); `AttachmentRepository::end_attempt` (writes `v.updated_at`); spec "Liveness function" bullets; AD-8 "Threshold".

**Proposed wording.**
- **AD-3 (new bullet):**
  > Variant statuses `processing`, `done`, `skipped`, and `failed` with any reason except `max_attempts` and `delete_failed`, are written only by a token-guarded transition of the attempt that holds the attachment. `upsert` writes only `pending`. A new reason or status outside an attempt needs an AD-8 amendment.
- **AD-8 Threshold:** "no new attempt evidence, no producer step (as defined), and no pending deferral …".
- **Spec:** must replace both stored-state queries before approval.

---

## H6 — Threshold T < 900 s: the job is stopped while AD-10 still treats its attempt as possibly alive [medium]

**Units.**
- **A: liveness (AD-8 Slow attempt).** "An attempt counts from its claim until the threshold expires, matching the AD-10 recovery wait."
- **B: recovery (AD-10).** Wait W = max(900, T).

**Incompatibility.** "Matching" holds only for T ≥ 900. `stale_after_seconds()` allows 60. With T = 300 and a live 400 s encode, the claim evidence expires at 300 s and the next admin load stops the job (`stopped`, no `last_error`, "cause unknown"). Meanwhile AD-10 refuses to recover that attempt until 900 s, because it may be alive. That is the #34 symptom (a healthy slow job declared stale), reproduced by any site that lowered the filter. The spec matrix row "Low filter" accepts it without saying so.

**Proposed AD-8 wording.**
> An attempt counts from its claim until the AD-10 recovery wait `max( STALE_CLAIM_SECONDS, threshold )` expires.

That is the same expression AD-10 uses, so "no job is stale while one of its attempts may still be alive by AD-10".

---

## H7 — "Progress since Resume" from attempt evidence: two edges [medium]

**Units.**
- **A: AD-8.** "Every consumer that asks whether work happened since a moment … uses this evidence."
- **B: AD-9.** "One stop per stoppage", implemented by `waits_for_the_queue()`.

**Incompatibility, two edges.**
1. **Sync attempts end the skip.** A claim from a REST or WP-CLI sync of a job attachment after Resume is evidence. If that sync dies, the row stays `processing` with `updated_at > resumed_at` forever. AD-10 never recovers it, so "progress since Resume" stays true. The skip ends, and the job is stopped again for the queue stoppage that began before the Resume. A sync attempt says nothing about whether AS runs again.
2. **Jobs without attachments never end it.** Remove and inventory jobs never assign `job_id`, so they can never have attempt evidence. By the letter, "progress since Resume" is always false for them. A new stoppage after Resume then never stops them while their producer action stays scheduled. Today the code uses the cursor for those types, which contradicts the new AD-8 sentence.

**Proposed AD-9 wording.**
> Progress since Resume is attempt evidence from queue attempts only (a `processing` row claimed with source `queue`, or a variant transition of a job attachment that no sync attempt holds), or a cursor move of the producer after the Resume. Sync attempts are never job progress.

---

## H8 — A library-wide `sync --now` counts as an "explicit sync" and resets every poisoned file [medium]

**Units.**
- **A: AD-10 poison guard.** "Only … an explicit sync, inside its own claim" resets the count. The #34 decision repeats that "explicit sync keeps the retry right".
- **B: `wp trust-optimize sync --now`.** Listed among the synchronous paths in AD-11.

**Incompatibility.** `Command::sync_now()` calls `AttachmentProcessor::sync()` for every eligible attachment. Each call claims with `SOURCE_SYNC`, which sets `attempts = 1`. One command therefore re-converts every poisoned file in the library in the CLI process, though no one asked for any of them by name. The first file that really kills PHP:
- leaves a sync attempt in `processing` that AD-10 never recovers;
- aborts the CLI run;
- if the file belongs to a running job, keeps that job in flight forever (H1).

**Proposed AD-10 wording.**
> An explicit sync names one attachment (REST `/image/<id>/sync`, `sync_attachment`, a per-image action). A library-wide synchronous run honors the poison guard and reports poisoned files instead of converting them.

This changes WP-CLI behavior, so it goes to the owner and into `readme.txt`.

---

## H9 — One filter for two waits: an upgrade hazard, and `release()` still at 900 s [medium]

**Units.**
- **A: AD-10 recovery wait** = max(900, `trust_optimize_bulk_stale_after_seconds`).
- **B: existing operator sites and `JobHealth::release()`.**

**Incompatibility.**
- **Upgrade hazard.** The natural workaround for #34 was to raise the stale filter, to an hour or a day. After this release, those sites silently wait that long before recovering a really dead queue attempt. Because of H1, nothing else stops the job meanwhile.
- **`release()` still uses 900 s.** Its too-young refusal keeps `STALE_CLAIM_SECONDS`, so an operator can release a queue attempt aged 900 s–W that automatic recovery protects on purpose. The memlog says the decision "prevents two thresholds for one notion of silence", yet recovery (W), release, held list and signals (900) still use two.

**Proposed wording.**
- **AD-10:**
  > The operator release of a queue attempt refuses ages below the recovery wait. Sync and legacy attempts keep 900 s.
- **Docs:** the CHANGELOG and readme entries name the side effect for sites that raised the filter. Site Health or the status output shows the effective recovery wait.

---

## H10 — The AD-9 "no progress" signal and AD-8 use two notions of progress [low]

**Units.**
- **A: AD-9 signal.** "No conversion action of the group reached `complete`."
- **B: AD-8 attempt evidence.**

**Incompatibility.** Each of these ends as AS `complete` and silences the signal, though none is progress by AD-8:
- an action that parks;
- an action that answers BUSY;
- an action refused by the poison guard;
- a schema retry;
- under #26, a slot or pace deferral.

The signals are diagnostics, so this only hides the warning, which is exactly what H2 needs to stay silent.

**Proposed AD-9 wording.**
> The signals stay AS-level. A running job whose newest attempt evidence is older than the threshold, while it is kept alive only by deferrals or waits, shows the same warning.

---

## H11 — Claim evidence can be read only while the attachment is `processing` [low]

**Units.**
- **A: AD-8.** "An attempt counts from its claim until the threshold expires."
- **B: `AttachmentRepository::finish()` and `end_attempt()`.** Both overwrite `updated_at`, and nothing else keeps the claim time.

**Incompatibility.** An attempt that ends without a variant transition leaves no trace. Examples: a queue worker that finds no pending variant, an `attempt_lost`, a gate deferral under #26. The spine's "counts until the threshold" therefore holds only for attempts still in `processing`. This is harmless today (it errs toward "stale"), but the spine should say what the data supports.

**Proposed AD-8 wording.**
> A claim is evidence while its attempt holds the attachment. After the attempt ends, only its variant transitions remain as evidence.
