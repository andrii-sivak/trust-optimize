# Currency / reality-check review: spine amendment for #34 (AD-5, AD-8, AD-9, AD-10)

- Reviewed: uncommitted diff of `ARCHITECTURE-SPINE.md` dated 2026-11-18, including the Backpressure wording as it now stands ("one batch of parked and one batch of new attachments ... up to two batches").
- Code checked at HEAD `61e8212` on `fix/gh-34-progress-liveness`. The spine frontmatter still says `code at 0943f8c`, which is 30 commits older.
- Action Scheduler sources:
  - bundled `vendor/woocommerce/action-scheduler` **3.9.3** (`composer.lock:10-11`, `action-scheduler.php:8`). AS paths below are relative to that directory;
  - upstream `woocommerce/action-scheduler` **trunk** (header `Version: 4.2.0`), raw files fetched from GitHub on 2026-11-18: `classes/ActionScheduler_QueueRunner.php`, `classes/ActionScheduler_QueueCleaner.php`, `classes/abstracts/ActionScheduler_Abstract_QueueRunner.php`, `classes/data-stores/ActionScheduler_DBStore.php`, `functions.php`;
  - a site runs the newest AS that any active plugin registers (`ActionScheduler_Versions::initialize_latest_version`, `action-scheduler.php:34-47`). So every AS fact must hold in 3.9.3 and in 4.x.
- Encoder sources: https://www.php.net/manual/en/imagick.setprogressmonitor.php, and ImageMagick `coders/webp.c` and `coders/heic.c` (main) and Imagick `imagick_class.c` (master), fetched raw from GitHub on 2026-11-18.

## Verdict

Every AS fact the amendment relies on holds in 3.9.3 and in 4.x, and every code fact holds at HEAD. Two sentences overstate:
- "matching the AD-10 recovery wait" holds only when the filter is at least 900 s;
- "no plugin code runs during an encode" is true today, but it is not a technical impossibility.

Both are wording fixes. No decision needs to change.

## Claim-by-claim

