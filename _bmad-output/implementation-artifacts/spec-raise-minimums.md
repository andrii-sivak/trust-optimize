---
title: 'Require WordPress 7.0 and test up to PHP 8.5'
type: 'chore'
created: '2026-11-16'
status: 'done'
baseline_commit: '1362108edb6b7abd35e528a7dbc495e988deec25'
route: 'dispatch'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The plugin declares WordPress 6.5 as its minimum, while the official WordPress agent skills the project follows target 7.0+, and the test matrix stops at PHP 8.4 although 8.5 is the current stable release.

**Approach:** Declare WordPress 7.0 as the minimum everywhere it is stated and tested, keep PHP 8.0 as the minimum, and move the top of the test matrix from PHP 8.4 to 8.5. Remove code that only existed for WordPress versions below 7.0.

## Boundaries & Constraints

**Always:**
- Owner decisions: WordPress minimum 7.0; PHP minimum stays 8.0 (`composer.json` `require.php`, `config.platform` and phpcs `testVersion` unchanged); test matrix PHP 8.0 and 8.5; PHP 8.6 is added only after its stable release.
- Every place that states or tests the WordPress minimum says 7.0; every place that tests or documents the PHP top version says 8.5 instead of 8.4. The 1.0.0 entry in `CHANGELOG.md` is history and stays.
- `CHANGELOG.md` under `## [Unreleased]`, `### Changed`: "Requires WordPress 7.0 or higher (was 6.5)."
- Removing a pre-7.0 workaround is a refactoring in its own commit, separate from the version changes.

**Never:**
- No plugin version bump, no `Stable tag` or `Tested up to` change.
- No edits outside the repository (the Docker stand's `docker-compose.yml` is the owner's), to `AGENTS.md` or `CLAUDE.md` (refreshed separately through `bmad-project-context`), or to the spec.
- No new PHP syntax or features beyond 8.0.

</frozen-after-approval>

## Code Map

- `trust-optimize.php` (`Requires at least`), `readme.txt` (header `Requires at least`, `= Requirements =`), `README.md` (Requirements, test commands) -- declared minimum and documented commands.
- `phpcs.xml` -- `minimum_supported_wp_version` and `minimum_wp_version` (6.5), plus the comment above the latter.
- `.github/workflows/ci.yml` -- unit matrix `['8.0', '8.4']`; integration matrix rows with `wp: '6.5'` and `php: '8.4'`. Lint stays on 8.0.
- `tools/bin/test` -- accepts `8.0|8.4` and maps 8.4 to `tools-php84`; usage lines name 6.5. The stand now has a `tools-php85` service (`trust-optimize-tools:php8.5`); 8.4 is dropped from the wrapper to mirror CI.
- `tools/README.md` -- command table and the "Container facts" table (`tools-php84` column).
- `composer.json` / `composer.lock` -- dev requirement `wp-phpunit/wp-phpunit: ^6.5`; releases 7.0.x and 7.1.x exist.
- `includes/service/ImageCleanupService.php:313-321` -- deletes, then checks `file_exists()`. The comment blames WordPress before 6.7, but the check is still the right test on 7.0: a `wp_delete_file` filter can redirect or skip the deletion, and another cleanup can remove the file first, so the return value alone misreports both ways. Keep the check; only the comment is wrong.
- `.github/workflows/ci.yml` step "Pin wp-phpunit to the downloaded core version" and `tools/bin/test` -- both read `wp core version`; under PHP 8.5 WP-CLI can print deprecation notices to stdout, and `sh` has no `pipefail`, so a failing `wp` call is hidden.
- No other WordPress-version guards exist in `includes/` (the `function_exists( 'as_*' )` checks guard Action Scheduler and stay).

## Tasks & Acceptance

