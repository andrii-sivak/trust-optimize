---
title: 'Adversarial review: spec-gh-30-dead-worker-recovery (draft)'
reviewed: '_bmad-output/implementation-artifacts/spec-gh-30-dead-worker-recovery.md'
date: '2026-11-17'
lens: adversarial (publication protocol, release command, Pause/Resume races)
code_base: main 62676d3
---

# Verdict

**Not ready to leave draft.** The publication lease itself holds: it sits on the attachment row and races the death CAS on that same row, so the two can never both succeed under RR or RC. The holes are around it:

- **Orphan `processing` variants.** The death outcome spans two tables, `run()` no longer resets `processing` variants, and nothing forbids an unconditional `recompute()` from writing `processing`. A `processing` variant can therefore outlive its attempt. Once it does, the attachment cannot finish, or it becomes a NULL-source "legacy" held row that only WP-CLI can free.
- **Release ignores the lease.** The release CAS does not key on the lease. The spec's own argument ("any write since the inspection has changed `updated_at`") is false for the lease write.
- **Lease release failure.** The spec gives no rule for continuing after a failed lease release, so the worker's next lease misses on its own lease.
- **Stale skip.** The skip turns a producer that dies after Resume into a job that runs forever, with no notice and no button.

Severities below: critical 0, high 3, medium 10, low 11.

Code references are to main at 62676d3.

---

# 1. Publication protocol (lease, death CAS, guarded transitions, closing write)

## What holds

- **Lease vs death CAS: sound.** Both are `UPDATE attachments … WHERE attachment_id = X AND state = processing AND attempt_token = T AND publishing_at IS NULL`. Each one falsifies the other's predicate (the death CAS clears the token and leaves `processing`; the lease sets `publishing_at`). InnoDB evaluates an UPDATE's own target row with a current (locking) read in both REPEATABLE READ and READ COMMITTED, so the second statement waits for the first and re-evaluates. They cannot both hit. Autocommit is enough here.
- **`done` vs death CAS: sound while the lease is held.** The worker takes the lease before the rename and keeps it until after the `done` write, and the death CAS requires `publishing_at IS NULL`. So no recovery can land between the rename and `done`. The AC "late worker after new publication" holds: T1's lease misses on `attempt_token = T2`, or on `state <> processing` while the row is queued or parked.

## F1 — Orphan `processing` variants: the death outcome is not atomic, and nothing heals it [HIGH]

**Where:**
- Spec, Always / Death CAS: "its `processing` variants go back to `pending`, and the attachment state is chosen in the same UPDATE". Also "`run()` no longer resets `processing` variants of earlier attempts" and "`recompute()` and `set_state()` without a token never touch a `processing` row".
- Code: `AttachmentProcessor::run()` l.200-205 is the only healer today. `derive_state()` (`AttachmentRepository` l.92) returns `PROCESSING` whenever any variant is `processing`. `Lifecycle::deactivate()` → `suspend_unfinished()` (l.338) touches attachments only.

The death outcome writes two tables. Without a transaction it is two statements, unless it is written as one multi-table `UPDATE attachments a LEFT JOIN variants v …`, and the spec does not say which. A `processing` variant whose attempt is gone is therefore reachable in several ways:

1. **Recovery request dies between the two statements.** This happens on admin page loads with `max_execution_time`, or on a PHP-FPM kill. The attachment is `queued` and its token is cleared, but the variant is still `processing`.
2. **Deactivation during a live conversion.** `suspend_unfinished()` moves the attachment to `none/deactivated` and leaves variant V `processing`. Worker T's lease then misses (`state <> processing`), so it deletes its temporary file and stops without a write. Nobody resets V. This needs no race at all: it is the common "deactivate the plugin during a bulk" path.
3. **READ COMMITTED write skew.** T runs `pending → processing` on V2, guarded by `EXISTS` on the attachment holding T, concurrently with the death CAS. The MySQL manual says the SELECT part of `UPDATE … (SELECT …)` is a non-locking read under READ COMMITTED and takes shared locks only under REPEATABLE READ. T's guard can therefore read the pre-recovery snapshot. The death statement's variant step meanwhile sees V2 as `pending` (a semi-consistent read of the last committed version) and skips it. Result: V2 is `processing`, and the attachment is `queued` with no token.
4. **Lease missed.** T stops without any write ("the worker stops"), so its current variant keeps `processing` whenever the death CAS's variant step ran before T's `pending → processing`.