| # | Claim | Evidence | Status |
|---|---|---|---|
| 1 | `priority` argument: default 10, lower runs first | 3.9.3 `functions.php:15,19` (`as_enqueue_async_action`), `:65,69` (`as_schedule_single_action`), `:118,122` (`as_schedule_recurring_action`): "Lower values take precedence over higher values. Defaults to 10, with acceptable values falling in the range 0-255." Trunk `functions.php` has identical signatures and docblocks | **Holds** in both versions |
| 2 | Claims are taken by priority ascending, then by date and ID | 3.9.3 `classes/data-stores/ActionScheduler_DBStore.php:1000`: `'ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC'`, also used at `:1121`. Trunk has the same default string. The WHERE clause is `claim_id = 0 AND scheduled_date_gmt <= %s AND status=%s` (`:949`) | **Holds**, with the caveats in F3: `attempts` is the second key, only due actions compete, and the order can be filtered |
| 3 | AS fails an in-progress action after its failure period (300 s by default) without checking the worker | 3.9.3 `classes/ActionScheduler_QueueCleaner.php:206-226`: `mark_failures()` selects `in-progress` actions with `last_attempt_gmt <= now - timeout` (`DBStore.php:550-555`) and calls `mark_failure()` (`DBStore.php:1215-1236`). That is an unconditional UPDATE by `action_id`; nothing checks the process. The period is `10 * get_time_limit()` (`abstracts/ActionScheduler_Abstract_QueueRunner.php:241-243`), with a default time limit of 30 (`:268-280`), filtered through `action_scheduler_failure_period`. The start time is written by `log_execution()` (`DBStore.php:1253`). Trunk `mark_failures()` is the same, and run() computes `$cleanup_time_limit = 10 * $this->get_time_limit()` | **Holds** (see F4 for what changes the default) |
| 4 | That cleanup runs even when the concurrent-batch limit is reached | 3.9.3 `classes/ActionScheduler_QueueRunner.php:148-155`: `run_cleanup()` comes before `if ( false === $this->has_maximum_concurrent_batches() )`. WP-CLI: `classes/WP_CLI/ActionScheduler_WPCLI_QueueRunner.php:61-72`, `run_cleanup()` before the check. Trunk run(): `reset_timeouts()` and `mark_failures()` (or the full `clean()` for a custom cleaner) come before the concurrency check, while deletion moved to its own task. The async request runner is the exception: `allow()` refuses while the limit is reached (`classes/ActionScheduler_AsyncRequest_QueueRunner.php:76-78`), and dispatch also requires `is_admin()` (`QueueRunner.php:123-131`), so it never reaches `run()`. AD-5's "Dead claims" already says this | **Holds** for WP-Cron, system cron and WP-CLI `run()`. It does not run through the async runner while a claim is held. AD-5 already states that correctly |
| 5 | A live worker marked failed can still finish | `process_action()` calls `mark_complete()` after `execute()` (`Abstract_QueueRunner.php:105-107`), and `mark_complete()` (`DBStore.php:1279-1300`, same in trunk) updates by `action_id` alone. So AS status can go `failed` → `complete`. 4.x `mark_failure()` also rewrites `last_attempt_gmt` (already noted in review-currency-30 F2) | **Consistent** with AD-10: a recovery never relies on the worker being gone, and publication is guarded separately |
| 6 | No PHP userland code runs during a blocking Imagick/GD encode | See F2. Ticks fire between userland statements only. With `pcntl_async_signals`, signal handlers run at VM interrupt checks after the internal call returns, and FPM rarely has pcntl. Shutdown functions run at request end only. A `max_execution_time` hard timeout terminates the process without userland code. GD (`imagewebp`/`imageavif`) has no callback. **However**, `Imagick::setProgressMonitor()` (imagick ≥ 3.3.0) calls a PHP callable during processing (`imagick_class.c` → `MagickSetImageProgressMonitor(..., php_imagick_progress_monitor_callable, ...)`). ImageMagick's WebP coder forwards libwebp encode progress through `picture->progress_hook = WebPEncodeProgress` → `SetImageProgress(image, EncodeImageTag, ...)` (`coders/webp.c:769-780, 835`). The HEIC/AVIF coder reports progress only while it copies rows before the libheif encode, not during the AV1 encode | **Holds for the plugin as built**: it encodes only through `wp_get_image_editor()` → `WP_Image_Editor::save()` (`includes/features/optimization/ImageConverter.php:298`, `includes/files/AtomicImageWriter.php:54`) and registers no progress monitor, ticks, signals or shutdown hook (grep over `includes/` finds none). **Overstated as a general claim**: see F2 |
| 7 | Only `claim()` stores attachment state `processing` | `includes/storage/AttachmentRepository.php:302-331` (`claim`, SET `a.state = PROCESSING`). Every other state write was checked. `finish` `:251` takes a final state and the docblock says it is never processing. `mark_queued` `:344` and `requeue` `:482` write QUEUED. `set_state` `:222` and `write_state` `:881` both have `AND state <> PROCESSING`. `end_attempt` `:818` writes FAILED, NONE or QUEUED. `suspend_unfinished` `:423/:436` and `park_where` `:776` write their own states. `recompute()` returns `get_state()` without writing when `derive_state()` yields processing (`:279-281`). `take_lease` `:673` writes only `publishing_at`, so a processing row's `updated_at` stays the claim time | **Holds** |
| 8 | `recompute()` / `set_state()` rewrite state, reason and `updated_at` outside any worker | `set_state` `:222` writes state, reason, last_error and updated_at. `recompute` → `write_state` `:881` writes state, `reason = NULL`, `last_error = NULL` and updated_at, even when the state is unchanged. Callers outside a worker: `VariantPlanner.php:96,145-146`, `ConversionQueue.php:123`, `ImageCleanupService.php:100`, `BulkProducer.php:436` | **Holds**. `recompute` clears the reason rather than rewriting it, which is why `failed`/`max_attempts` becomes `failed` with a NULL reason, as the spec notes |
| 9 | The variant writers behind "attempt evidence" | Planner upserts write only `pending` (`includes/planning/VariantPlanner.php:115-130`, `VariantRepository::DEFAULTS` `:47`). Worker transitions are token-guarded (`VariantRepository::transition` `:163-200`; `ImageConverter.php:109,151-152,202-203,269`; `AttachmentProcessor.php:276` processing→pending at the end of the budget). `end_attempt` sets variants to pending or to failed/`max_attempts` (`AttachmentRepository.php:818-840`). Cleanup writes failed/`delete_failed` (`includes/service/ImageCleanupService.php:215,319`) | **Holds**: only a worker writes processing, done, skipped, or failed with another reason |
| 10 | The producer's backlog check is `pending > max_pending` | `includes/bulk/BulkProducer.php:516-533`: counts `trust_optimize_process_attachment` actions of group `trust-optimize` with AS status `pending`, `per_page => $max + 1`, then `> $max`. `max_pending()` `:394-396` defaults to 200 with a floor of 1. It runs only for TYPE_SYNC jobs (`:336-346`). After it, `queue_parked($limit)` and then up to `$limit` new IDs (`:349-360`) | **Holds**, and so does the current Backpressure line. See F5 for what "pending" includes |
| 11 | Conversions are scheduled at priority 10 today, except recovered and schema-retry at 20 | `ConversionQueue::schedule_or_park( $id, $priority = 10 )` `:251` and `schedule( ..., $priority = 10 )` `:262`. `enqueue` `:140` (hand-over, parked after Resume `BulkProducer.php:454`, restore `Lifecycle.php:109`), `requeue` `:152` and the `more` continuation `:201` are at 10. `schedule_recovered` `:168` and the schema retry `:182` use `PRIORITY_RESUMED = 20` (`:42`). Service actions pass no priority, so they get 10: producer `BulkProducer.php:567,570`, Maintenance `Maintenance.php:78`, Restore `Lifecycle.php:95,115` | **Holds**. The AD-5 rule ("every conversion at 20") is the target that #34 implements, and the spec lists every path (`spec-gh-34-progress-liveness.md:70-77`) |
| 12 | Recovery wait = `max( STALE_CLAIM_SECONDS, filter )` | `STALE_CLAIM_SECONDS = 900` (`AttachmentRepository.php:30`). Filter default `15 * MINUTE_IN_SECONDS`, clamped to at least 60 (`includes/bulk/BulkJobRepository.php:621-625`). Today `JobHealth.php:165` passes STALE_CLAIM_SECONDS alone. The spec changes that (`spec-gh-34-progress-liveness.md:58`) | A new rule, consistent with the spec. See F1 for the AD-8 wording |
| 13 | `MAX_ATTEMPTS` claim guard | `MAX_ATTEMPTS = 3` (`AttachmentRepository.php:35`). Today a queue `claim()` has no attempts condition (`:312`) | A new rule. The current gap is real, which confirms the spec's "Poison bypass" |

