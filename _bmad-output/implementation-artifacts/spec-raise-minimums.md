---
title: 'Require WordPress 7.0 and test up to PHP 8.5'
type: 'chore'
created: '2026-11-16'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
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
- `includes/service/ImageCleanupService.php:313-321` -- deletes, then checks `file_exists()` because "wp_delete_file() returns nothing before WordPress 6.7"; since 6.7 it returns `bool`.
- No other WordPress-version guards exist in `includes/` (the `function_exists( 'as_*' )` checks guard Action Scheduler and stay).

## Tasks & Acceptance

**Execution:**
- [ ] `trust-optimize.php`, `readme.txt`, `README.md`, `phpcs.xml`, `CHANGELOG.md` -- WordPress 6.5 → 7.0 and the README test commands; add the Changed entry -- declared minimum.
- [ ] `.github/workflows/ci.yml` -- unit matrix 8.0 and 8.5; integration rows `8.0 / 7.0 / mariadb:10.6`, `8.5 / latest / mariadb:10.6`, `8.0 / 7.0 / mysql:5.7` -- tested range.
- [ ] `tools/bin/test`, `tools/README.md` -- accept `8.0|8.5` (8.5 → `tools-php85`), usage lines with 7.0; replace the `tools-php84` facts column with `tools-php85` values checked in the container (`php -v`, `function_exists( 'imagewebp' )`, `function_exists( 'imageavif' )`, Imagick `queryFormats`) and update the checked date -- local runner mirrors CI.
- [ ] `composer.json`, `composer.lock` -- `wp-phpunit/wp-phpunit: ^7.0`, updated with `tools/bin/composer update wp-phpunit/wp-phpunit` only -- dev library matches the minimum.
- [ ] `includes/service/ImageCleanupService.php` -- judge deletion by the return value of `wp_delete_file()` and drop the `file_exists()` workaround and its comment; separate `refactor(cleanup)` commit -- pre-7.0 legacy.

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

## Spec Change Log

## Review Triage Log
