# Changelog

All notable changes to the TrustOptimize plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- A worker that died no longer blocks the queue for good. The job health check ends a queue attempt that Action Scheduler confirms dead (no pending or in-progress action, silent for 15 minutes) and queues the attachment again; after three deaths the file is given up as `max_attempts`. It runs on admin page loads, on Resume and in the hourly maintenance task, never on status requests.
- Attempts that nothing recovers automatically (a synchronous conversion, one that started before the update, a publication that could not finish) are listed in a notice and freed by the new `wp trust-optimize release <id>` command, which first shows the attempt and never touches a file.
- `queue_signals` (`stuck_claim`, `no_progress`) in the REST and WP-CLI job, a warning in the admin notices and on the Bulk optimization tab, and in `wp trust-optimize resume`.
- An admin notice with a Resume button for a bulk job paused for any reason except your own Pause (stopped, deactivated, unknown, low disk space); it names the reason and the last error.
- Error codes `trust_optimize_schema_pending`, `trust_optimize_attachment_busy`, `trust_optimize_disk_low`, `trust_optimize_resume_failed` and `trust_optimize_start_failed`.
- A paused bulk job stores why it was paused in `pause_reason` (`user`, `deactivated`, `low_disk_space`, `stopped`, `unknown`), shown in the admin panel as "Paused because" and in the REST API and WP-CLI job output.

### Fixed
- Resume no longer gets undone by the next stale check while the queue is still stuck: a resumed job that has had no chance to work stays running, and Resume is undone, with an error, only when its producer cannot be scheduled. A bulk job whose first producer cannot be scheduled fails instead of staying running.
- A conversion attempt is owned by its worker: a claim is never taken over by age, every write of a worker is guarded by the attempt's token, and a file is moved into place only under a lease on the attachment row, so a worker that was recovered meanwhile cannot replace a file a newer attempt published.
- A file is replaced only when its hash is the one the variant row recorded; any other file at a variant path is kept and the variant ends `target_exists_foreign`.
- Attachments queued in a paused bulk job are parked when their task runs (nothing is converted while paused) and queued again by the job after Resume, wherever its cursor is; the job does not complete while they wait.
- Cancelling a paused job queues its parked attachments, so they are not left behind; a deactivation gives back the attempt of the conversion it interrupted instead of counting it as a death.
- A file that died three times is not queued again by a bulk run, regeneration or maintenance; an explicit sync, or a changed source file, gives it another chance.
- A running bulk job was paused as stale after a DST change or a change of the site timezone, and a job that had really stopped could be detected as stale hours late, because job timestamps and the stale threshold used site-local time.
- `wp trust-optimize sync-attachment` and `remove-attachment` work as documented. WP-CLI registered them as `sync_attachment` and `remove_attachment`; those names no longer exist.
- The Documentation link on the settings page, the plugin and author URIs and the link in `readme.txt` point to the renamed GitHub account; the old author profile link returned 404.

### Changed
- Database schema 1.2.0: the attachments table gets `attempt_source`, `attempt_token` and `publishing_at`, and the jobs table gets `resumed_at`, `resume_token` and `resume_cursor_id`. Until the migration has finished, queued tasks reschedule themselves in an hour without claiming, and syncs, bulk start and Resume answer `trust_optimize_schema_pending`.
- `POST /image/{id}/sync` on an attachment that a conversion holds now answers 409 `trust_optimize_attachment_busy` (with `source` and `since`) instead of a 200 response with a skipped/busy body; `wp trust-optimize sync-attachment` ends in an error.
- `queue_signals` is a list in the REST API and a comma-separated string in `wp trust-optimize status`.
- Pause now says what it does: attachments already queued are parked until Resume instead of being converted while the job is paused.
- Database schema 1.1.0: the jobs table gets `pause_reason`, and the unused columns `processed`, `skipped`, `failed_count`, `created_count`, `deleted_count` and `profile_hash` are dropped. Progress fields are unchanged in the admin, REST and WP-CLI; the job no longer has `profile_hash`. Reasons that were stored in `last_error` (`deactivated`, `low_disk_space`) move to `pause_reason`, and stale recovery sets `pause_reason` `stopped` instead of adding its "recovered from stale running state" message to `last_error`. Replacing the plugin with an older version after this update is not supported; roll back with a consistent backup of files and database.
- Requires WordPress 7.0 or higher (was 6.5).
- Bulk job timestamps (`started_at`, `updated_at`, `finished_at`) in the REST API and WP-CLI are now UTC, in the same `Y-m-d H:i:s` format. Values written before the update are not converted.

## [1.0.0] - 2026-10-05

### Added
- First public release. Converts the JPEG and PNG sizes that WordPress generates into WebP and AVIF on your own server; the originals are never modified. The formats the server can write are detected through `wp_image_editor_supports()`, and a format is switched off when a real conversion proves it does not work.
- `<picture>` delivery built over the core `srcset` and `sizes`: a format is offered only when it covers every `srcset` candidate, otherwise the original `<img>` is left untouched. Markup is processed with `WP_HTML_Tag_Processor`; `loading` and `fetchpriority` stay as core sets them.
- Variants are stored next to their source and named after it (`photo.jpg.webp`); their state lives in the plugin's own database tables. A variant that is not smaller than its source is not kept, and a variant is regenerated when its source file is replaced in place (detected by a changed file size on the next sync or metadata regeneration).
- Safe file handling: variants are written through a temporary file and `rename()`, never overwrite or delete a file that belongs to another attachment, and a file shared by several attachments is deleted only by its last owner.
- Background conversion with Action Scheduler: one task per attachment, an attempt counter for failing images, a pixel limit and a pause when free disk space runs low.
- Bulk conversion and removal of the whole library with progress, pause, resume and cancel, from the settings page, the REST API or WP-CLI.
- Admin pages: an overview with statistics and a bulk optimization panel, and a settings page grouped into delivery, formats and quality, limits and uninstall, with the largest image entered in megapixels (stored as `max_pixels`) and a reset to defaults that respects what the server supports.
- Media Library status column with batched status requests and polling that stops.
- WP-CLI: `wp trust-optimize` with `inventory`, `sync`, `status`, `pause`, `resume`, `cancel`, `remove`, `sync-attachment` and `remove-attachment`.
- REST API under `/trust-optimize/v1/` for statuses, bulk jobs and single attachments.
- Admin notices and Site Health tests for background tasks, supported image formats and free disk space.
- Filters `trust_optimize_should_render`, `trust_optimize_img_attributes`, `trust_optimize_cdn_hosts`, `trust_optimize_max_pixels`, `trust_optimize_min_free_disk_bytes`, `trust_optimize_worker_time_budget`, `trust_optimize_bulk_*`, `trust_optimize_uninstall_cleanup_*` and `trust_optimize_{format}_quality`.
- Multisite support: per-site settings, tables and queue, tables created on the first request of a site, documented system cron for every site of a network.
- Uninstall that removes generated files, tables and options only when asked to, keeps its tables and options when interrupted so that deleting the plugin again continues the cleanup, and supports networks.
- Requires PHP 8.0, WordPress 6.5 and MySQL 5.7 / MariaDB 10.3.