**Wrong outcome.** The next claim T2 does not reset V ("`run()` no longer resets"). T2's closing write derives `PROCESSING`. The spec does not say what the closing write stores then, and both obvious implementations go wrong:

- **(a) It stores `processing` and keeps or clears T2's token.** After 900 s, step 1 sees a queue attempt whose AS action is complete and counts a **spurious death**. Three such cycles make a healthy file `failed/max_attempts` (poison).
- **(b) It stores `processing` with the token cleared** (or an untokened `recompute()` from cleanup or the planner writes `processing`). The row then reads as `attempt_source IS NULL` = "a conversion started before the update". It is **never recovered automatically**, a non-dismissible held notice appears, the bulk never finishes, and only `wp trust-optimize release` frees it.

Either way the bulk does not complete. The in-flight count includes the row.

**Proposed spec wording:**

> - **Only a claim heals orphans.** After a claim hits, the new attempt moves every `processing` variant of the attachment back to `pending`. The transition is guarded by its own token. A claim hits only when no attempt holds the attachment, so such a variant belongs to a lost attempt, and that attempt's lease and `done` write miss on the token. This is not "resetting variants another attempt still holds".
> - **No write stores `processing` except `claim()`.** `recompute()` (with or without a token), the closing write and `set_state()` never store `processing`. When the variants include `processing` but the writer is not the attempt holding the attachment, the derived state is `queued`.
> - **One statement for the death CAS and release.** Each is one `UPDATE attachments a LEFT JOIN variants v ON v.attachment_id = a.attachment_id AND v.status IN (…) SET … WHERE a.attachment_id = %d AND a.state = 'processing' AND a.attempt_token <=> %s AND a.publishing_at IS NULL`. It counts as a hit when the affected rows are ≥ 1. Afterwards it invalidates the variants object cache of that attachment.

## F2 — Release does not key on the lease, and the lease write does not move `updated_at` [HIGH]

(Also part of focus 2.)

**Where:**
- Spec, release: "one CAS keyed on the attachment, `state = processing`, `attempt_token` …, `updated_at = since` … clears the lease". Its justification: "any write since the inspection … has changed `updated_at`".
- The spec does not say whether the lease CAS or the lease release write `updated_at`. In current code only `claim`, `mark_queued`, `requeue`, `set_state`, `write_state` and `suspend_unfinished` move it. `reset_attempts` (l.293) and `assign_job` (l.405) do not.

**Interleaving.**
1. The operator inspects a held sync attempt T: no lease, `since = S`.
2. The process is in fact alive (a long CLI sync, or a stalled NFS write). T takes the lease for V. If the implementer follows the current pattern of not touching `updated_at` outside state changes, `updated_at` stays S.
3. The operator confirms. The CAS hits (token T, `updated_at = S`). V goes back to `pending`, the lease is cleared, and the attachment is queued with an action.
4. T2 claims, converts V, takes its lease, renames, and writes `done` with hash h2. The site serves h2.
5. T, which had stalled between its lease and its rename, now renames. The served file becomes T's bytes. T's `done` misses on the token, falls into the `row_gone` path, and `discard_unowned_file()` keeps the file because the row still owns the path.

**Wrong outcome.** The served file no longer matches the row's `file_hash` (AD-13 broken). Later cleanup returns `hash_mismatch` and skips the file (`ImageCleanupService` l.306), so the file can be orphaned. The release confirmation was meant to catch exactly this change, and here it misses it.

**Proposed spec wording:**