**Execution:**
- [x] `trust-optimize.php`, `readme.txt`, `README.md`, `phpcs.xml`, `CHANGELOG.md` -- WordPress 6.5 → 7.0 and the README test commands; add the Changed entry -- declared minimum.
- [x] `.github/workflows/ci.yml` -- unit matrix 8.0 and 8.5; integration rows `8.0 / 7.0 / mariadb:10.6`, `8.5 / latest / mariadb:10.6`, `8.0 / 7.0 / mysql:5.7` -- tested range.
- [x] `tools/bin/test`, `tools/README.md` -- accept `8.0|8.5` (8.5 → `tools-php85`), usage lines with 7.0; replace the `tools-php84` facts column with `tools-php85` values checked in the container (`php -v`, `function_exists( 'imagewebp' )`, `function_exists( 'imageavif' )`, Imagick `queryFormats`) and update the checked date -- local runner mirrors CI.
- [x] `composer.json`, `composer.lock` -- `wp-phpunit/wp-phpunit: ^7.0`, updated with `tools/bin/composer update wp-phpunit/wp-phpunit` only -- dev library matches the minimum.
- [x] `includes/service/ImageCleanupService.php` -- revert 65b4f5c (`git revert`), then replace the comment above the `file_exists()` check with the real reason (a filter can redirect or skip the deletion; the file system is the ground truth) in a `docs(cleanup)` or `refactor(cleanup)` commit -- keeps correct behaviour.
- [x] `tools/bin/test`, `.github/workflows/ci.yml` -- read the core version as the last output line matching `^[0-9]+\.[0-9]+`, and fail with a clear message when there is none -- one robust parse in both places.
- [x] `phpcs.xml` -- remove `minimum_supported_wp_version` (WPCS 3 renamed it to `minimum_wp_version`, so it is ignored) and make the comment say the deprecation checks assume WordPress 7.0 or newer -- one place to bump next time.

**Acceptance Criteria:**
- Given the change, when `git grep -nE "6\.5|8\.4"` runs over the files in the Code Map, then only the 1.0.0 CHANGELOG entry and the unrelated PHP 8.4 deprecation comment in `tests/Integration/BulkProducerTest.php` remain.
- Given `phpcs.xml` at 7.0, when phpcs runs, then it reports no errors; if it flags deprecated WordPress functions, they are replaced in their own commit, or the work stops and is reported when the replacement is not a one-line change.
- Given a cleanup whose file cannot be deleted, when `remove_file()` runs, then it still returns `failed` / `delete_failed` (existing tests keep passing).

## Verification

**Commands:**
- `tools/bin/composer phpcs` -- expected: no errors
- `tools/bin/test unit 8.0` and `tools/bin/test unit 8.5` -- expected: OK
- `tools/bin/test integration 8.0 7.0` and `tools/bin/test integration 8.5 latest` -- expected: OK
- `WP_MULTISITE=1 tools/bin/test integration 8.0 7.0 "--group ms-required"` -- expected: OK

**Manual checks (if no CLI):**
- If `trust-optimize-tools:php8.5` is not built yet, stop and report instead of building it.
- If `SiteHealthTest` or `LifecycleTest::test_work_queued_before_a_deactivation_is_finished_after_the_next_activation` fail locally, it is the known test-database leak in `deferred-work.md`: delete the pending `trust-optimize` actions from `wptests_actionscheduler_actions` in the test database and re-run.

## Implementation Notes

- d6a7129 version changes; 414955d and 6bcfa7a version parsing in `tools/bin/test` and CI (WP-CLI prints PHP 8.5 deprecation notices to stdout); 180ae1a comment for the CI pipeline; 03e656b dead phpcs setting removed. 65b4f5c (return-value refactor) was reverted in bea77f4 after review; b52425c gives the real reason for the `file_exists()` check.
- Verified locally: phpcs clean; unit 8.0 and 8.5 (80 each); integration 8.0 + WordPress 7.0 (wp-phpunit 7.0.7) and 8.5 + WordPress 7.1.4 (236 each, 8 skipped); multisite 8.0 + 7.0 (8). The known test-database leak was cleaned before each integration run.
- `AGENTS.md` is refreshed next, outside this spec, through `bmad-project-context`.

## Spec Change Log

- Review pass 1 (bad_spec, triage rows 1-2): the plan classified the `file_exists()` check in `ImageCleanupService::remove_file()` as pre-6.7 legacy. Reviewers showed it still decides correctly on 7.0 when a `wp_delete_file` filter redirects or skips the deletion, or another cleanup removed the file first; the return-value refactor (65b4f5c) misreports both cases. Amended: Code Map entry and the cleanup task (revert, fix the comment). Patch findings (rows 3, 5) added as tasks. Avoids: orphaned variant files with dropped rows, and rows stuck as failed for files that are gone. KEEP: d6a7129 (version changes) and 414955d's intent; no full revert, the loop re-derives only the cleanup change.

## Review Triage Log

