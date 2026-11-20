# Adversarial review: spine amendment for diagnostics (AD-18)

- **Reviewed:** AD-18 as amended 2026-11-20 in `ARCHITECTURE-SPINE.md` (sources line, the rule, Deferred "Location and file mechanism"), read against AD-4, AD-5, AD-8, AD-10, AD-11, AD-15, AD-17, and the Consistency Conventions row for errors.
- **Code read:** `includes/processing/AttachmentProcessor.php` (`convert_claimed`, `convert_pending`), `includes/queue/ConversionQueue.php` (`process`), `includes/storage/AttachmentRepository.php` (`finish`), `includes/storage/VariantRepository.php` (`transition` token guard), `includes/features/optimization/ImageConverter.php` (`fail` message), `includes/bulk/BulkJobRepository.php` (`update`, `merge_snapshot`), `uninstall.php`.
- **Method:** for each hole, two units one level down that each follow every AD as written, and the way they still build incompatibly. The owner decisions (opt-in JSONL, 60 days, a non-AS cleanup path, no diagnostic-only work while off, AD-11 not keyed off the switch, a recording failure must not redirect a conversion, a field names its scope, a missing result line is not a cause, pace and gate lines only when those features exist, private files, no event stream in the database) are treated as fixed. The holes are places where those sentences still allow two builds.

## Verdict

**fail**

The decisions are the right ones and should stand. The amended sentences do not yet force one build. F1–F4 are wording fixes: each pair below can cite the current letter and still disagree on the record, on the conversion, or on retention. F5 is the one point that needs the owner. Nothing here reopens 60 days, opt-in recording, or "AD-10 does not read the log."

## Findings

### F1 — The required "start" line is also the gate line a release must not invent

- **Severity:** high
- **Disposition:** autofix

**Two units.**

- **A, processing recorder, this release (no gate, no pace).** AD-18 says a release that does not contain the gate does not invent gate-decision lines, and that pace-change lines are written only by the pace. A writes a conversion start line that is not a gate decision, plus a result line, plus environment. It has to: the same bullet says the start record is written separately so a killed worker leaves a trace, and this release still converts.
- **B, a later reader, and a writer that follows the bullet list literally.** The only "before" record the What list names is "the gate decision before a conversion". B treats a start line that does not carry a gate answer as an invented gate line, which the next sentence forbids. B's writer therefore records only a result, after the call. A kill during the encode leaves no line. B's reader, pointed at A's files, finds start lines with no `decision` field and either drops them or fills `decision: start` because every conversion that ran was, in this release, allowed to start.

**Shared shape.** Nothing says which keys join a start line to its result once the gate exists and adds a third line. A correlates on `(attachment id, attempt count, attempt source, size, format)`. B correlates on the attempt token, which A will not write (F5) and which B calls an id because the privacy rule allows ids. The same conversion becomes two unrelated lines. AD-10's attempt identity stays on the row; the file has no required subset of it.

**Why this is the spine's job.** The #26 spec is told both "do not invent gate lines" and "the start record is written separately." It cannot satisfy both readings. Location can stay deferred; the line kinds cannot.

**Proposed wording, replacing the What bullets:**