> - **The lease is part of the confirmation key.** The inspection prints the lease value (`none` or the `publishing_at` time). The confirming command carries it (`--lease=none|<publishing_at>`). The release CAS also requires `publishing_at <=> %s` (the observed value).
> - **The lease writes do not touch `updated_at`.** Lease set and lease release leave it alone, so `since` stays the claim time.
> - **Release refuses young rows.** It refuses a row whose `updated_at` is newer than `now − STALE_CLAIM_SECONDS`, because the change-check argument needs that age.
> - **Release refuses a live queue action.** For `attempt_source = queue` it refuses while `as_get_scheduled_actions()` (pending or in-progress, same rules as step 1) still lists an action for the attachment. Otherwise the death outcome would schedule a second action (AD-4).

## F3 — A failed lease release makes the same worker's next lease "miss", so it stops without its closing write [MEDIUM]

**Where:** spec, error outcomes: "Rename and `done` succeeded, but the release failed: `done` stands. The closing write … clears the lease". Also "Lease missed (attempt lost): delete the temporary file … the worker stops."

**Interleaving.** The release UPDATE of V1 fails (a transient DB error). T continues to V2. Its lease CAS requires `publishing_at IS NULL`, so it misses on its own lease. The spec classifies that as "attempt lost", so T stops and makes no closing write.

**Wrong outcome.**
- The lease stays set even after the DB recovers.
- The row turns into a held "lease not released" row that needs the operator, and the bulk is blocked.
- The spec's promise that "the closing write clears the lease" is never exercised.
- An implementer who reads "attempt lost" literally also skips `more` scheduling, so V2 stays `processing`, which leads to F1.

**Proposed spec wording:**

> - **After a failed lease release, the attempt publishes nothing more.** It goes straight to its closing write, which clears the lease.
> - **Alternatively, the lease is re-entrant.** The lease CAS is `WHERE … attempt_token = T AND (publishing_at IS NULL OR <held by T>)`. That needs no extra column, because the token already identifies the holder, and the death CAS keeps `publishing_at IS NULL`.
> - **"Lease missed" means the attempt is lost** only when the row no longer holds `state = processing AND attempt_token = T`.

## F4 — Held classification and age are undefined for leased rows [MEDIUM]

**Where:** spec, "Held attachments" lists the reasons "publication incomplete" (with "the variant's path") and "lease not released". The lease is a single `publishing_at` on the attachment row. It does not name a variant.

**Problems:**
- **Classification.** The spec does not say how the held finder tells the two lease reasons apart, or which path it prints. A developer can (a) print every `processing` variant, (b) print the last `done` one, or (c) print nothing.
- **False alarm on age.** The held age is "processing for longer than 900 s", that is `updated_at`, the claim time. A live queue attempt older than 900 s that holds the lease for a few milliseconds when an admin page loads gets a non-dismissible "make sure that process has ended … run release" notice. AVIF encodes on large originals can take minutes, and the queue budget is checked only between variants (`AttachmentProcessor` l.224). This invites a wrong release, which leads to F2.

**Proposed spec wording:**

> - **Held for a lease only when the lease is old.** A leased row is held only when its `publishing_at` is older than `STALE_CLAIM_SECONDS`.
> - **Reason with a `processing` variant: "publication incomplete".** The lease is set and the attachment has one or more `processing` variants. The notice names each such variant's `relative_path` (reserved at `pending → processing`).
> - **Reason without one: "lease not released".** The lease is set and no variant is `processing`.

## F5 — The closing write is undefined, and guarding today's three statements one by one breaks [MEDIUM]

**Where:**
- Code: `run()` l.240-248 ends with `reset_attempts()`, then `recompute()`, then `set_state()` with the failure reason. These are three statements.
- Spec: "the `attempts` reset, the closing write" are token-guarded, and the closing write "clears the lease as well". The spec does not say whether the closing write clears `attempt_source` and `attempt_token`.

