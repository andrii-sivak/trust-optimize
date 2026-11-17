<!-- bmad:context -->
<!-- Verified 2026-11-17 against 0943f8c. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## TrustOptimize

WordPress plugin that converts the image sizes WordPress generates into WebP and AVIF and serves them through `<picture>`. PHP 8.0+, WordPress 7.0+, Action Scheduler for background work, plain JS and CSS in `assets/` with no build step. User documentation (settings, hooks, WP-CLI, REST) is `readme.txt`; planning artifacts are in `_bmad-output/`, project knowledge in `info/`.

## Policy

- Never commit to `main`, merge, push, or create tags and releases; the owner merges through pull requests.
- Code, comments and commit messages are in English; commits are Conventional Commits, `type(scope): summary`.
- Comments explain why in their own words. Never point to specs, stories or plan IDs from code: they describe a moment and the code outlives them. Reference them in commit messages and pull requests instead.
- Refactoring changes structure without changing observable behavior. Never mix a refactoring and a behavior change in one commit.
- When a change is hard to make, first refactor to make it easy, in its own commit, then make the change.
- No speculative generality: no interface, abstract class, factory, hook or option without a current second user. Extract duplicated logic on the third repetition, not earlier.
- Do not accumulate legacy: delete dead code, compatibility shims, unused parameters and commented-out code instead of wrapping them. While deleting, you may restructure the methods, classes and files it touched when the result is simpler. Public hooks, settings, REST routes, WP-CLI commands and the schema are not legacy: removing them needs the owner.
- When simplicity and modularity pull apart, or a change would alter public behavior beyond the task, stop and ask the owner instead of choosing.
- Bump the plugin version only in the release commit on a `release/*` branch; every other branch adds its entry under `## [Unreleased]` in `CHANGELOG.md`.
- The release zip contains only the paths listed in `.gitattributes`. A new top-level file or directory the plugin needs at runtime gets a `-export-ignore` line there; nothing else does.
- Before writing a spec for, or changing, a REST route, a WP-CLI command, a settings or admin screen, activation/deactivation/uninstall code, an Action Scheduler task or a database query, load the matching WordPress skill: `wp-rest-api`, `wp-wpcli-and-ops`, `wp-plugin-development`, `wp-performance`.

## Where things are

- Before writing a spec for, or changing, the conversion queue, an Action Scheduler task, bulk jobs, the conversion path, file writes or deletes, format capability, the schema or multisite code, read the architecture spine `_bmad-output/planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md`. Its AD rules bind and win over older notes; a change that contradicts one goes to the owner through `bmad-architecture`.

## Running and verifying

- Run the checks instead of judging style or tests by reading code. Fix style with `tools/bin/composer phpcbf`, not by hand.
- Use the wrappers from the plugin root, not bare `composer` or `phpunit`: they run in the PHP 8.0 and 8.5 containers of the Docker stand (`tools/README.md`).
- `tools/bin/composer phpcs`, `tools/bin/test unit 8.0|8.5`, `tools/bin/test integration 8.0 7.0`, `tools/bin/test integration 8.5 latest`.
- Multisite: `WP_MULTISITE=1 tools/bin/test integration 8.0 7.0 "--group ms-required"`.
- If a `trust-optimize-tools:php*` image is missing, stop and report instead of running `tools/bin/test`: it builds the image first, which takes 10–30 minutes.
- If `SiteHealthTest` or `LifecycleTest` fail locally while CI passes, the test database holds leaked pending actions (`_bmad-output/implementation-artifacts/deferred-work.md`); delete them and re-run: `tools/bin/compose run --rm -T tools-php80 sh -c 'mariadb --skip-ssl -h "$WP_TESTS_DB_HOST" -uroot -p"$WP_TESTS_DB_PASSWORD" "$WP_TESTS_DB_NAME" -e "DELETE a FROM wptests_actionscheduler_actions a JOIN wptests_actionscheduler_groups g USING (group_id) WHERE g.slug=\"trust-optimize\" AND a.status=\"pending\""'`.
- After adding, renaming or removing a class run `tools/bin/composer dump-autoload`; autoload is a classmap and tests fail on a stale one.

## Conventions that differ from defaults

- Before changing or removing a symbol, `git grep` every consumer across `includes`, `templates`, `assets`, `tests`, `uninstall.php`, `trust-optimize.php` and `readme.txt`.
- Wire services through constructors in `includes/core/Plugin.php`; never `new` a service elsewhere (the static `Lifecycle::deactivate()` is the one exception, it runs without the plugin booted).
- SQL goes through `$wpdb->prepare()` with table names as `%i` from the repositories or `DatabaseManager`; never interpolate them.
- Store datetimes in UTC with `current_time( 'mysql', true )`; convert to local time only for display.
- A schema change bumps `DatabaseManager::DB_VERSION` in the same commit; `check_version()` also creates the tables lazily on network sites.
- phpcs catches PHP newer than 8.0, but nothing catches WordPress APIs newer than 7.0: check the `@since` of every core function you call and guard newer ones with `function_exists()`.
- Front-end rendering never writes files or loads an image editor; it only reads variant state (`FrontendNoEditorTest`).
- A change to a public hook, setting, WP-CLI command or REST route updates `readme.txt` in the same commit.

<!-- /bmad:context -->
