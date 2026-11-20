# Amendment gate — AD-13 occupied-target status (2026-11-20)

Scope: the owner decision that #19 keeps `failed` / `target_exists_foreign`, and that `skipped` moves to Deferred together with display and retry rules. The rest of the spine was not re-opened.

## Mechanical

`lint_spine.py` on this workspace: `ok: true`, `total_findings: 0`.

## Divergence check

- Two units implementing #19 can no longer choose `skipped` or `failed`. AD-13 states `failed` / `target_exists_foreign` as the adopted outcome. The file stays. The attempt ends normally.
- The Deferred item does not leave the status free. It names the later change as `skipped` plus how the result is shown and when planning retries it, and it points that writer at the AD-3 / AD-8 exclusion rule. A later unit cannot ship a bare status swap.
- #19 is no longer listed as a new evidence-status writer. Cleanup's existing `failed` / `delete_failed` exclusion is unchanged.

## Verdict

The amendment is consistent with the owner's decision and does not weaken ownership (hash required) or publication (#30). No fix to apply.