**Two incompatible implementations:**
- **(a) The token is cleared by the first guarded statement.** The later `set_state(reason, last_error)` misses, so failure reasons are lost.
- **(b) The token is never cleared.** A finished row keeps `attempt_source = queue` and a stale token. During deploy, a pre-update process's `claim()` takes the row over by age (old code: `state <> processing OR updated_at < …`, l.248) without touching the new columns. The row then looks like a recoverable *queue* attempt with a dead token, while an unguarded old-code worker runs on it.

**Proposed spec wording:**

> - **The closing write is one statement.** It is `UPDATE … SET state = <derived, never processing>, reason, last_error, attempts = 0, attempt_source = NULL, attempt_token = NULL, publishing_at = NULL, updated_at = now WHERE attachment_id = X AND state = processing AND attempt_token = T`.
> - **Every exit from `processing` clears the attempt record.** That covers the closing write, the death CAS, release, `suspend_unfinished` and the exception path, and each one clears all three attempt columns.

## F6 — The exception path of `process()` is not covered [MEDIUM]

**Where:** `ConversionQueue::process()` l.145-151. Today it calls `set_state(FAILED, 'exception')` with no token. `BulkProducer::hand_over()` l.340 does the same.

Under the new rule ("`set_state()` without a token never touch a `processing` row"), the exception handler becomes a no-op on the worker's own row. The attachment and its current variant stay `processing`. After 900 s, step 1 sees the action `failed`, confirms a death, and retries. That is a silent behavior change: today an exception means `failed` at once, and after this change it means up to three retries and then `max_attempts`. The alternative implementation threads the token into `process()` and keeps today's behavior. Either is defensible, but the spec has to pick one.

**Proposed spec wording:**

> - **An exception ends the attempt in one closing write.** `run()` makes it with the token: state `failed`, reason `exception`, and the current `processing` variant goes to `failed` / `exception`. It holds the lease only if the exception came after the lease was taken, in which case it does not release the lease and the row becomes held "publication incomplete". Then the exception is rethrown.
> - **The producer's `hand_over()` keeps its untokened write.** That write never touches a `processing` row.

## F7 — `not_smaller` on the temporary file leaves the old target as a foreign file forever [MEDIUM]

**Where:**
- Spec: "size check runs on the temporary file". It is listed in the "no behavior change" refactor bullet *and* in the Code Map.
- Code: `ImageConverter` l.228-246. Today the bigger file is renamed over the target. The row then goes to `skipped` with `relative_path = NULL`, and `discard_unowned_file()` deletes the target.

**Interleaving.**
1. A `done` V is re-optimized: the planner resets it to `pending` and keeps `relative_path`.
2. The new encode is not smaller. With the check on the temporary file, the temporary file is deleted and V goes to `skipped` with `relative_path = NULL`.
3. The old file at the target now has no owner.
4. The next re-plan has a file that exists and is not owned (l.193), so publication refuses it with `target_exists_foreign`, permanently.

**Wrong outcome.** A variant is lost to `target_exists_foreign` and a file is orphaned. The refactor commit also changes behavior, against AGENTS.md.

**Proposed spec wording:**

> - **A not-smaller result changes no ownership.** On the temporary file, `not_smaller` keeps the row's `relative_path`, `file_hash` and file. The row goes to `skipped` / `not_smaller` and keeps owning its file. Alternatively, it deletes the old file through the ownership-checked cleanup under the lease (the spec picks one).
> - **This is a behavior commit.** It is not part of the refactoring commit.

## F8 — Deadlocks and errors on cross-table guards [MEDIUM]

Under REPEATABLE READ, the `EXISTS (… attachments …)` guard in a variants UPDATE takes a shared lock on the attachment row after the variant row's X lock. A death or release statement that locks the attachment row and then the variant rows (F1's single statement) can deadlock with it. InnoDB rolls one back with error 1213, and `$wpdb->query()` returns `false`.

The spec defines a "lease query error". It does not define a deadlock on a guarded transition, on the death CAS or on release. An implementation that tests `1 === (int) $wpdb->query(…)` turns an error into a miss. For the `done` write after the rename, that silently takes the `row_gone` path instead of "publication incomplete".

**Proposed spec wording:**

