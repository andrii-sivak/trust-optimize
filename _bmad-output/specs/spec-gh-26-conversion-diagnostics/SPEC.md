---
id: SPEC-gh-26-conversion-diagnostics
companions:
  - events.md
  - storage.md
  - ../../planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md
sources: []
---

# Opt-in conversion diagnostics (#26 slice)

## Why

A pain for the operator of a small host, and the measurement the later resource work needs. Bulk conversion can kill a PHP worker inside the encoder and leave nothing: Action Scheduler records only that the action started, and `debug.log` stays empty (#31). The memory check that runs today uses PHP's allocator and a flat bytes-per-pixel guess, so it cannot see the native encoder or the container limit (#32). Adaptive pace, the resource gate, and a new memory estimate are not this change. This change records what a real conversion did, on the site, so a dead worker and a finished one can be told apart after the fact.

## Capabilities

- **CAP-1**
  - **intent:** An operator turns conversion diagnostics on with an explicit configuration flag. Off is the default, and off does no diagnostic-only work.
  - **success:** With the option row absent, a conversion writes a diagnostics line. `get_option` returning the string `1` does too. The stored string `0` writes nothing and takes no cgroup or CPU sample, and age cleanup still deletes a file older than 60 days. The settings screen shows "Keep diagnostic logs" checked when the row is absent and unchecked when the stored value is `0`. Saving the form stores `'1'` or `'0'` on `trust_optimize_diagnostics` and does not put that key in `trust_optimize_options`. A stored `0` is still `0` after the main settings are saved again.
- **CAP-2**
  - **intent:** While diagnostics are on, a variant conversion leaves a start line flushed before the image editor opens, and a result line after the attempt finishes, and both lines carry the same `conversion_id`.
  - **success:** A test editor's load finds the start line already in the file, including attachment, size, format, `conversion_id`, and the pre-editor memory fields. The result line repeats that `conversion_id` and records the status and reason this `convert()` call returned. Where this attempt's transition completed (`done`, `not_smaller`, and a `fail()` that updated the row), those two fields match the row. For `not_pending`, `attempt_lost`, and `row_gone` they record the return and are not required to match the row. The diagnostics writer does not update the variant row or the attachment row. The id is absent from both rows, every option, and every transient. A second attempt of the same attachment after the count resets uses a different id. Stopping inside the editor leaves that start line and no result line for that id.
- **CAP-3**
  - **intent:** The process environment is one event at the start of a job and whenever it changes, and variant lines do not repeat it.
  - **success:** In one process, three variants of one job with an unchanged fingerprint produce one `environment` line. Changing `avif_quality` writes a second `environment` line before the next variant line. Variant lines carry the environment hash and not the version block. Another process may write the same environment again; the writer does not read the file to suppress that.
- **CAP-4**
  - **intent:** A diagnostics failure, including a lock that is not acquired, does not interrupt a conversion and does not change its outcome.
  - **success:** With the diagnostics directory not writable, or with the write lock already held, the variant status, reason, and output bytes match the same conversion with diagnostics off. The held-lock case returns without waiting and writes no line. No exception from the writer escapes the converter.
- **CAP-5**
  - **intent:** Each site keeps its own diagnostics under `uploads/to-logs`, bounded by 60 days and by the size caps, and can drop them without Action Scheduler.
  - **success:** A line written for site B is not in site A's `to-logs`. Age cleanup deletes a JSONL file older than 60 days and does not schedule an Action Scheduler action. Uninstall with "Remove data on uninstall" deletes that site's `to-logs` only on the request that also drops that site's options, and the directory goes first. An uninstall that stops early and keeps the tables keeps `to-logs`. The settings screen, REST responses, and WP-CLI output contain no URL of a log file. The directory contains the deny files. The test does not claim the URL returns an error.
- **CAP-6**
  - **intent:** A recorded figure names what it measures, and a figure that could not be read is marked unavailable. A line that was not written establishes nothing.
  - **success:** When the cgroup files cannot be read, the start line has `cgroup` of `unavailable` and does not copy `php_bytes` into a cgroup field. The PHP memory scope string says the PHP allocator. No attachment or variant row gains a cause because a result line or a start line is missing, or because a write was skipped.

## Constraints

- AD-18 binds this spec. Directory is that site's `uploads/to-logs`, with the usual deny files and no secret directory name. The plugin never emits a URL and does not guarantee the files are unreachable. Retention is 60 days. Starting caps are 512 MiB per site and 8 MiB per file, and both are maxima, so a busy site keeps less than 60 days. Pace-change lines and gate-decision lines are not written.
- The flag is the option `trust_optimize_diagnostics`, its own row. Recording is on when the row is absent and when `get_option` returns the string `1`. The stored string `0` is off and is not deleted by a plugin update or by saving the other settings. The existing settings screen has the checkbox "Keep diagnostic logs", checked by default. The form posts a hidden `0` and the checkbox `1`, and stores only those strings. The key is not part of `trust_optimize_options`. While off, no new diagnostic measurement and no new event are written. Existing files stay, and periodic age cleanup still runs. No new admin screen, no clear-logs control, no new WP-CLI command, no REST field, and no new hook. On a multisite the option is per site.
- One writer object is constructed in `Plugin` and passed into the converter and the attachment processor. It is not reached through a static call.
- `variant_result` records the status and reason returned by this `convert()` call. It is not a later read of the row. Match the row only when this attempt completed the transition: `done`, `not_smaller`, and a `fail()` that updated the row. `not_pending`, `attempt_lost`, and `row_gone` are logged as returned and are not compared to the row. The diagnostics writer performs no row write of its own.
- A start line is written only after the source file is known to exist, and it is flushed before the editor is opened. Editor class, reported quality, durations, and output size are on the result line. A failure before the editor, including a missing source and `not_pending`, is a result with no start. `attempt_lost` and `row_gone` can occur after the start line. `conversion_id` is 32 hex characters from a fresh `random_bytes( 16 )` for that variant attempt. It is not a hash of the other fields, and a later attempt of the same attachment draws a new one. The start line and the result line carry it. When a gate exists, its line for that attempt carries it too. It is written only on those lines, never in a table, an option, or a transient, and it is not compared to the attempt token.
- An attachment the planner skips without opening an editor produces one `attachment_skip` line, not a start. The line uses the reason and the dimensions that decision already computed. The log does not decode a file and does not stat a file the planner did not already stat.
- The attempt in the log is the integer `attempts` count and `attempt_source` (`queue` or `sync`). The attempt token is never written. `job_id` comes from the attachment row once per attachment, and is null outside a bulk. No extra query per variant.
- The log stores the agreed fields in `events.md` and nothing else. Reason codes stay. There is no message field and no free-text error. File names, paths, URLs, file bytes, secrets, and user data are not written. Size names and numeric ids are written.
- The environment event carries PHP, SAPI, WordPress, GD, Imagick and ImageMagick versions, `memory_limit`, `max_execution_time`, the editor class list, cgroup limit files when readable, and the numeric format settings. ImageMagick's version string is stored with URLs removed. Imagick resource limits are read only when that read does not decode an image.
- CPU time is `getrusage(RUSAGE_SELF)`, split around editor load and encode. Wall time is `hrtime` around those two calls. PHP memory is `memory_get_usage(true)` and `memory_get_peak_usage(true)`. Cgroup `memory.current` and `oom_kill` are read at start and at result when the process cgroup is readable. Each of these names its scope, as `events.md` specifies. `sys_getloadavg`, PSI, and throttle counters are not read.
- A phase that did not run is JSON `null`. A measurement that was attempted and could not be read is the string `unavailable` plus a reason. Zero is not used for either case.
- Writer time counts inside the worker deadline. The deadline is not moved forward to pay the write back. A run may therefore finish fewer variants than the same run with the flag off. The lock try does not wait. The lock is not held across the editor or the encoder. A recording error does not enter the worker's exception path.
- The directory, rotation, the 1280-byte line cap, locking, the per-write size check, periodic age cleanup, and uninstall are `storage.md`. A line that would exceed 1280 bytes is not written. Required fields are not stripped to make it fit.
- While the flag is off, periodic age cleanup may still delete old files on an admin load. It does not sample CPU, PHP memory, or the cgroup for a record, and it does not append an event.
- No schema change. `readme.txt` documents the checkbox, the default on, the stored `0`, `uploads/to-logs`, 60 days, and 512 MB. The settings list includes the checkbox. Comments do not cite an issue or this spec.

## Non-goals

- Adaptive pace, AIMD, a duty cycle, and the background-intensity control.
- The AD-11 resource gate: start, park, defer, skip, the one-at-a-time slot, and any new memory estimate. `ImageLimits` coefficients stay as they are.
- AVIF encoder speed, pinning Imagick to one thread, and skipping a format because it is larger than another.
- A clear-logs button, a new admin screen, per-batch metrics in the bulk UI, and a WP-CLI or REST surface for the log.
- A secret or unguessable directory, and any promise that the log URL cannot be fetched.
- Extending the worker deadline to hide the cost of a write.
- Writing the in-flight step, `conversion_id`, or a cause inferred from a missing line onto a variant row, an attachment row, or `last_error`.
- Logging queue polls, producer ticks, each editor fallback inside one conversion, or the converter's error sentence.
- A watcher in another process, an external collector, host load averages, PSI, and `fsync` of every line.
- Proving from these files that the kernel OOM-killed a worker.

## Success signal

On a site with `trust_optimize_diagnostics` set to `1`, a conversion that dies inside the editor leaves a start line with the attachment, size, format, `conversion_id`, and the pre-editor figures, and no result line with that id. A conversion that finishes leaves a result line with the same id whose status matches the variant row, plus one environment line for that job rather than one per variant. With the flag off, the same conversion writes no diagnostics file and makes no cgroup or CPU sample. A busy write lock or an unwritable directory leaves the variant outcome unchanged.

## Assumptions

- Imagick memory and thread limits are included on the environment event only when the read does not decode an image. If the read fails, the event says `imagick_limits` is `unavailable`.
