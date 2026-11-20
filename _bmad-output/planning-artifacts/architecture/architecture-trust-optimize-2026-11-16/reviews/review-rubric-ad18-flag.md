# Rubric review — AD-18 flag and file rule

Reviewed only AD-18 and the Deferred bullet that mentions diagnostics in `ARCHITECTURE-SPINE.md` (`updated: 2026-11-20`). AD-10, AD-11, AD-15, and AD-17 were checked only for contradictions with that rule. Unchanged ADs were not re-opened.

## Verdict

**pass**

The rule is enforceable. It states every bound decision in a form a spec can implement and a review can test, and it does not contradict AD-10, AD-11, AD-15, or AD-17. Deferred still owns only file names, the line-size number, and the age-cleanup interval, inside the caps and the lock already fixed.

## Findings

None.

## Walk

### Bound decisions

| Decision | Where the rule locks it |
| --- | --- |
| `uploads/to-logs` per site | Privacy: each site's files live in that site's `uploads/to-logs`. |
| No secret directory | Privacy: no unguessable directory name. |
| Deny files, no URL guarantee | Privacy: usual deny files, never emit a URL, residual risk accepted. |
| 60 days kept | Retention: 60 days from last write. One age. Size caps may keep less. A site that never runs age cleanup may keep files longer. |
| 64 MiB and 8 MiB maxima | Retention: both figures are maxima. |
| Option `trust_optimize_diagnostics` value `1` | Isolation: only stored `1` enables; absent is off. |
| No checkbox | Isolation: this release adds no settings checkbox. |
| Writer time inside the worker budget | Isolation: writer time counts inside the budget; the deadline is not extended. |
| Non-blocking lock | Isolation: try without waiting; a missed lock skips that line or that cleanup pass. |
| Size check on every write | Retention: checked on every write, under the write lock. |
| Periodic age cleanup | Retention: periodic, not part of the per-write check, not an Action Scheduler action. |
| Unique rotated names within one second | Retention: a rotated name is unique within the same second. |
| `conversion_id` not stored and authorizes nothing | Privacy: it only joins a start line to its result. |
| Reason codes only | Privacy: ids, reason codes, and numbers. No free-text errors, paths, URLs, image names, or secrets. The attempt token is not a field. |

"Usual deny files" does not name the files. The residual-risk sentence makes the set testable without a promise that they work: deny files are written, no URL is emitted, the directory name is guessable. File names stay with the #26 spec.

"8 MiB per file" plus "does not truncate a line" is met by rotating before a line would exceed the file cap, then deleting whole rotated files, oldest first, until the site total is at most 64 MiB. The open file is not the one deleted. The line-size number, deferred, has to fit in an empty file under that cap. Two implementations cannot keep more than the maxima.

`conversion_id` is written on the two lines and "is not stored" as a credential or database field. Read with "authorizes nothing" and with DB vs files, that is one store, the JSONL file, not a second channel.

### AD-10

Death, recovery, and the late-worker checks stay on the attempt row and Action Scheduler. The start line is only a trace a killed worker leaves. A missing line, a lock skip, or a failed write is not a death, not a job error, and not an input. A recording error is caught, is not rethrown, and does not follow the convention that fails the attachment. The attempt token is not a diagnostic field, so a web-reachable log cannot become a claim key. Writer time can make the run return `continue` sooner; that result already resets `attempts` and is not a death. `updated_at` is not touched by a diagnostics write.

### AD-11

Gate inputs stay pause, free disk, memory estimate, and pace. The flag and the fate of a write do not change the answer, the variant reason, or whether the Action Scheduler action fails. A measurement the gate needs is not diagnostic-only code, so it still runs while recording is off. Gate and pace lines exist only when those features exist and are passed in by them. The writer lock is not the one-at-a-time slot: it is non-blocking, and it is not held across an editor or encoder call. Sync stays outside that slot; if it loses the log lock it skips the line and still returns at once.

Per-write deletion frees at most the diagnostic footprint (64 MiB per site) on the uploads volume. That cannot change the disk answer under the current 1 GiB / 5% floor. The rule already forbids recomputing the answer from the write. No AD-11 sentence has to move.

### AD-15

Tables, options, mutex, and uploads stay per site. The diagnostics option and `uploads/to-logs` are per site, so a network uninstall inside `switch_to_blog()` deletes that site's directory only when that site's `remove_data_on_uninstall` is set. Deactivation still does not delete the files. Nothing in the rule writes another site's logs or migrates a schema.

### AD-17

No diagnostic table, event column, or second metrics store. Summary metrics stay the display-only batch metrics. The option is not a schema change and does not bump `DB_VERSION`. The attempt columns AD-10 places in schema 1.2.0 stay attempt state.

### Deferred

The diagnostics bullet still leaves file names, the line-size number, and the age-cleanup interval to the #26 spec, and it records the directory, the 60 days, both caps, the URL residual risk, and the non-blocking lock as already fixed. That matches the rule. The structural seed still leaves the writer class to the same spec; that is not a second choice of directory. No other deferred bullet is loosened.
