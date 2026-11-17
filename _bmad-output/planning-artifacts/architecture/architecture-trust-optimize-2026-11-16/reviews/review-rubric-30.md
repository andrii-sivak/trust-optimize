# Rubric review: spine amendment for #30 (AD-7, AD-9, AD-10, Deferred)

- Reviewed: the uncommitted diff of `ARCHITECTURE-SPINE.md` against `HEAD` (62676d3), measured against the last seven memlog entries (from "update run for #30 spec").
- Code checked: `includes/storage/AttachmentRepository.php`, `includes/queue/Maintenance.php`, `includes/queue/ConversionQueue.php`, `includes/processing/AttachmentProcessor.php`, `includes/features/optimization/ImageConverter.php`, `includes/bulk/BulkJobRepository.php`, `includes/bulk/BulkProducer.php`, and bundled Action Scheduler 3.9.3 (`functions.php`, `ActionScheduler_DBStore`, `ActionScheduler_QueueCleaner`, `ActionScheduler_Abstract_QueueRunner`, `ActionScheduler_FatalErrorMonitor`).

## Verdict

**Pass with fixes.** The amendment carries the owner's decisions faithfully, apart from two narrowings and one added detail, all listed below. The queue signals fit the documented AS 3.9.3 API. They do not conflict with AD-5. Two rules in AD-10 cannot be enforced as written: the late-worker guard is too narrow, and the absence test cannot work inside `claim()`. Fix both before the #30 spec freezes. Otherwise the #30 spec has to invent the missing pieces, and two implementations may invent different ones.

## Findings

### F1 — HIGH: the late-worker guard does not cover variant writes or the next-action scheduling (AD-10, AD-4, AD-13)

The new rule says: "Its closing writes are conditional on still holding that attempt." The code shows that a late worker does more than write the attachment state:

- **Variant ABA.** The worker that takes over the attempt runs `AttachmentProcessor::run()`. It resets every `processing` variant row to `pending` (line ~203) and then `ImageConverter` moves the row back to `processing` (`transition( PENDING, PROCESSING )`). The late worker's publish is `transition( PROCESSING, DONE )` (ImageConverter ~121/139). That CAS still hits, because the status is `processing` again, this time held by the new worker. Two encoders then write the same `relative_path`, and the late one publishes over a row it no longer owns. This is the overwrite AD-13 prevents.
- **Duplicate next action.** `ConversionQueue::process()` schedules a continuation whenever the result says `more`. A late worker whose attempt was recovered would schedule a second action next to the one the recovery CAS already queued. AD-4 forbids exactly that ("duplicate or competing next actions").
- **Attempts reset.** `reset_attempts()` is unconditional today. A late worker that resets it after the recovery erases the death count, and the poison-file loop comes back. The rule covers this only if "closing writes" is read broadly.

**Fix:** list in AD-10 → Late worker what the attempt guards:
1. resetting `attempts`;
2. every attachment state write (`recompute`, `set_state`);
3. every variant transition out of `processing` (to `done`, `failed` or `pending`);
4. the scheduling of the next action.

Then add: "A worker that finds its attempt recovered returns a result on which `process()` schedules nothing." Leave the mechanism to the #30 spec, as Deferred already does.

### F2 — HIGH: the absence test can never succeed inside `claim()` (AD-10 Confirmed death)

A death is confirmed only when "AS has no `pending` or `in-progress` action for that attachment any more", and the rule lists `claim()` as one path that takes over a confirmed dead attempt. But `claim()` runs inside `ConversionQueue::process()`, which is itself the attachment's own `in-progress` action. As 3.9.3 `as_has_scheduled_action()` queries `STATUS_RUNNING` and `STATUS_PENDING`. So, read literally, `claim()` can never confirm a death from an AS runner. An implementer then has two options:

- (a) exclude the caller's own action. That needs the action ID, which comes only from the `action_scheduler_begin_execute` / `before_execute` hooks. Those hooks are not `as_*` functions, so this is outside the AD-5 wording "only `as_*` functions and the store status constants".
- (b) ignore the test in `claim()`, which is the age-based takeover the owner just forbade.

Two units, #30 and #26 (whose slot code also runs inside the action), can pick differently.

**Fix (owner decision, through `bmad-architecture`):** prefer dropping the age-based takeover from `claim()` altogether. A stale `processing` row makes `claim()` return busy, and the action ends. Maintenance, the job-health check and Resume recover the row through the shared CAS once the busy action has completed. This is simpler, needs no action ID, and keeps AD-5 intact. If the owner wants `claim()` to keep taking over, the rule must say "no action other than the caller's own" and AD-5 must name the hook it relies on.

`mark_queued()` has no such problem: it runs from the producer, the upload hook or Maintenance, never from the attachment's own action.

### F3 — MEDIUM: the AD-9 interim stale test contradicts AD-8 as written, and the #30/#34/#26 ownership split is missing from the spine