| # | Source | Finding | Verdict | Evidence / route |
|---|---|---|---|---|
| 1 | blind, edge-case | A `wp_delete_file` filter that returns empty (offload plugins) after deleting the file itself now leaves the row `failed` forever; one that returns another path reports `deleted` while the file stays | medium | Verified in WordPress 7.1 `wp_delete_file()`: the return value is the filtered `unlink()` result, not the state of `$path`. The old `file_exists()` judged `$path` itself. Route: bad_spec. |
| 2 | edge-case | A file removed by a concurrent cleanup between the existence check and `unlink` is now kept as `failed` | low | Same root cause as row 1; grouped. |
| 3 | blind, edge-case, verification-gap | The CI pin step parses raw `wp core version` output; the PHP 8.5 row breaks if notices reach stdout | maybe-false | Edge-case reviewer reproduced notices on stdout in the 8.5 container (`display_errors=On`); unknown for setup-php's default ini. Fails loudly either way. Cheap shared fix -> patch. |
| 4 | blind, edge-case | `tail -n 1` hides a failing `wp core version` (no `pipefail`) and assumes notices come first | medium | Edge-case reviewer reproduced: exit 0 and a Deprecated line as the version, then a junk `tests-*` dir. Same root cause as row 3; grouped -> patch. |
| 5 | blind | `phpcs.xml` still sets `minimum_supported_wp_version`, and the comment reads "before WP 7.0" | low | Verified: WPCS 3 renamed the option to `minimum_wp_version` (`MinimumWPVersionTrait.php:63`), so the old key is dead config. Trivial deletion -> patch. |
| 6 | blind, edge-case | `AGENTS.md` still names 6.5, the 8.4 commands and the 6.5 guard rule | high | Real; agents would run commands that now exit 2. Agent-context file -> defer to the `bmad-project-context` refresh that follows in this branch. |
| 7 | blind, edge-case | No runtime guard for WordPress below 6.7 when the plugin is deployed by git, Composer or zip upload | low | WordPress blocks activation and package installs below `Requires at least`; all installs are owner-controlled on 7.x. Unlikely; a guard adds code. Rejected. |
| 8 | blind | `tools/README.md` facts carried over unchecked; the Check line covers only `tools-php80` | low | Verified: the listed extensions exist in both containers (8.5 adds lexbor, random, uri, OPcache; lacks ftp, which is not listed). Values hold. Rejected. |
| 9 | blind | `composer.lock` left out of the reviewed diff; `plugin-api-version` moved to 2.11.0 | false | Excluded on purpose; written by `tools/bin/composer` as `AGENTS.md` prescribes, and it installed under `platform.php` 8.0.30 in the release-style run. |
| 10 | blind | CHANGELOG gives no guidance for sites below 7.0 and omits PHP 8.5 testing | low | WordPress itself withholds the update below `Requires at least`; the test matrix is not user-facing. Rejected. |
| 11 | blind | The stand still defines the unused `tools-php84` service | low | Outside the repository; scheduled for removal after merge. Rejected here. |
| 12 | verification-gap | No verification gaps; success and failure paths of `remove_file()` covered | false | Not a defect. |
| 13 | blind (pass 2) | The CI pin step has the version-parsing pipeline without the explanation `tools/bin/test` has | low | Real: a reader is likely to simplify it back and break the PHP 8.5 job; fix is one comment line. Route: patch. |
| 14 | blind (pass 2) | Read `$wp_version` from `wp-includes/version.php` instead of parsing WP-CLI output | low | The current parse works on both runners and fails loudly with a clear message. Rejected. |
| 15 | blind (pass 2) | `docs/release-1.0.0/checks.md` lists the old commands | false | `docs/` is gitignored local history of the 1.0.0 release, not a live checklist. |
| 16 | edge-case (pass 2) | The acceptance grep now also matches the new CHANGELOG line "(was 6.5)" | low | The frozen CHANGELOG wording requires that text; the fix would edit this spec. Rejected. |
| 17 | verification-gap (pass 2) | Nothing checks that `Requires at least` / `Requires PHP` agree across the header, `readme.txt` and the lowest CI row | medium | Pre-existing gap in `scripts/build-release.sh`, not caused by this change. Route: defer (`deferred-work.md`). |
| carried | blind, edge-case (pass 2) | `AGENTS.md` stale; readme Upgrade Notice / release bump; 8.5 not mentioned to users; `tools/README.md` facts; stand `tools-php84` | carried | Same location and claim as rows 6, 10, 8, 11; verdicts and routes kept. |
