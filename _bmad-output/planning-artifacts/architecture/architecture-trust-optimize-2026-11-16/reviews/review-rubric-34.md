# Rubric review: spine amendment for #34 (AD-5, AD-8, AD-9, AD-10, Deferred)

- Reviewed: `ARCHITECTURE-SPINE.md` working tree on 2026-11-18 (diff against HEAD 61e8212, including the on-disk fix to the AD-5 backpressure wording), the last six #34 entries of `.memlog.md`, and the code at HEAD.
- Code read: `includes/bulk/JobHealth.php`, `BulkJobRepository.php`, `BulkProducer.php`, `includes/storage/AttachmentRepository.php`, `VariantRepository.php`, `includes/planning/VariantPlanner.php`, `includes/queue/ConversionQueue.php`, `Maintenance.php`, `includes/processing/AttachmentProcessor.php`, `includes/service/ImageCleanupService.php`, `includes/features/optimization/ImageConverter.php`. I also read the #34 draft spec to judge what the level below needs.

## Verdict

The amendment is sound. It ratifies the code: AD-9 matches `JobHealth::check_admin_load()`, the AD-10 death CAS matches `end_attempt()`, and AD-5 backpressure matches `produce()`/`has_backlog()`. The AD-5 priorities and the AD-10 claim guard are new behavior that the spine states and assigns to #34. The factual claim in AD-8 holds at HEAD, with the qualifications listed below.

Three gaps remain:

- The attempt-evidence rule depends on an invariant about who writes variant rows. The spine states that invariant only as a description of today's code, not as a rule that binds future writers.
- "Producer step" has no data definition, and that conflicts with "the age of the job row" not being enough by itself.
- "Progress since Resume" is described as identical to the liveness evidence. It is not, and it cannot be for remove and inventory jobs.

## Verification of the AD-8 factual claim

**Claim:** the only writers of variant rows are planner upserts (always `pending`), worker token transitions, the death CAS (`pending` or `failed`/`max_attempts`) and cleanup (`failed`/`delete_failed`). Only `claim()` stores attachment state `processing`.

### Variant writers at HEAD

These are all calls to `VariantRepository::upsert/transition/delete` plus the raw SQL on the variants table:

| Writer | Location | Statuses written | Verdict |
| --- | --- | --- | --- |
| Planner insert | `VariantPlanner.php:115` | Default `pending` (`VariantRepository::DEFAULTS`) | Matches, with the race caveat below |
| Planner reset | `VariantPlanner.php:119-131` | Explicit `pending`, reason NULL | Matches |
| Worker: claim start, stale `processing`→`pending` | `AttachmentProcessor.php:276` (token) | `pending` | Matches (not evidence) |
| Worker: `pending`→`processing` | `ImageConverter.php:109` (token) | `processing` | Matches |
| Worker: `processing`→`skipped`/`not_smaller` | `ImageConverter.php:149` (token) | `skipped` | Matches |
| Worker: `processing`→`done` | `ImageConverter.php:200` (token) | `done` | Matches |
| Worker: `processing`→`failed` | `ImageConverter.php:269` (token) | `failed` with `missing_file`, `unsupported_format`, `no_editor`, `target_exists_foreign`, `save_failed`, `rename_failed`, `unexpected_output`, `target_outside_uploads`, `publish_unsafe` | Matches: never `max_attempts` or `delete_failed` |
| Death CAS, and operator `release()` through the same `end_attempt()` | `AttachmentRepository.php:806-850` | `processing`→`pending` (reason kept); when poisoned, `processing`/`pending`→`failed`/`max_attempts` | Matches. `release` is the same CAS, which AD-8 already names |
| Cleanup delete failure | `ImageCleanupService.php:215` (no token, from any status) | `failed`/`delete_failed` | Matches |
| Cleanup row delete | `ImageCleanupService.php:230` | Row removed | Not a status write, but it erases evidence (low impact) |
| `delete_for_attachment()` | `VariantRepository.php:367` | Row removed | Only tests call it |

### Attachment `processing` writers

- `claim()` (`AttachmentRepository.php:302-326`) is the only statement that writes the literal `processing`.
- `recompute()` returns early on a derived `processing` (l.280).
- `set_state()` and `write_state()` exclude `processing` rows in their WHERE clause. No caller passes `processing`.
- `mark_queued`, `requeue`, `park_where`, `suspend_unfinished` and `end_attempt` write other states.
- Lease writes leave `updated_at` untouched (l.700-708), so a `processing` row's `updated_at` is its claim time.