- AD-9 says that until #34, step 2 is "the job's `updated_at` against `trust_optimize_bulk_stale_after_seconds`". AD-8 says that "the age of the job row" never makes a job stale by itself. Both rules bind #30. An implementer or reviewer of #30 will flag the conflict, or "fix" it by building the AD-8 function inside #30. That function belongs to #34, so the two units would diverge. AD-8 itself makes no exception.
- The owner decided that "#30 owns the AD-9 frame (JobHealth …), #34 owns AD-8; #26 adds deferral data". The spine does not say who builds the single entry point. Only the structural seed says "job-health check (#34, #30)". #34 could therefore add its own health hook, which is the scattering AD-9 prevents. `BulkProducer::register()` still hooks `recover_stale_jobs` on bare `admin_init` with no ajax/cron guard. Some unit has to remove that hook, and the spine does not say which.

**Fix:**
- Add to AD-8 → "Not stale by itself": "Interim: until #34 lands, AD-9 step 2 keeps the old row-age test, on admin page loads only. #30 does not build this function."
- Add an **Ownership** bullet to AD-9:
  - #30 builds the single entry point and removes the bare `admin_init` hook;
  - #34 replaces step 2 with the AD-8 function;
  - #26 supplies the deferral data AD-8 reads.

### F4 — MEDIUM: job error and notice — a narrowing that is not in the decision, an undefined order, and wrong text for known reasons (AD-10 Job error, AD-9 Notice, AD-7)

- **Narrowing.** The owner wrote: "the death recovery replaces (never appends) the job's `last_error`". The spine narrows this to "an attachment **of a running job**". Maintenance and Resume can recover a death after the job was paused `stopped`. With the narrowing, that death is never written, and the notice says "cause unknown" even though the cause is known.
- **Order.** Resume both "clears the job's `last_error`" (AD-7) and runs step 1, which may write it (AD-9). The spine does not fix which comes first. One order wipes a death that was just recorded; the other leaves a stale error on a running job.
- **Known reasons.** The notice now "shows the job's `last_error`, written by death recovery, or says plainly that the cause is unknown". For `low_disk_space` and `deactivated`, `last_error` is normally empty. Once the disk has been freed, the notice would call a known cause unknown.
- **Old schema.** On a site whose jobs table is not yet migrated (network subsites migrate lazily), `last_error` still holds the pause reason (`BulkJobRepository::pause_data`). A replace in that state erases the reason. Today's `recover_stale_running` appends for this very reason.

**Fix:**
- Drop "of a running job", or ask the owner if the narrowing was intended.
- State that Resume clears `last_error` before it runs step 1.
- Make the notice name the `pause_reason` first, then `last_error` when present. It says "unknown" only for `unknown`, or for `stopped` with no `last_error`.
- Say that death recovery writes `last_error` only on the current schema.

### F5 — MEDIUM: "Refusal" scope and an added poison-file detail (AD-7, AD-10)

- **Refusal scope.** "Resume is refused only on a confirmed block" can be read as the only ground for refusing at all. AD-11 says that "Resume checks again" for low disk. **Fix:** "On queue grounds, Resume is refused only on a confirmed block…".
- **Poison file.** The owner said "normal planning no longer queues it". The spine lists "bulk, **metadata regeneration**, Maintenance". Metadata regeneration also fires when the source file is replaced, and AD-14 treats a replaced source as a change. A new file is not the poison file, yet under this rule it would never be converted. Today `mark_queued()` re-queues `failed` rows freely, so this is a behavior change either way. **Fix:** ask the owner whether a changed source resets the count. Until then, write "normal planning", as the owner did, and leave the list to the #30 spec. Also say whether `wp trust-optimize sync --now`, which calls `sync()` and so resets attempts for the whole library, counts as an "explicit sync".

### F6 — LOW: AS 3.9.3 behavior the spine should acknowledge (AD-10 Prevents, AD-9 signals)

- **The "live long conversion taken over by age" claim overreaches.** Any runner that starts calls `clean( 10 × time_limit )`, which is 300 s by default. `mark_failures()` then marks an `in-progress` action `failed` while its PHP process may still be encoding. Once the attachment has been `processing` for more than 900 s, the absence test passes, and a single encoder call longer than 900 s (WP-CLI, no `max_execution_time`) is taken over anyway. The late-worker guard (F1) is the real protection. **Fix:** reword the Prevents line as "…taken over while AS still runs it". The overreach was in the Prevents line itself, so no extra text is needed elsewhere.
- **The death-confirmation delay differs by AS version.** With a SIGKILL or container OOM, `FatalErrorMonitor` never runs, and the action stays `in-progress` until the next runner's `mark_failures`. The memlog says AS 4.0 moved cleanup to a daily job. If that includes `mark_failures`, confirmed deaths under a WooCommerce-loaded 4.x can take up to a day. Verify this under #1. AD-5 already says AD-4/AD-5 hold on 4.x, but makes no such claim for AD-10's timing.
- **Ratified:** the `as_get_scheduled_actions()` arguments `claimed` (true/false), `modified` (compared against `last_attempt_gmt`, which `claim_actions`, `log_execution` and `mark_complete` all update) and `status` are documented in the 3.9.3 docblock and behave as the signals need. AS's own `reset_timeouts` and `mark_failures` act at 300 s, so a stuck claim older than 900 s really does mean the runner has not run. That is consistent with AD-5.
- **Wording.**
  - The Resume warning "runs only once Action Scheduler's own runner can run again" claims a block. The owner said processing "depends on the AS runner". The no-progress signal also fires on a shared queue that is busy, not blocked: priority-20 conversions waiting behind WooCommerce, which AD-8 accepts. Keep the warning and the notice text neutral ("has not progressed for 15 minutes").
  - "None of the group's actions finished" should name the statuses: `complete` only, or `complete` and `failed`.
