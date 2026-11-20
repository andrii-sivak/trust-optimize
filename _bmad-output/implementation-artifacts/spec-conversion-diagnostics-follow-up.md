---
title: 'Conversion diagnostics follow-up'
type: 'bugfix'
created: '2026-11-20'
status: 'done'
route: 'oneshot'
review_loop_iteration: 0
baseline_commit: 'a3ddff96af0344e4561441c4ca0ec48d98f8e168'
context:
  - '{project-root}/_bmad-output/specs/spec-conversion-diagnostics-follow-up/SPEC.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

Implement the approved [diagnostics follow-up](../specs/spec-conversion-diagnostics-follow-up/SPEC.md). When cgroup memory remains readable, start and result events retain the memory value and record a real integer zero or positive OOM count. If the OOM count cannot be read as a valid integer, record `cgroup_oom_kill` as `unavailable` and `cgroup_oom_reason` as `not_readable`. Diagnostic read failures preserve conversion outcomes.

Align the live diagnostics requirements, companions and acceptance criteria with AD-18 and preserve historical reviews. The option defaults to on, the checkbox stores string `'1'` or `'0'`, and a saved off survives updates. Off stops new measurements and records, keeps files, and permits periodic cleanup. Limits are 512 MiB per site, 8 MiB per file and up to 60 days. CI, queue, delivery and release work are outside scope.

</frozen-after-approval>

## Implementation Notes

- Approved scope has no open intent questions or irreversible operations.
- `includes/diagnostics/ConversionDiagnostics.php` supplies cgroup numbers to the shared start and result payload builder. Reuse `tests/Integration/ConversionDiagnosticsTest.php` for fixture-based regressions.
- Re-derive the current diagnostics kernel and companions under `_bmad-output/specs/spec-gh-26-conversion-diagnostics/` and align current requirements in `_bmad-output/implementation-artifacts/spec-gh-26-conversion-diagnostics.md`. Record the bug fix under Unreleased in `CHANGELOG.md`.
- Amelia implemented both tasks. The regression-only run reproduced eight failures against the baseline. The final tests cover initial and refreshed samples and the two recorded event types.
- The 60-day retention policy is implemented as a periodic age-pruning cutoff from the last write. A busy site can retain less, and deletion of eligible files waits for cleanup.
- PHPCS and the full local unit, integration, and multisite matrix passed. After the review patches, PHPCS and the diagnostics integration tests passed on PHP 8.0 with WordPress 7.0 and PHP 8.5 with WordPress 7.1.5. Each single-site diagnostics run reported 41 tests, 333 assertions, and one multisite skip. The focused multisite run passed one test with five assertions. PHP 8.5 reported a pre-existing reflection deprecation. The broad multisite run also logged missing-table messages during uninstall tests and exited successfully.

## Review Triage Log

- Medium, fixed. The initial fixtures seeded the cgroup cache and used deprecated reflection. Directory substitution now exercises initial discovery and refresh without reflecting on the cache. Reflection in the shared converter fixture only calls `setAccessible()` on PHP 8.0.
- Medium, fixed. Static samples did not detect stale result data. The new test changes both files during the conversion and checks the two independent records.
- Medium, fixed. An error handler that throws on a suppressed counter-file warning could drop the memory sample. The reader catches the counter-file failure and the fixture verifies the handler was called for both events.
- Low, fixed. The missing-events fixture is named for the missing file. A separate fixture exercises a failed read.
- Low, fixed. The settings regression now saves the separate option off before updating the other settings.
- Low, fixed. Public documentation and current requirements describe periodic age pruning. Storage describes closed daily files as eligible for eviction. The event field inventory includes the partial sample, and the companion records compatibility with older integer zeroes.
- Low, accepted. The new marker can add up to 47 bytes. The existing 1280-byte limit still skips the complete oversized line, as specified by the storage companion.
- Low, existing. The admin description uses the older unit label. The scope covers the specifications and documentation, while the numeric limit and settings UI remain as implemented.
- Low, additional coverage. The fixtures verify the successful return, completed row, initial samples, and an independently refreshed result. The unchanged whole-cgroup fallback and settings registration retain their existing tests. Extra output-hash comparisons, an inverse refresh fixture, and a full browser form submission are beyond the regressions found.