> - **Every guarded write tells an error from a miss.** It distinguishes `false` (error, `$wpdb->last_error`) from `0` (miss).
> - **Death CAS and release: an error is a miss.** The death CAS retries on the next call. Release says "try again".
> - **`done` after the rename: an error is "publication incomplete".** The lease is kept.
> - **Safety comes from the lease.** The spec states that publication safety relies on the same-row lease and never on the cross-table `EXISTS` guard. The guard only stops wasted work.

---

# 2. `wp trust-optimize release`

## R1 — Lease not in the key; no minimum age; no AS check for queue rows [HIGH]

This is F2.

## R2 — Owner trail: the memlog's last design entry is a different mechanism [MEDIUM]

`.memlog.md` entry 29 says:

> legacy release = inspection stamps a token by CAS on (processing, token NULL, attempts, updated_at); release CAS keys on stamped token + attempts + updated_at + state

The spec says the opposite: inspection "changes nothing", and the key is token-or-`legacy` plus `updated_at` without `attempts`.

Either is workable. The `updated_at`-only key is sound for legacy rows because every old-code write that matters (`claim`, `set_state`, `write_state`) moves `updated_at`, given F2's minimum age. But the memlog records the stamping design as the decision, and the spec does not record that it was superseded.

**Fix:** add a memlog entry: "release: inspection read-only; key = token or legacy (`IS NULL`) + `updated_at` + lease; supersedes 29". In the spec, write the legacy key as `attempt_token IS NULL AND attempt_source IS NULL`, not `= NULL`.

## R3 — The release outcome for the job is unspecified [LOW]

The spec says release "applies the death-CAS outcome". It does not say whether it also:

- writes the job's `last_error` ("attachment N, death k of 3");
- schedules the action, given that the death outcome includes "scheduled at priority 20".

**Fix:** "Release writes the job's `last_error` like a death. It schedules the action only for the `queued` outcome and only when R1's AS check found no action."

## R4 — `--since` value format [LOW]

`updated_at` contains a space. The printed confirming command must quote it: `--since="2026-11-17 10:00:00"`. The command must parse it as UTC, exactly as stored.

## R5 — Variant rows and files on release are fine, with one gap [LOW]

- **What holds.** `pending` keeps `relative_path`, so `owns()` (`VariantRepository` l.285, path-only) lets the next attempt overwrite the file.
- **Gap: a row deleted before re-publication.** If the planner deletes the row first (format disabled), the file's hash does not match (the row kept the previous hash), so cleanup skips it as `hash_mismatch` and it is orphaned. That leads to `target_exists_foreign` on re-enable.

**Fix:** accept this and list it under accepted risks with #19, or have release clear `file_hash` on publication-incomplete variants so cleanup deletes the file by path ownership.

## R6 — What already holds against concurrent writers

- **Concurrent claim.** It is impossible, because claims never take over `processing`. The exception is pre-update code during deploy, which moves `updated_at` and so makes release miss.
- **Concurrent closing write.** It moves state and `updated_at`, so release misses. If release comes first, the closing write misses on the token, which is fine.
- **Park.** The park CAS needs `queued`, so there is no overlap.
- **Pause.** The `paused` choice happens inside the statement, so it is fine.
- **Schema-pending.** Release refuses, which is fine.

---

# 3. Pause / Resume

## P1 — The stale skip turns a producer that dies after Resume into a job that runs forever, invisibly [MEDIUM]

**Where:** spec, Stale step: "skips a job that has `resumed_at` and no progress since it". The notice for a running job appears only with queue signals.

**Interleaving.** Resume succeeds. The producer's first run throws (AS marks it `failed`) or its action is deleted. Nothing else is pending.

**Wrong outcome.** No progress since `resumed_at`, so the stale step skips the job forever. There are no signals: no in-progress action, and no due pending action. The job stays `running`, with no notice and no Resume button. Today's code would stop it after 15 minutes. This is a new stoppage after the Resume, so AD-9 says it should stop once.

**Proposed spec wording:**

