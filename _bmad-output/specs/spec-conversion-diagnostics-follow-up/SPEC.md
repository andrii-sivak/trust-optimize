---
id: SPEC-conversion-diagnostics-follow-up
companions: []
sources: []
---

# Conversion diagnostics follow-up

## Why

Operators rely on conversion logs to distinguish a valid zero OOM-kill count from a failed measurement. Current diagnostics requirements and acceptance checks also need to agree with the default-on behavior already captured by AD-18.

## Capabilities

- **CAP-1**
  - **Intent.** Preserve a readable cgroup memory sample when the OOM-kill counter is unavailable.
  - **Success.** On start and result events, a readable `memory.current` remains an integer. An actual `oom_kill 0` stays integer zero and a positive counter stays an integer. If `memory.events` is unavailable or contains no valid non-negative counter, `cgroup_oom_kill` is `"unavailable"` with `cgroup_oom_reason` set to `"not_readable"`. Conversion completes with the same outcome if a diagnostic read fails.
- **CAP-2**
  - **Intent.** Bring the current diagnostics specification, events and storage companions, and implementation acceptance criteria into agreement with AD-18.
  - **Success.** Diagnostics is on with no option row and with the string `'1'`. The existing checkbox stores `'1'` or `'0'` in `trust_optimize_diagnostics`. An explicit `'0'` survives updates and stops diagnostic measurements and new records. Existing files stay and periodic age cleanup continues. The limits are 512 MiB per site, 8 MiB per file and up to 60 days from the last write. Historical review text is preserved.

## Constraints

- AD-18 governs behavior. An unavailable `memory.current` still uses the existing whole-cgroup marker. Use deterministic fixtures that do not depend on the host's cgroup state.
- The branch starts at the fetched `origin/main`. Owner approval precedes implementation. Amelia implements via `bmad-build` as a GPT-6.1 Sol subagent with medium effort. Run the repository checks and review the actual diff.

## Non-goals

- CI exit 127, queue changes, image delivery, release preparation, plugin version changes, push and merge.

## Success signal

Fixture cases verify integer zero, a positive count, an unreadable events file and a missing or malformed field. The start and result records retain the memory measurement and conversion outcomes are preserved. The live documents agree with AD-18.
