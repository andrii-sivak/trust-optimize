- source_spec: `_bmad-output/implementation-artifacts/spec-gh-12-bulk-job-utc-timestamps.md`
  summary: The local integration suite leaks a committed pending `trust_optimize_restore_queue` action into the test database on every run, so the next run fails `SiteHealthTest` (3 tests) and `LifecycleTest::test_work_queued_before_a_deactivation_is_finished_after_the_next_activation` once that action is overdue.
  evidence: Reproduced on `main` (71291d6) and on this branch; after deleting the leftover row the suite passes (236 tests), and the same run leaves a new pending `trust_optimize_restore_queue` row behind. The test database also holds about 2,200 committed Action Scheduler rows since 2026-10-03. Likely cause: a test that runs DDL (dbDelta) implicitly commits the transaction after the action is scheduled. CI is unaffected because it starts from an empty database.
- source_spec: `_bmad-output/implementation-artifacts/spec-raise-minimums.md`
  summary: Nothing checks that `Requires at least` and `Requires PHP` agree between `trust-optimize.php` and `readme.txt` (and that the lowest CI row tests that minimum); `scripts/build-release.sh` checks only `Version`, `TRUST_OPTIMIZE_VERSION` and `Stable tag`.
  evidence: Pre-existing gap found by the verification-gap review of the minimums change: a partial bump of one header would pass phpcs, all tests and the release build. A few lines next to the `Stable tag` check in `scripts/build-release.sh` would close it.
- source_spec: `_bmad-output/implementation-artifacts/spec-jobs-pause-reason-schema.md`
  summary: `DatabaseManager::check_version()` stores the schema version without confirming that `dbDelta()` created the attachments and variants tables, so a failed create on a fresh install is marked current and never retried.
  evidence: Pre-existing in 1.0.0 (version stored right after `create_tables()`); the 1.1.0 migration confirms only `jobs.pause_reason` via `SHOW COLUMNS`. Found by the edge-case review of the pause_reason schema change.
- source_spec: `_bmad-output/implementation-artifacts/spec-gh-30-dead-worker-recovery.md`
  summary: A live queue worker that spends more than 15 minutes on one variant is recovered as dead once Action Scheduler has failed its action (after its 300 s failure period), and after three such recoveries a slow but valid file ends `failed` / `max_attempts`.
  evidence: Death candidates are aged by the attachment's `updated_at`, which only `claim()` writes; AD-8 forbids liveness writes on the conversion path, and the pre-#30 code took a claim over after the same 900 s. Settling it needs an owner decision (a bounded per-variant budget below 900 s, a spine amendment allowing an attempt heartbeat, or the #26 slot lease). Found by the blind-hunter and edge-case reviews of #30.
- source_spec: `_bmad-output/implementation-artifacts/spec-gh-30-dead-worker-recovery.md`
  summary: `wp trust-optimize sync --now` (schema-pending stop and the busy warning) and the `queue_signals` column of `wp trust-optimize status` have no tests.
  evidence: Both go through `\WP_CLI\Utils\make_progress_bar` or `format_items`, which the test WP_CLI stub does not provide; REST covers the shared `JobHealth::queue_signals()` and `AttachmentProcessor::sync()` paths. Found by the verification-gap review of #30.

## Deferred from: code review of spec-gh-34-progress-liveness.md (2026-11-18)

- Cost of the attempt-evidence query (`BulkJobRepository::attempt_evidence()`) for a silent large job: it reads every attachment row of the job through `job_state` to check `updated_at`. Same shape as `has_progress_since_resume()` before #34; runs only while the producer is silent past the threshold. Measure with EXPLAIN on the stand before deciding on a `(job_id, updated_at)` index (schema change).
- `AttachmentProcessor::convert_pending()` maps a derived `processing` state to `queued` + `more` and its closing write resets attempts, so an orphan reset that keeps failing reschedules forever without tripping the poison guard. Pre-existing; needs repeated DB errors.

- source_spec: `_bmad-output/implementation-artifacts/spec-gh-19-unconfirmed-file.md`
  summary: A `done` variant is served from `relative_path` alone. `VariantRepository::servable_path()` does not require `file_hash`, so a done row that never recorded a hash is still delivered until cleanup drops the row.
  evidence: Pre-existing AD-14 rule (`done` plus a path). The #19 cleanup change does not touch delivery. Found by the blind review of the #19 diff.