### Qualifications

1. **Upsert race.** The insert path of `upsert()` updates, on a duplicate key, only the columns it carries plus `updated_at` (`VariantRepository.php:110,125`), and it carries no status. If two planners race for a missing row, the second one refreshes `updated_at` on whatever status the row has by then. This is rare but it is false evidence. "Always pending" is true for the status, not for the timestamp.
2. **`finish()` does not enforce "never processing".** The sync path's `up_to_date` branch (`AttachmentProcessor.php:170-172`) passes `derive_state()` unmapped. `convert_pending()` maps `processing`→`queued` (l.356-358). The sync branch could store `processing` with the token cleared, but only if `begin()`'s token reset failed on a database error. So "only `claim()` stores `processing`" holds by convention, not by construction.
3. **The #34 spec adds a fifth writer.** Its CAS that ends a refused legacy row as `failed`/`max_attempts` "together with its `pending` variants" is a new variant writer. Its output is excluded, so evidence stays correct, but the list of writers in the memlog decision and the spec is already incomplete. See H1 and M3.

## Findings

### H1 — The attempt-evidence rule depends on a writer invariant that the spine does not bind (high)

- **Location:** AD-8 "Attempt evidence"; AD-3; AD-14; AD-8 Binds.
- **Evidence:**
  - Evidence is defined by the status and reason a variant row ends in: `processing`/`done`/`skipped`, or `failed` with any reason except `max_attempts` and `delete_failed`. That is a correct test only while every non-worker writer writes `pending`, `failed`/`max_attempts` or `failed`/`delete_failed`.
  - The spine does not say this. The writer list exists only as a fact in the memlog and the spec.
  - Several bound units will add variant writers:
    - **AD-14 re-optimization:** "a `done` variant keeps status `done` … until its replacement is successfully published". Today the planner resets `done`→`pending` (`VariantPlanner::reconcile` → `reset`). Whoever implements AD-14 will have to write to a `done` row, or next to it, from the planner. Any such write that refreshes `updated_at` becomes "worker evidence".
    - **#26:** deferral data may live on the variant (Deferred, item 3), and the slot may reuse the attempt record.
    - **#35:** Cancel of queued attachments.
    - **#40:** per-image settings.
    - **#19:** `target_exists_foreign` moves to `skipped`. That is a worker write, so it is fine.
    - **A new cleanup reason**, or any new non-worker `failed` reason, would count silently because the reason test is an exclusion list.
  - AD-8 Binds lists only #34 and #30. It does not list #26, the AD-14 owner, #35 or #40.
- **Why it matters:** this is exactly the divergence #34 fixes. A non-worker rewrite (here, `recompute()`) was read as progress. Without a binding rule, the next unit can bring the bug back in a different table.
- **Suggested fix:**
  - Add a rule, in AD-3 or AD-8: *Only a worker's token-guarded transition may write a variant into `processing`, `done` or `skipped`, or into `failed` with a worker reason, or refresh `updated_at` of a row in those statuses. Every other writer writes only `pending`, `failed`/`max_attempts` or `failed`/`delete_failed`, or deletes the row. A unit that needs another non-worker write (for example AD-14 re-optimization, #26 deferral on the variant, #35 Cancel) amends AD-8 first.*
  - Add #26, #35, #40 and the AD-14 owner to the AD-8 Binds.
  - Optionally, turn the exclusion list into a statement of which reasons only non-workers write. That makes a new reason fail closed.

### M1 — "Producer step" has no data definition and conflicts with "the age of the job row" (medium)