## Findings

### F1 (Medium): "matching the AD-10 recovery wait" is false for a filter below 900 s

- **Claim (AD-8, Slow attempt):** "An attempt counts from its claim until the threshold expires, matching the AD-10 recovery wait."
- **Evidence:**
  - AD-8 counts a claim for `trust_optimize_bulk_stale_after_seconds`;
  - AD-10 waits `max( 900, filter )`;
  - the filter is accepted down to 60 s (`BulkJobRepository.php:624`).

  With a filter of 300, a single 400 s encode has no evidence after 300 s, so the job is stopped (if nothing else moved). Recovery would not touch the attempt until 900 s. The two windows are equal only when the filter is at least 900, which includes the default.
- **Fix:** Either say "equal to the AD-10 recovery wait at the default and above; below 900 s the job may stop while its attempt is still protected from recovery", or let claim evidence count for the recovery wait (`max( 900, threshold )`) so the two stay equal. Which one is the owner's choice; the second changes behavior only for filters below 900.

### F2 (Low): "no plugin code runs during an encode" is a property of the current design, not a technical limit

- **Claim (AD-8, Slow attempt):** "no liveness write can tell it apart, because no plugin code runs during an encode."
- **Evidence:**
  - True today. The plugin encodes through `wp_get_image_editor()` and `save()` (`ImageConverter.php:298`, `AtomicImageWriter.php:54`) and registers no callback.
  - GD has no callback.
  - libheif's AV1 encode reports nothing to ImageMagick.
  - Ticks, async signals and shutdown functions cannot run in the middle of an internal call.
  - But Imagick can run a PHP callable during processing: `Imagick::setProgressMonitor()` (php.net, imagick ≥ 3.3.0). ImageMagick's WebP coder reports progress during the libwebp encode (`coders/webp.c:769-780,835`). A `wp_image_editors` subclass of `WP_Image_Editor_Imagick` could therefore write a heartbeat during a WebP encode.
