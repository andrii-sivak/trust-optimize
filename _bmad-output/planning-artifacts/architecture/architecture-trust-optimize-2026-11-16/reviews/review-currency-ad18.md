# Currency / reality-check review — spine amendment for diagnostics (AD-18)

- Reviewed: uncommitted diff of `ARCHITECTURE-SPINE.md` (2026-11-20) on `spec/gh-26-conversion-diagnostics`. The edit is AD-18 plus the new `sources` entry that cites it. Unchanged AD-18 sentences were not re-checked.
- Check: every library, service, or version named in an amended sentence is already true in this plugin, or is a plain PHP/WordPress fact. "Action Scheduler", "JSONL", and "60 days" are decisions, not version claims. The Stack table was not re-verified; AD-18 names no version from it.
- Code checked: `composer.json` (`php: >=8.0`, `woocommerce/action-scheduler: ^3.8`). No `Gate`, `Pace`, `Diagnostic`, or `Logger` class under `includes/`.

## Verdict

pass

The amended sentences name no library, service, or version that is not already true here or a plain PHP fact. Action Scheduler is the bundled dependency and is named only as something one cleanup path must not use. No version number was added.

## Names in the amended sentences

| Name | Where | Status |
| --- | --- | --- |
| Action Scheduler | Retention: cleanup has a path that does not use it | **Decision, and already true.** `composer.json` requires `woocommerce/action-scheduler`. No 3.9.3 / 4.x claim, so the Stack table is not in play. |
| JSONL | Unchanged "What" bullet; called out in the brief | **Decision**, not a package. No JSONL library is named. |
| 60 days | Retention, and the new `sources` line | **Decision.** No product version. |
| the pace, the gate | New sentence: those features write their own lines; a release without them does not invent the lines | **Not an external service.** Same features as AD-11. The sentence says they may be absent. No such classes exist under `includes/` today, which matches that sentence. |
| thrown error | Isolation: a recording failure, including a thrown error, does not interrupt the conversion | **Plain PHP fact.** `Error` is throwable since PHP 7; the plugin requires PHP >= 8.0. The sentence is a rule, not a claim that PHP swallows an uncaught error by itself. No PHP version is named. |
| Clear logs | Retention: "when it exists" | **Not a WordPress service.** The clause says the action may not exist yet. |
| AD-11, AD-17 | Cross-references | Spine rules, not libraries. The new AD-11 sentence does not restate `memory_limit` or container headroom. |

## Findings

None. Severity: n/a. Disposition: no change.