- **Location:** AD-8 "One function" (producer's own steps), "Not stale by itself" (age of the job row), "Threshold"; AD-10 "Job error"; AD-17 metrics.
- **Evidence:**
  - The only stored trace of a producer step is `trust_optimize_jobs.updated_at`, and the #34 spec reads it that way.
  - Many writers refresh it while the job runs:
    - `set_cursor`;
    - `touch()` in `wait()`;
    - `merge_snapshot` (inventory today; AD-17 says display-only batch metrics also go to `settings_snapshot.metrics`);
    - `mark_running` at start and at Resume;
    - `revert_resume`;
    - the generic `update()`.
  - Only `record_death` deliberately avoids it (`BulkJobRepository.php:524-538`). AD-10 relies on that ("does not touch the job's `updated_at`. A recovery is never progress"), so the spine already treats the job row's `updated_at` as liveness data.
  - AD-8 still says that the age of the job row does not, by itself, make a job stale or alive. A fresh job row by itself does make it alive under the spec.
- **Why it matters:** #26 will write metrics. If those writes go through `update()`/`merge_snapshot` from the conversion path, every conversion refreshes the job row. That is a liveness write in effect, and it hides a dead producer. Two units can disagree on whether Resume or start counts as a producer step.
- **Suggested fix:**
  - Define a producer step as a write of the job's `updated_at` by the producer's own run (cursor, wait, inventory totals), plus start and Resume.
  - Require every other job-row write (metrics, death, diagnostics) to leave `updated_at` alone, as `record_death` does.
  - Reword the "age of the job row" bullet: *a job row's age is only a producer-step signal; it never decides alone without the attempt evidence and deferrals.*

### M2 — "Every consumer … uses this evidence" overstates what progress since Resume uses (medium)

- **Location:** AD-8, the last sentence of "Attempt evidence"; AD-9 "One stop per stoppage".
- **Evidence:**
  - The liveness function also uses producer steps and deferrals.
  - Remove and inventory jobs have no attempts at all. `has_progress_since_resume()` uses `cursor_id > resume_cursor_id` for them (`BulkJobRepository.php:372-374`), and the spec keeps that.
  - For sync jobs the spec counts attempt evidence only: no cursor movement, and no `touch()`.
  - None of this is in the spine. Read literally, the spine sentence makes remove and inventory jobs never show progress since Resume.
- **Suggested fix:** state both predicates.
  - Liveness = producer step OR attempt evidence OR pending deferral.
  - Progress since Resume = attempt evidence for sync jobs; cursor movement for job types that do not convert; never a producer wait.

### M3 — The claim poison guard can strand a non-terminal row, and the spine does not say where it goes (medium)

- **Location:** AD-10 "Poison file" (new bullet); AD-7 "A job never completes while …"; AD-3 CAS list.
- **Evidence:**
  - After #34, a queue `claim()` refuses `attempts >= MAX_ATTEMPTS`.
  - A row that is not `failed` but carries 3 attempts, such as a legacy `queued` row, can then never be claimed. `mark_queued` and `requeue` refuse it as well.
  - It stays in `JobProgress::in_flight()`, so `finish_or_wait()` waits forever. Each wait is a `touch()`, a producer step, so AD-8 judges the job alive forever and it never completes.
  - The spec fixes this with a new CAS that moves the row and its `pending` variants to `failed`/`max_attempts`. It also says `process()` must skip the busy/park/retry path on this refusal.
  - The spine sets neither rule. The new CAS is also a new variant writer and a new AD-3 race-able transition.
- **Suggested fix:** add to AD-10:
  - *A queue claim refused for the count ends the row terminal by CAS (`failed`/`max_attempts` with its open variants) unless it already is.*
  - *`process()` then neither parks, retries the claim, nor schedules.*
  - List the CAS in AD-3.

### L1 — "matching the AD-10 recovery wait" is false when the filter is below 900 s (low)

- **Location:** AD-8 "Slow attempt".
- **Evidence:** `stale_after_seconds()` clamps the filter to at least 60 s. The recovery wait is `max(900, filter)`. With a filter of 300, an attempt stops counting for liveness at 300 s but cannot be recovered before 900 s. The spec's "Low filter" row accepts this; the spine says the two match.
- **Suggested fix:** *An attempt counts for liveness until the threshold. It can be recovered only after the recovery wait, which equals the threshold when the threshold is at least 900 s.*

### L2 — "a claim" against "an attachment's stored state is not evidence" (low)

- **Location:** AD-8 "Attempt evidence".
- **Evidence:**
  - A claim can only be observed as the stored state `processing` together with its `updated_at`, and only while the attempt runs.
  - Once `finish()` runs, the claim leaves no trace. An attempt that moved no variant leaves no evidence at all. Examples: an ineligible source under the claim, or, after #26, a slot or pace defer.
  - The two sentences read as contradicting each other. A unit could invent a `claimed_at` column so that finished or deferred claims count as progress since Resume.
- **Suggested fix:**
  - Use *"a running claim: state `processing`, timed by its `updated_at`, which only `claim()` sets"*.
  - Add that nothing else may write `updated_at` of a `processing` row (lease writes already comply).
  - Say whether a claim that ends in a defer counts as progress since Resume. The current answer is no.

### L3 — The Threshold bullet still uses the old wording (low)

- **Location:** AD-8 "Threshold": "no new change among the job's attachments".
- **Suggested fix:** "no attempt evidence". The old wording is what made `recompute()` count.

### L4 — Operator release, the held list and the queue signals keep 900 s (low)

- **Location:** AD-10 "Confirmed death", "one setting for job staleness (AD-8) and this wait".
- **Evidence:**
  - `JobHealth::release()` refuses only below `STALE_CLAIM_SECONDS` (l.276).
  - `find_held()` and the queue signals use 900.
  - The spec keeps these at 900. The spine does not mention them, so with the filter raised to 3600 an operator can release a slow live queue attempt at 901 s.
- **Suggested fix:** record which waits stay at 900 s and why (operator action, diagnostics), so a later unit does not "harmonize" them, or harmonize them on purpose.

### L5 — AD-10 "Only the attempt or the death CAS leaves `processing`" ignores deactivation (low, pre-existing)

- **Location:** AD-10; AD-16; AD-8 now names "suspension".
- **Evidence:**
  - `suspend_unfinished()` moves `processing`→`none`/`deactivated` and gives back one attempt (`AttachmentRepository.php:416-447`). It leaves variants in `processing` for the next `begin()` to reset.
  - Cleanup on `delete_attachment` moves a `processing` variant to `failed`/`delete_failed` without a token (`ImageCleanupService.php:215`), although the attempt's worker may still hold it.
- **Suggested fix:**
  - List suspension (deactivation, outside a lease) as the third exit from `processing`.
  - Note that cleanup on attachment deletion may write a held variant.

### L6 — Frontmatter and Deferred are stale after #30 merged (low)

- **Location:** frontmatter `scope`/`sources` ("as of main 0943f8c", "code at 0943f8c"); Deferred items "Column names and types of the attempt record … bound on step-1 work" and "How the check knows the job's last Resume".
- **Evidence:** HEAD 61e8212 already has:
  - `attempt_source`/`attempt_token`/`publishing_at`;
  - `JobHealth::BATCH_SIZE` 20;
  - `resumed_at`/`resume_token`/`resume_cursor_id`.

  The amendment ratifies this code, but the spine still describes 0943f8c and lists these items as open.
- **Suggested fix:** bump scope and sources to 61e8212, and mark those Deferred items as resolved by #30.

### L7 — The liveness function cannot catch a job held open by an attempt that is never recovered (low, informational)

- **Location:** AD-8.
- **Evidence:**
  - While `in_flight() > 0` the producer waits and `touch()`es every 30 s.
  - A synchronous or legacy attempt that is never recovered automatically (AD-10), or a stranded row (M3), therefore keeps the job "alive" indefinitely.
  - Only the held notice reports it.
  - This is consistent with the owner's decision, but the spine does not say it, and a later unit may expect AD-8 to stop such jobs.
- **Suggested fix:** add one sentence. A running producer keeps its job alive; work that never ends is surfaced by the AD-10 held report, not by AD-8.

## Checklist summary

- **Divergence points for #34, #26 and #30:** mostly fixed. Still open:
  - the binding writer invariant (H1);
  - the producer-step data and who may refresh it (M1, which affects #26 metrics);
  - progress since Resume per job type (M2);
  - where a refused poison row ends (M3).
- **AD rules are enforceable and prevent their divergence:** AD-5, AD-9 and AD-10 are. AD-8 attempt evidence is correct today but not enforceable against future writers (H1).
- **Deferred:** removing the Maintenance/Resume stop item is correct, because AD-9 now decides it. Nothing left under Deferred lets two units diverge on #34's subject; the #30 items are only stale (L6). The missing items are listed above rather than in Deferred.
- **Ratifies the brownfield code:** yes. The AD-8 factual claim holds at HEAD, with the upsert-timestamp race and the unenforced `finish()` guard as caveats. AD-5 priorities and the AD-10 claim guard are explicitly new behavior for #34.
- **Internal consistency:** minor wording conflicts (L1, L2, L3, L5) and the M1 conflict with "age of the job row".