> - **What.** Opt-in JSONL per site. Every recorded conversion writes two lines of its own, whether or not a gate exists: a start line flushed before the heavy call, and a result line after the call returns. The start line is how a killed worker leaves a trace. It is not a gate decision and it does not say why the process stopped.
> - The gate, only when that feature exists, appends its own line for the answer it actually returned (`start`, `park`, `defer`, or `skip`, with that answer's reason). The pace, only when that feature exists, appends pace-change lines. A release without the feature writes neither, and writes no placeholder.
> - Environment and settings are recorded at job start and when they change, not on every variant.
> - One writer appends every line for a site. The gate and the pace pass it their lines; they do not open the file themselves. Lines that belong to one conversion share site, job id (null outside a bulk), attachment id, attempt source, attempt count, and the variant's size and format. They do not share the attempt token.

### F2 — A recording failure, a slow success, or a lock still changes the conversion

- **Severity:** high
- **Disposition:** autofix

AD-18 says a resource decision does not depend on the switch, and that a thrown error while recording does not interrupt the conversion or change its decision. It never says what "decision" includes. Three pairs stay inside that letter.

**Pair 1 — the worker catch is a second owner of the outcome.**

- **A, logger called from the worker try.** `AttachmentProcessor::convert_claimed()` catches every `Throwable`, calls `finish(..., FAILED, 'exception', $throwable->getMessage())`, and rethrows (`AttachmentProcessor.php` around 316–319). The Consistency Conventions require that: exceptions in a worker mark the attachment failed and rethrow so Action Scheduler records the action. There is no gate in this release, so A reads "does not change its decision" as vacuously true. With recording on and the directory not writable, the attachment ends `failed` / `exception` and the AS action fails. With recording off, the same bytes are encoded.
- **B, logger that swallows.** The variant status, reason, and output bytes match the run with recording off. B reads the Conventions as covering conversion failures only, and AD-18 as the more specific rule.

A's rethrow also feeds AD-9: a `failed` AS action does not count as `complete`, so a stretch of recording errors is a "no progress" signal that recording-off does not produce. If instead the logger throws outside that try, `finish()` never runs, `claim()` has already incremented `attempts`, and the row stays `processing`. AD-10's death CAS then counts a recording failure as a worker death. Three of those poison the file (`attempts >= MAX_ATTEMPTS`). Both placements obey the sentences that apply to them. AD-10 is unchanged only if the logger cannot become that exception.

**Pair 2 — a successful write moves the time budget.**

- **A, recorder inside `ImageConverter::convert()`.** The budget is checked between variants, before `convert()` (`AttachmentProcessor.php` around 333 and 349–351), not during the write. On a multi-size attachment the writes land inside the 20 s budget. The next iteration sets `more`, `finish()` still resets `attempts` (normal `continue`, AD-10), and `ConversionQueue::process()` schedules another action (around 211–212). Recording on splits one attachment into several priority-20 actions. Recording off often finishes it in one. No gate answer changed, and nothing failed, so the isolation sentence does not apply.
- **B, recorder outside the budget.** Same lines, written after `finish()` or excluded from the deadline. Also legal: AD-18 does not say where the write sits.

The extra actions are real queue load. AD-5's producer waits while pending conversions exceed `trust_optimize_bulk_max_pending`. The switch does not change an AD-11 answer and still changes how far the producer runs ahead. That is a pace effect from a release that is forbidden to invent pace.

**Pair 3 — the file lock couples two conversions AD-11 says may overlap.**

- **A, background writer.** Holds an exclusive lock from the start line through the encode so the result is the next line. The file mechanism is deferred, and AD-18 does not forbid holding the lock.
- **B, synchronous writer.** AD-11 keeps sync outside the one-at-a-time slot, so it runs beside A, and sync "returns the result at once." B blocks on A's lock. When the wait outlives the request, the sync attempt stays `processing`. AD-10 never recovers a sync attempt by age. Recording has turned a sync conversion into a held attempt. Neither unit threw, and neither consulted the switch.

**Proposed wording, replacing the Isolation bullet:**

> - **Isolation.** Recording is opt-in. While it is off, code whose only purpose is to fill a diagnostic record does not run and does not write a file. A figure the gate needs for AD-11 is not that code: the gate measures it with the switch off or on, and recording only copies it.
> - The switch and the fate of a write do not change any of these: the AD-11 answer, the worker result (`finished`, `continue`, `defer`, `parked`), the variant status and reason, the attachment outcome, `attempts`, or whether an AS action fails.
> - The write sits outside the worker time budget. It does not hold a lock or a handle across an editor or encoder call. A background conversion and a synchronous conversion never wait on each other for the diagnostic file.
> - The writer catches its own errors, bounds the bytes it allocates and appends, and does not let a recording error reach the worker's exception path. It is not stored on the variant, it does not fail or park the attachment, it is not rethrown, and it is not a death. The Conventions row that fails an attachment and rethrows does not apply to a recording error. A host kill during the write is the same as a kill during the encode: the attempt row (AD-10) and, if the start line was flushed, a start line with no result.

### F3 — A missing result line is not stored as a cause, and is still used as one

- **Severity:** high
- **Disposition:** autofix

The reading rule forbids establishing why a process stopped, and forbids writing a cause from the absence. It does not forbid counting the absence, and it does not forbid a later unit acting on that count. AD-10's own death path is safe: it reads the attempt row and Action Scheduler, and the amendment says not to change that. The other bound readers are not safe.

**Two units.**

- **A, coefficient / summary writer (#32's reading, and any admin total).** Does not write `cause: oom` and does not call the death CAS. It writes `unmatched_starts`, a count of start lines with no result. The field names what it measures. A stores that count in `settings_snapshot.metrics` because AD-18 allows "summary metrics" in the database and AD-17 already names that JSON. The gate then raises its memory estimate from `unmatched_starts`, or skips a size that often has no result line. The AD-11 answer does not read the switch. It reads a summary that exists only because the switch was on. The same attachment is `start` when no log was ever kept and `defer` or `skip` when it was.
- **B, gate that follows the title "never into decisions."** Memory estimate is the static conservative figure from size, format, and encoder. The files are write-only. B's job row does not carry diagnostic counts.

A second clash on the same JSON, even when A does not touch the gate. `BulkJobRepository::merge_snapshot()` writes the snapshot through `update()`, and `update()` always sets `updated_at` (`BulkJobRepository.php` around 605–615 and 696–700). AD-8 says a metrics write must leave `updated_at` alone, and that a conversion makes no write whose only purpose is liveness. A's summary refresh is a heartbeat: while recording is on, every logged conversion keeps the job inside the staleness window. B never writes that key and never refreshes the row. Liveness of the same job depends on the switch.

The field-scope sentence has the same shape. A writes `memory_bytes` from `memory_get_usage(true)` and relies on a sidecar word for "PHP allocator." B writes `memory_bytes` from the cgroup's `memory.current` and the same sidecar word for "cgroup, neighbours included." A reader that trusts the shared key compares the two. Both names "name the scope" if the scope is allowed to live beside the value instead of in the key. The owner decision was that a field names what it measures so one of these is not stored as the other.

**Proposed wording:**

> - **Reading the record.** A field's name is the scope it measures. No second scope reuses the name, and no reader copies a value from one scope into another. An unavailable figure is that field's own unavailable, not a stand-in.
> - The absence of a result line establishes nothing. It is not a cause, and it is not an input. No unit writes or computes from it a variant reason, a death, a job error, a gate answer, a pace value, a memory coefficient, or a counter. AD-10 uses the attempt row and Action Scheduler's answer only.
> - **DB vs files.** These files are not attachment, variant, or job state (AD-2) and are not attempt evidence (AD-8). The database does not store the event stream or a tail of it, in a table, an option, a transient, or a snapshot. Summary metrics mean only the display-only batch metrics AD-17 puts in `settings_snapshot.metrics`. Writing them does not change the job's `updated_at`.

### F4 — "60 days" and "a path that does not use Action Scheduler" still allow two cleanups

- **Severity:** high
- **Disposition:** autofix

The byte cap's number can stay with the #26 spec. The lifecycle cannot. "Prevents: two retention periods" is not met by naming 60 days once.

**Two units.**

- **A, writer-side prune, isolation read strictly.** "While it is off, code whose only purpose is to fill a diagnostic record does not run." A treats prune and rotation as that code, so with the switch off nothing deletes files. Age is file mtime. The size cap is enforced by refusing the next start line once the cap is full. That refusal is not a thrown error, so the isolation sentence does not apply, and the conversion continues with no start line. A killed worker then leaves no trace, which the What bullet requires A to leave. A also never schedules Action Scheduler. On a quiet site one active file is appended for months; mtime stays fresh, so the 60-day rule never sees it.
- **B, retention read strictly, plus Maintenance.** Files older than 60 days are deleted by the timestamp in the first JSON line, so A's long-lived active file is deleted while A still has it open. The next append recreates it, or keeps writing to an unlinked inode. The start line of an in-flight encode is gone. B's cleanup "has a path" on `admin_init` that does not use Action Scheduler, and also prunes from Maintenance, an AS action at priority 10 (AD-5 allows a service action; the sentence does not say the non-AS path is the only one). Uninstall is in AD-18's Binds and is not mentioned in the rule. B deletes the directory on every uninstall, including when `remove_data_on_uninstall` is off (`uninstall.php` returns at that flag before it deletes anything). A leaves the files unless the flag is set, like the tables. After a network uninstall, B's shared directory (one tree, `site_id` inside the line) removes every site's logs while deleting the first site. A uses `wp_upload_dir()` per site (AD-15).

A third reading of "two retention periods" drops the size cap so that only 60 days exists. The rule lists the cap beside the 60 days. Both are required; the forbidden pair is two clocks (for example 30 days for the active file and 60 for archives), not a size bound plus a time bound.

Idle sites need an explicit non-goal. AD-9 has no night-time check. If the spine is silent, B's AS sweep is the unit that "makes 60 days true" on a site nobody visits, and A refuses to ship it.

**Proposed wording, replacing the Retention bullet:**

> - **Retention.** One cleanup routine per site. Sixty days and one size cap both apply; there is not a second time limit. Age is the file's last-write time. The routine deletes rotated files older than 60 days, and it frees cap space by deleting whole rotated files, oldest first. It does not truncate a line, and it does not delete or rename the file a writer has open. The cap's byte number belongs to the file mechanism deferred below.
> - The routine is not diagnostic-only work. It runs while recording is off, from a diagnostic write, from an admin request, and from uninstall. It is not an Action Scheduler action, and nothing schedules one for it. There is no network sweep: a site whose requests never run the routine can keep files past 60 days. That wait is not another retention period.
> - A full cap does not change a conversion and does not suppress a start line by failing the attachment. If a line cannot be stored, the conversion still runs and the absence stays unexplained (Reading the record).
> - Each site keeps its own directory. Uninstall deletes that site's diagnostic files only when `remove_data_on_uninstall` is set, in the uninstall request, through this routine, and only after it has finished or given up the generated-file cleanup it already does. It does not delete another site's files. "Clear logs", when it exists, deletes only these files.

### F5 — "Not reachable" and "no secrets, no image names" still depend on which unit writes the line

- **Severity:** medium
- **Disposition:** discuss

The token and free-text parts can be fixed without a new product choice. The URL part cannot; the spine currently defers it.

**Two units, URL.**

- **A, uploads directory plus deny files.** Writes under `wp_upload_dir()` with a random directory, `.htaccess`, `web.config`, and `index.php`, and never prints a URL. The mechanism is the deferred item, so A is in bounds. On a server that does not honor `.htaccess`, the JSONL is reachable by URL once the path is known. The outcome sentence ("Files are not reachable by URL") is false, and A has followed every procedural sentence.
- **B, fail closed.** Refuses to create files under any directory `wp_upload_dir()`'s base URL can map, until a path outside the document root is configured. Recording stays off in effect; conversions are unchanged (F2). B also meets the outcome sentence, and also claims the mechanism was left to it.

The #26 notes already ask the owner which of these is accepted. Until that is answered, the Deferred line and the Privacy sentence license both. Proposed fork, pick one:

> - **Privacy.** Files are not reachable by a URL. A deny file under the uploads URL is not enough on a server that ignores it. Either the writer puts them outside any URL-mapped directory, or the owner records that an unguessable uploads path plus deny files is accepted and a server that ignores those files can still serve them.

**Two units, payload, both claiming "ids and numbers only."**

- **A** logs `attempt_token` as the attempt's id. The token is the guard on every variant transition and on the publication lease (`VariantRepository::transition` appends `a.attempt_token = %s`; `AttachmentRepository::finish` requires it). A copy of the file is enough to authenticate a late write for as long as the attempt row holds that token. A also copies the converter's message. `ImageConverter` builds `Source file is missing: ` plus `source_relative_path`, and `fail()` returns that string in the result errors. The line holds an image name and a path. The named fields are still ids and numbers.
- **B** stores reason codes only, never the attempt token, never the directory token, never a path.

**Proposed wording (does not depend on the URL fork):**

> - The files hold ids, reason codes, and numbers. They do not hold user data, URLs, image names, paths, or secrets, in a field or in free text. The attempt token and the directory token are secrets: the first authorizes the attempt's writes (AD-10), the second keeps the directory unguessable. A conversion message is omitted, or stored only after paths, URLs, and file names are removed.

## Not reopened

- Retention stays 60 days. The cap is an additional size bound, not a second clock, and its byte number stays deferred with the file mechanism.
- Opt-in stays the default. Cleanup while off is retention, not a probe.
- AD-10's confirmed death stays the attempt row plus Action Scheduler's answer. The log does not become a third input.
- Pace and gate lines stay absent until those features exist. F1 adds a start line that is explicitly not a gate line.
