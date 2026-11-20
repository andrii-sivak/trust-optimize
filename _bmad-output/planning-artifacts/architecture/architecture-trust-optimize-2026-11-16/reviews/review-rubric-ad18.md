# Rubric review — AD-18 amendment (diagnostics)

Reviewed only `ARCHITECTURE-SPINE.md` as of `updated: 2026-11-20`. Scope is the AD-18 amendment: retention 60 days, isolation, reading the record, cleanup not solely through Action Scheduler, and pace and gate lines only when those features exist. Unchanged ADs were checked only for contradictions with those sentences.

## Verdict

**pass**

The amended rule is enforceable and blocks every divergence named in Prevents. It keeps one private JSONL channel, matches the owner decision recorded in the front matter, and does not weaken AD-10, AD-11, or AD-17. Deferred still has a single owner for file location and mechanism.

## Findings

None.

## Walk

### Rule against Prevents

| Prevents | Where the rule locks it |
| --- | --- |
| Logs exposed by URL or carrying user data | Privacy: not reachable by URL; IDs and numbers only; no user data, URLs, image names, or secrets. |
| A diagnostics failure aborting or redirecting a conversion | Isolation: a recording failure, including a thrown error, does not interrupt the conversion and does not change its decision. |
| Always-on diagnostic probes | Isolation: recording is opt-in; while it is off, code whose only purpose is to fill a diagnostic record does not run and does not write a file. |
| Event streams bloating the database | DB vs files: the database keeps only job state and summary metrics. |
| A missing log line stored as a cause | Reading the record: absence of a result line does not establish why a process stopped, and no unit writes a cause from that absence. |
| Two retention periods | Retention states one age: files older than 60 days. The size cap is a size bound, not a second age. "Clear logs" deletes the same files. No other retention age appears in the spine. |

"A diagnostic field names the scope it measures" is checkable on the line schema (a value whose subject is not named fails review). The vocabulary of fields stays inside the one file mechanism the #26 spec owns. That does not add a second channel.

"Pace-change lines and gate-decision lines are written by the pace and the gate" names the source of those facts. The next sentence forbids inventing them in a release that has no pace and no gate. It does not choose a path or a class. The structural seed and Deferred still leave the writer location to the #26 spec.

"Cleanup has a path that does not use Action Scheduler" is checkable: age deletion must be reachable without an AS action. It does not forbid an additional AS action, and it does not add a conversion action, so it does not fight AD-4 or AD-5. Which non-AS call runs is file mechanism, owned by the #26 spec, inside this rule.

### AD-10 — death stays on the attempt row

The attempt record in `trust_optimize_attachments` is still what recovery and the late-worker checks key on. Counting still happens only in `claim()`. The death CAS still writes `last_error` from the attachment ID and the death count.

The separate start line is a trace in the diagnostic file so a killed worker leaves a line. Reading the record forbids treating a missing result line as why the process stopped, and forbids any unit writing a cause from that absence. Job-health, `last_error`, variant reasons, and the death CAS cannot take a missing result line as a death. An unpaired start line is that absence. Isolation keeps a recording failure from changing the conversion outcome the attempt row records.

AD-9's queue signals remain a different use of "diagnostic": they feed the notice and the Resume warning and never become this file channel. AD-9 still shows `last_error` from death recovery, or says the cause is unknown. That is not a cause taken from a log gap.

### AD-11 — gate reads stay decisions

AD-11 inputs are unchanged: pause, free disk, memory estimate, background pace. AD-18 records the gate decision and the result; it does not add the log to those inputs.

Isolation says a resource decision does not depend on the opt-in switch, and a recording failure does not change the decision. Gate answers (`start`, `park`, `defer`, `skip`) are therefore the same whether recording is on, off, or throwing. While recording is off, probe code that only fills a diagnostic record does not run; the memory and disk reads the gate needs for its answer still do.

Pace-change and gate-decision lines exist only when the pace and the gate exist, and they are emitted by those features. A release without them does not invent a parallel sampler. That keeps diagnostic recording from becoming a second gate.

### AD-17 — database stays job state and summary metrics

AD-18 repeats the limit and points it at AD-17: the database keeps only job state and summary metrics. Environment, settings snapshots for diagnosis, gate lines, and pace lines go to the JSONL files. Nothing in the amendment adds a diagnostic table, an event column, or a second metrics store. The attempt columns AD-10 already places in schema 1.2.0 remain attempt state, not an event stream. Display-only batch metrics stay where AD-17 put them.

The cross-reference does not rewrite AD-17's migration rule. It binds diagnostics so they cannot grow the schema.

### Deferred

No deferred bullet is loosened.

- Location and file mechanism of diagnostics stay owned by the #26 spec, within AD-18. The amendment tightens that bound (60 days, non-AS cleanup path, opt-in isolation, no cause from a missing result line, no pace or gate lines before those features exist). It does not name a directory, a filename, or a writer class.
- Pace algorithm and coefficients stay inside AD-11, owned by #26 and #32. They do not gain a second line format. Lines, once the feature exists, come from the pace; the file mechanism stays the #26 spec.
- Slot versus attempt record, deferral data, and queue-signal deferral are untouched. None of them may treat a missing diagnostic result as a cause, which closes a misuse rather than opening a choice.

Two units cannot pick different retention ages, different death sources, or different diagnostic stores from this edit. They still cannot pick different file locations: that choice has one owner and was left open on purpose.

### Owner decision of 2026-11-20

The front matter records: "60-day retention, opt-in recording does not interrupt a conversion, a missing result line is not a cause."

- Retention deletes files older than 60 days. One period.
- Isolation is opt-in, and a recording failure does not interrupt the conversion or change its decision. The resource decision does not depend on the switch.
- Reading the record: absence of a result line does not establish why a process stopped, and no unit writes a cause from that absence.

The same rule keeps a single channel: opt-in JSONL per site. The database is not a second diagnostics channel. Queue signals in AD-9 are not redefined as that channel. The separate start record is the before-line of the same record, not another store. The amendment does not invent a second diagnostics channel.