> The skip applies only while the job is visibly waiting on AS: a producer action of the job is `pending` or `in-progress`, or a queue signal is present. Otherwise the old `updated_at` test applies.

The matrix rows "Producer only waits" and "Producer waited once" still hold, because a producer action is pending in both.

## P2 — Revert semantics for `launch()` and for a Resume of a running job [MEDIUM]

**Where:**
- Code: `mark_running()` l.274-303 accepts `pending`, `running` and `paused`, and returns true for an already running job. `launch()` l.172 calls `resume()` on a `pending` job.
- Spec: revert "restores `paused`, `pause_reason` and `resumed_at`".

**Wrong outcomes:**
- **New job.** When producer scheduling fails, a `pending` job is reverted to `paused` with `pause_reason = NULL` while the mutex stays held. Admin then shows a paused job with no reason. The notice logic ("any reason except `user`") has no case for NULL.
- **Running job.** A Resume on a running job (REST allows it) rewrites R. A scheduling failure then pauses a job that was running.

**Proposed spec wording:**

> - **`mark_running()` reports what it changed.** It returns the previous status.
> - **Revert only from `paused`.** The revert CAS runs only when the previous status was `paused`.
> - **Already running: no new token.** A Resume of a `running` job does not write a new R and only schedules the producer.
> - **`launch()` failure** — owner to decide: cancel the new job and release the mutex, or leave it `pending` and answer `trust_optimize_resume_failed`.

## P3 — The parked set must include `none/deactivated` rows of the job [MEDIUM]

**Where:**
- Spec: the parked set is "`none` / `paused`, `job_id` kept"; `JobProgress` counts parked as in flight.
- Spine AD-7: "Deactivation parks the same way, with reason `deactivated`"; "A job never completes while its parked set is not empty".
- Code: `suspend_unfinished()` stores `deactivated`. `Lifecycle::restore()` runs as a separate async action.

**Interleaving.**
1. Deactivate during a bulk. Rows become `none/deactivated` and the job is paused (`deactivated`).
2. Reactivate, then Resume.
3. The producer runs before the restore action. The cursor is exhausted, and `in_flight` (queued + processing + `none/paused`) is 0, so the job **completes** with unconverted rows.

**Proposed spec wording:**

> The parked set is `state = none AND reason IN ('paused', 'deactivated') AND job_id = J`. One repository method returns it, and `JobProgress` and `finish_or_wait()` use it.

## P4 — Parked rows queued by the producer, when scheduling fails, are lost to the parked set [LOW-MEDIUM]

**Where:** spec, Design Notes: "The producer, not Resume, queues the parked set … it covers failed queueing calls". Code: `ConversionQueue::enqueue()` l.116-120 runs `mark_queued()` and *then* `as_enqueue_async_action()`.

**Wrong outcome.** If `mark_queued()` hits and scheduling returns 0, the row is `queued` with no action. It is no longer in the parked set, so the producer never retries it. The job waits on `in_flight` until Maintenance's day-old `find_stuck()`, and Maintenance needs AS to run. The design note overclaims.

**Proposed spec wording:**

> When scheduling fails after a queueing CAS from the parked set, the producer moves the row back by CAS (`queued → none/paused`, same `job_id`).

## P5 — The claim/park retry can end with neither, leaving a lost `queued` row [LOW]

**Interleaving.**
1. The claim misses (paused).
2. Resume.
3. The park misses (the job is running).
4. Pause.
5. The retry claim misses (paused).

**Wrong outcome.** The action ends and schedules nothing. The row is `queued` without an action, and the producer's parked set does not include it.

**Proposed spec wording:**