- **Fix:** Reword to the decision rather than to an impossibility, for example: "A single encode longer than that looks like no progress. AD-8 makes no liveness write, and none could cover every encoder: GD and the AVIF encode offer no callback, and Imagick's progress monitor sits outside WordPress's editor API."

### F3 (Low): priority semantics have three caveats worth one clause in AD-5

- **Claim (AD-5, Priorities):** priority 10 for service actions and 20 for conversions puts the producer ahead of its conversions.
- **Evidence:** Verified in 3.9.3 (`DBStore.php:949,1000`), and identical in trunk. But:
  1. Priority applies only among **due** actions (`scheduled_date_gmt <= now`). A producer delayed by `wait()` by 30 s is not ahead until it is due.
  2. Priority applies at **claim** time. A batch already claimed (25 by default, `action_scheduler_queue_runner_batch_size`) keeps running. The runner checks its 30 s time limit after each action (`QueueRunner.php:191`), so a due producer waits at most about 30 s plus one encode.
  3. The order string can be filtered by any plugin (`action_scheduler_claim_actions_order_by`, since 3.4.0), and `attempts ASC` sits between priority and date. That key is immaterial for unstarted actions.

  Also, priority 20 puts every TrustOptimize conversion behind every other plugin's default-priority actions that share the runner (WooCommerce and others). The current "Accepted" line names only new uploads waiting behind the bulk backlog.
- **Fix:** Add "Priority orders due, unclaimed actions only; conversions also yield to other plugins' default-priority actions (accepted)." The AD-8 "waiting behind higher-priority actions" clause already covers the liveness side.

### F4 (Low): the 300 s failure period is derived, and hosts change it

- **Claim (AD-10):** "AS fails an action that has been running longer than its own failure period (300 s by default)".
- **Evidence:**
  - The period is `10 * action_scheduler_queue_runner_time_limit` (default 30, `Abstract_QueueRunner.php:242,268-280`), then `action_scheduler_failure_period`.
  - A host or plugin that raises the runner time limit to 91 s or more pushes the failure period past 900 s. A truly dead worker then stays `in-progress` in AS longer than the recovery wait, so recovery waits for AS as well. That is safe, because the "no pending or in-progress" condition handles it, but the effective wait becomes `max( recovery wait, AS failure period )`.
  - `mark_failures()` handles at most `action_scheduler_cleanup_batch_size` (default 20, `QueueCleaner.php:186`) actions per run.
- **Fix:** Optional. Add "(10 × the runner time limit; AS filters can raise it, and recovery then also waits for it)". It also belongs as a test note for the #34 spec.

### F5 (Info): "pending" in the backlog check is AS `pending`

- **Evidence:** `BulkProducer.php:521-533` counts AS `pending`. That includes:
  - future-dated actions: schema retries one hour ahead (`ConversionQueue.php:182`) and AD-11 deferred retries later;
  - conversions from uploads and other jobs.

  It excludes `in-progress` actions. The spine's "more than `trust_optimize_bulk_max_pending` conversions are pending" is accurate. A large set of delayed retries can hold the producer in `wait()` while nothing is due. That is not a liveness problem, because `wait()` is a producer step.
- **Fix:** None needed now. #26 (AD-11) should decide whether delayed retries count toward the backlog.

### F6 (Info): an attempt that ends without a variant transition leaves no evidence after it ends

- **Evidence:** Claim evidence is visible only while the row is `processing`. `finish()` (`AttachmentRepository.php:251`) replaces it with a final state, which is not evidence. Paths that end without a variant transition exist: `AttachmentProcessor.php:296` (an exception before any variant), and `:361` when no pending variant was left.
- **Impact:** Negligible. Such attempts are short, and the producer's steps (cursor, `wait()`) keep a job alive. This note is here so the spec's scenario matrix does not assume every finished attempt leaves a trace.
- **Fix:** None to the spine. The spec may add a scenario in which a run of attempts fails with an exception while the producer waits.

### F7 (Info): frontmatter source commit

- `sources` and `scope` still say `code at 0943f8c`. The #34 facts were checked at `61e8212`.
- **Fix:** Add `'code at 61e8212 for #34'` to `sources`.