- **Admin page cost.** Step 1 on every admin page load inherits Maintenance's `find_stuck( …, 100 )` plus one `as_has_scheduled_action()` per row. That can mean about 100 AS queries per admin request. Ask the #30 spec to bound this, for example with a small batch or by limiting the check to plugin screens or to sites with a job or stuck rows. Load `wp-performance` for that spec.

### F7 — LOW: Deferred entries that can let units diverge

- **"How an attempt is identified" (owned by #30).** If #30 solves this with a new attachments column, while #26 adds deferral columns to the same table, both bump `DB_VERSION` independently. AD-17 covers only the jobs-table change. **Fix:** say that an attempt identity should first try existing data (`state`, `updated_at`, `attempts` as the CAS token) and needs no schema change. If it does need one, state that each attachments-table change ships as its own repeatable migration, and the second unit to merge rebases its version.
- **The attempt identity (#30) and the one-at-a-time slot lease (#26) both answer "who is running this".** Add one line saying whether #26 reuses #30's identity or keeps an independent lease. Either is fine; leaving it open invites two tokens.
- **"Whether Maintenance or Resume may also stop a job … Owned by #34."** #34 cannot change an AD rule on its own authority. **Fix:** "#34 proposes; the owner amends AD-9 through `bmad-architecture`."
- The #30 GitHub acceptance item 3 still says that Resume while blocked keeps the job paused (open question in the memlog). The spine wins, per `AGENTS.md`. Mention the mismatch in the #30 spec so the implementer does not test against the old criterion. `RestController.php:426` still has a 409 path. Check that it is not the old queue-blocked refusal.

## Faithfulness to the owner's decisions

| Owner decision (memlog) | In the spine | Status |
| --- | --- | --- |
| Two queue signals, read only through `as_get_scheduled_actions`, threshold 900 s, both diagnostic; no 409 from action ages; refusal only on a confirmed block; a Resume that fails by itself shows the error; claim cleanup stays with AS | AD-7 Queue signals / Refusal / fails by itself; AD-9 Queue signals; Deferred | Carried. The warning wording is stronger than the decision (F6). The refusal scope is ambiguous (F5) |
| `MAX_ATTEMPTS` 3; each confirmed death counted once; the third makes the attachment `failed`/`max_attempts`; normal planning stops queueing it; waits and deferrals are not deaths; explicit sync resets the count | AD-10 Counting, CAS, Poison file | Carried. The list "metadata regeneration" was added (F5) |
| Confirmed death = `processing` > 900 s and no pending or in-progress action; no takeover by age while one exists; shared CAS once; sync attempts excluded; a late worker must not overwrite | AD-10 Confirmed death, Late worker | Carried, but not enforceable as written (F1, F2) |
| #30 owns the AD-9 frame; old `updated_at` test until #34, on admin loads only; Maintenance and Resume never stop a job by row age; #34 owns AD-8; #26 adds deferral data | AD-9 Who runs which steps; Deferred | Mostly carried. Ownership of the frame is not stated, and AD-8 makes no exception (F3) |
| Death recovery replaces `last_error` with the attachment ID and death count; the notice shows it or says the cause is unknown; Resume clears `last_error` | AD-10 Job error; AD-9 Notice; AD-7 | Narrowed to "running job"; order with Resume not fixed (F4) |
| Question: #30 acceptance item 3 is outdated | not in the spine (correctly) | Mention it in the #30 spec (F7) |

Nothing else was invented. The added AD-10 Binds entries (planning, REST, WP-CLI) follow from the poison-file and sync-attempt rules.

## Cross-AD consistency

- **AD-5:** consistent. The signals are read through `as_*` only. Nothing touches claims or calls `QueueCleaner`. F2 is the one place where the AD-5 wording would be strained.
- **AD-8:** contradicted by the interim AD-9 step 2 unless an explicit exception is added (F3).
- **AD-4:** at risk from a late worker's continuation scheduling (F1). Recovery requeueing through `requeue`, followed by scheduling, matches AD-4.
- **AD-3:** consistent. The single CAS for leaving `processing` replaces today's unconditional `set_state( FAILED, 'max_attempts' )` in `Maintenance::run()`, which is the intended change of the `[ADOPTED, amended]` tag. The late-worker rule is how AD-3's "only the current claim holder writes unconditionally" is enforced, once F1 widens its scope.
- **AD-11 / AD-7 disk:** consistent once the refusal scope is fixed (F5).
- **AD-14:** possible conflict with the poison-file list on source replacement (F5).