> When the retry claim also misses and the row is still `queued`, `process()` reschedules the action at priority 20 (AD-4's next action).

## P6 — Step 1 cannot tell which branch of the death CAS hit [LOW-MEDIUM]

**Where:** spec, "parked … otherwise `queued`, with the action scheduled". The UPDATE's affected-row count does not say which branch ran.

**Wrong outcome.** An implementer who always schedules creates an action for a parked row. After Resume, the producer queues it again, which makes two pending actions for one attachment (AD-4 dedupe broken). An implementer who never schedules loses the `queued` branch.

**Proposed spec wording:**

> After a hit, step 1 re-reads the row and schedules only when `state = queued AND attempt_token IS NULL`. A row a sync claim took meanwhile is left alone.

## P7 — What already holds

- **Pause inside the claim.** It is correct. Under RR the `NOT EXISTS (jobs …)` subquery takes a shared lock that serializes with the Pause UPDATE. Under RC each statement reads the latest committed row, so "a Pause committed before the claim always wins" holds in both modes.
- **Death CAS vs Resume.** Under RC, a death CAS can read `paused` while `mark_running` commits, so the row is parked under a running job. The rule that each producer run first queues the parked set, plus `finish_or_wait()` never completing with a parked row, picks it up on the next run. It is self-healing.
- **Pause between `mark_running` and the revert.** The revert misses on `status`, so the matrix is correct. Pause between `mark_running` and scheduling leaves a pending producer for a paused job. It returns at once and is harmless.

## P8 — The revert's COALESCE deviates from AD-7 and is not listed as a spec call [LOW]

AD-7: "A Resume that fails restores the job's previous `pause_reason` and `last_error`." With `COALESCE(last_error, previous)`, a death recorded between `mark_running` and the revert wins. That is sensible, but it is a deviation from AD-7.

**Fix:** list it under Design Notes "calls made by the spec".

---

# 4. Poison guard

## G1 — A replaced source never resets the count of a poison file [MEDIUM]

**Where:** spec, "The planner resets `attempts` when a variant reports `source_changed`". Code: `VariantPlanner::reconcile()` l.262-264 evaluates `source_changed()` only for `done` and `skipped` rows. The death CAS at `attempts >= 3` turns every `processing` and `pending` variant into `failed`.

**Wrong outcome.** A poison file that never produced a `done` variant (the typical case) has only `failed` rows. If the source is replaced in place under the same path (the usual "replace media" flow), `source_changed` is never computed and the count never resets. That breaks AD-10's "a replaced source file … resets the count".

Also, `$retry` (l.97) is computed before any reset. Even when a reset happens, the `failed` rows of that same plan are not retried.

**Proposed spec wording:**

> - **Every row is checked for a replaced source.** `reconcile()` evaluates `source_changed` for `failed` rows as well.
> - **The reset happens before the retry decision.** When any row reports it, the planner resets `attempts` first and then computes the retry decision, so the same plan resets the `failed` rows to `pending`.

## G2 — The queue claim accepts `failed/max_attempts` rows [LOW]

`claim()` today accepts any non-`processing` state (l.248). A stray pending action reruns a poison file and counts a fourth attempt.

**Fix:** "A queue claim also requires `attempts < MAX_ATTEMPTS`; a sync claim does not."

## G3 — The frozen text omits `requeue()` from "never takes over a `processing` row" [LOW]

Today `requeue()` (l.383-396) takes over `processing` by age. The Code Map fixes it, but the Always block names only `claim()` and `mark_queued()`.

**Fix:** add `requeue()` to the frozen sentence.

---

# 5. Spine and owner-decision conformance

| # | Item | Severity |
|---|---|---|
| C1 | "Schema 1.2.0 adds **four** attachments columns" but only three are listed. The Code Map says "five columns" (3 + 2). A developer may invent a fourth column. | low |
| C2 | Memlog 29 (stamp-token release) is not carried or superseded (R2). | medium |
| C3 | The Code Map has `JobHealth` "used by … `RestController` and `Command`". AD-9 allows step 1 only from admin page loads, Resume and Maintenance. **Fix:** "REST and WP-CLI status read only the signals and the held list; they never run step 1 or the stale step." | low-medium |
| C4 | AD-10: "Only the attempt or the death CAS leaves `processing`". The planner's `reset` upsert (`VariantPlanner` l.104-117, on a replaced source *path*) sets a `processing` variant back to `pending` while an attempt holds it. T's `done` then misses into `row_gone`, and `cleanup_replaced_files()` can delete under its rename. **Fix:** "The planner never changes a `processing` variant row; it re-plans it on the next call." | medium |
| C5 | AD-7 COALESCE deviation (P8). | low |
| C6 | Texts: `admin-page.php:150` and `Command.php:388` are shared between Pause and Cancel. Only Pause becomes "parked until Resume", while Cancel still processes queued attachments. **Fix:** the spec says so. | low |

---

# 6. Other gaps

- **O1 — Readers need `has_schema()` too [low].** The held finder, step 1, the signals and `JobProgress`'s parked count read the new columns. On a 1.1.0 site those reads fail silently, because `$wpdb` returns empty. **Fix:** "Readers of 1.2.0 columns check `has_schema('1.2.0')` and return nothing."
- **O2 — Schema-pending reschedule load [low].** On a site whose migration keeps failing, every queued attachment re-enqueues every 300 s, forever. With 200 pending, that is about 58k completed AS rows a day, kept for 30 days. Consider rescheduling only the action that ran and letting the others wait, or backing off. This is an owner call, because the 300 s is listed as a spec decision.
- **O3 — Step 1 cost on admin loads [low].** In the target scenario (no cron, stuck in-progress actions), every admin page load re-checks the same ≤ 20 oldest candidates, with up to 20 AS queries per load. When more than 20 such candidates exist, newer dead ones starve. **Fix:** order by `updated_at` but skip rows re-checked in the last 60 s, or name the cost as accepted.
- **O4 — Object cache [low].** `VariantRepository::load()` caches rows per attachment (l.492-526). Only `transition()`, `upsert()` and `delete*()` invalidate the cache. The death CAS and release update variants in bulk. **Fix:** "they invalidate the attachment's variants cache". The closing write's state derivation should read the rows uncached, or right after an invalidation.
- **O5 — Shared targets (WPML or Polylang duplicates) are not covered by the per-attachment lease [low].** Two attachments' rows can own the same `relative_path` (`ImageCleanupService` l.296-301). Their attempts' leases do not exclude each other. **Fix:** list it as an accepted risk; the bytes are normally identical.
- **O6 — Accepted risks to state [low].** "Legacy rows present at upgrade block their bulk until WP-CLI release, and some hosts have no WP-CLI." "Ctrl-C on `wp trust-optimize sync --now` leaves a held sync attempt."
- **O7 — Occupied target found after the lease [low].** That outcome is missing from the error list. **Fix:** "Delete the temporary file, transition to `skipped` / `target_exists_foreign` (token-guarded), release the lease, and continue."
- **O8 — `resumed_at` is written only from `paused` [ok].** A newly launched job can be stopped once by the old test. That is consistent with AD-9, which says "before the job's last Resume". Note it in the matrix.

---

# Summary of proposed changes (by priority)

1. **(F1)** Heal orphan `processing` variants on every successful claim (token-guarded). No write other than `claim()` stores `processing`. The death CAS and release are one multi-table UPDATE plus a cache invalidation.
2. **(F2/R1)** The release key adds `publishing_at <=> observed`. Lease writes do not touch `updated_at`. Release refuses rows younger than 900 s, and queue rows with a live AS action.
3. **(F3)** After a failed lease release, no further publication: go straight to the closing write. Or make the lease re-entrant for the same token.
4. **(P1)** Skip the stale stop only while a producer action is pending or in-progress, or a signal is present.
5. **(F4)** Define the held lease reasons by the presence of `processing` variants and by the age of `publishing_at`.
6. **(F5/F6)** The closing write is one statement that clears the attempt record and never stores `processing`. Define the exception path.
7. **(G1, F7)** `source_changed` covers `failed` rows, with the reset before the retry decision. `not_smaller` on the temporary file keeps ownership; it is not part of the refactor commit.
8. **(P2, P3, P4, P6)** Revert only from `paused`, and the owner decides `launch()`. The parked set includes `deactivated`. Revert a failed schedule to parked. Re-read after the death CAS before scheduling.
