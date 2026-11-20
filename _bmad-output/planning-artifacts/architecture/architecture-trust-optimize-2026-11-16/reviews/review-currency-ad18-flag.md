# Currency / reality-check review — AD-18 flag naming

- Reviewed: the AD-18 flag sentences in `ARCHITECTURE-SPINE.md` (2026-11-20), uncommitted on `spec/gh-26-conversion-diagnostics`. Scope is the option name, the stored value that enables it, and age cleanup using the same non-blocking lock try. The rest of AD-18 was not re-opened.
- Check: an amended sentence must not name a library, service, or version that is not already true here or a plain PHP/WordPress fact. `uploads/to-logs`, JSONL, flock, and the option name are project decisions, not version claims. The Stack table was not re-verified.
- Code checked: `composer.json` (`woocommerce/action-scheduler: ^3.8`). The flag sentences do not cite a 3.9.3 or 4.x fact.

## Verdict

pass

The amended sentences name no unverified library or version. The option name, the directory, JSONL, and the lock are decisions. Action Scheduler is the bundled dependency and is named with no version.

## Names in the amended sentences

| Name | Where | Status |
| --- | --- | --- |
| `trust_optimize_diagnostics` | Isolation: per-site option; only stored `1` enables it; absent is off | **Decision.** An option name and a stored value, not a package or a product version. |
| `uploads/to-logs` | Privacy (same AD; called out in the brief) | **Decision.** A directory, not a library. |
| JSONL | What (same AD; called out in the brief) | **Decision.** No JSONL package is named. |
| flock / non-blocking try | Isolation: the writer tries its lock without waiting; age cleanup uses that same try. The spine says "lock", not the function | **Decision.** No `flock` symbol and no PHP version. |
| Action Scheduler | Isolation: the flag does not change whether an action fails | **Already true.** `composer.json` requires `woocommerce/action-scheduler`. No version number was added. |
| settings checkbox | Isolation: this release adds none | **Not a service.** A UI choice. |

## Findings

None. Severity: n/a. Disposition: no change.
