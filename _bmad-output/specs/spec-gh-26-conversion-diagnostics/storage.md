# Storage, cleanup, and checks

Companion to `SPEC.md`. The directory, the 60 days, the two size caps, the accepted URL residual risk, and the non-blocking lock are AD-18. This file is the mechanism.

## Where the files go

`wp_upload_dir()['basedir'] . '/to-logs'`

The uploads basedir is already per site, including `uploads/sites/{id}` on multisite, and it survives a PHP worker dying. There is no random suffix and no token option. The directory is created on the first write while the flag is on.

The plugin writes three guards when it creates the directory, and replaces them if they are missing later: `.htaccess` denying all access, a `web.config` denying all access, and an `index.php` that exits. Directory mode `0750` and file mode `0640` are attempted. A failed mode change does not stop conversion. The plugin never concatenates `baseurl` with this path and never prints a URL.

Those files help where the server honors them. Where the server serves `uploads/` as static files and ignores `.htaccess`, the directory can be fetched. The owner accepts that residual risk. The plugin does not add a secret directory to close it.

## Caps

| Limit | Value | What it costs |
| --- | --- | --- |
| Line | 1280 bytes | Fits the agreed fields. A longer line is skipped whole |
| Active file | 8 MiB | Owner's starting maximum. Then a rename |
| Site total | 512 MiB | Owner's maximum. A busy site keeps less than 60 days |
| Age | 60 days | Owner's figure, from file mtime. Not a second, shorter clock |

The full result example in `events.md` encodes to 846 bytes. The same keys with a 100-character size, a 64-character reason, an 80-character editor class, and 64-bit integers encode to 1187 bytes. 1280 sits above that. Required keys are not dropped to squeeze a line in, and a partial JSON line is not written.

The active file is `YYYY-MM-DD.jsonl` in UTC. The first write of a new UTC day leaves yesterday's file closed, so its mtime can age out. When the active file reaches 8 MiB, the writer, holding the lock, renames it and opens a new active file. The new name is `YYYYMMDDTHHMMSS.uuuuuuZ.jsonl`. If that path exists, the writer appends `-2`, `-3`, and so on before the rename. Two rotations in the same second therefore differ. Deny files and `write.lock` do not count toward the cap and are not deleted for age or size.

On every write, after the lock is held, the writer sums `*.jsonl` and deletes whole rotated files, oldest first, until the line it is about to append fits under 512 MiB. It does not delete or rename the active file it has open for this append. It does not run the 60-day delete on that path. If the line still cannot be stored, the write is skipped and the conversion continues.

## Writes

One lock file, `write.lock`. The writer tries an exclusive flock with `LOCK_NB`. If the try fails, it writes nothing for that line and returns at once. A background conversion and a synchronous one do not wait on each other.

When the lock is acquired, the writer checks the size cap, appends one encoded line and a newline, calls `fflush`, closes the data file, and unlocks. It does not `fsync`. It does not hold the lock or the data file across `get_editor_for_target_mime()` or `save_with_quality()`.

A failure to create the directory, take the lock, encode, or write is caught inside the writer. It does not reach `AttachmentProcessor::convert_claimed()`, so it is not stored as `exception`, not rethrown, and not a death. The conversion continues. That line is not retried. A skipped or missing line establishes nothing about the process.

Seconds spent in the writer are part of the worker's existing deadline. The processor does not add them back.

## Cleanup

Age cleanup deletes `*.jsonl` whose mtime is older than 60 days. It runs at most once an hour from `admin_init` when the current user can `manage_options`, using the option `trust_optimize_log_pruned_at` (autoload off, UTC mysql), and it runs from uninstall. It does not run on every diagnostics write. It takes the same non-blocking lock; if the lock is busy, that pass does nothing. It is not an Action Scheduler action, and nothing schedules one.

A site that never opens wp-admin can keep files past 60 days until uninstall or the next admin load. The 512 MiB cap still applies on the next write. Deactivation does not delete the directory.

Turning the flag off does not delete files. Age cleanup on `admin_init` still runs, and it does not sample CPU, PHP memory, or the cgroup.

Uninstall follows the existing flag. `to-logs` is deleted only when `remove_data_on_uninstall` is set and this request goes on to drop that site's `trust_optimize_*` options, and the directory is deleted first. An uninstall that stops during generated-file cleanup and keeps the tables keeps `to-logs`. Without the flag, the directory stays. There is no token option to preserve. If a deletion that should have happened leaves the directory behind, the existing uninstall `error_log` names `to-logs`.

## Checks

These are the acceptance checks. They do not need the resource gate or a new image.

| Check | What passes |
| --- | --- |
| Off | Option absent. Convert the test JPEG. No `to-logs` directory. A subclass that counts cgroup reads and `getrusage` samples stays at zero. Variant status and bytes match a run with the writer not constructed. The settings screen has no diagnostics checkbox |
| Flag | A missing option and the string `1` write a line. The stored string `0` does not, and age cleanup still deletes an old file. The settings form checkbox is checked when the option is missing and unchecked when it is `0`. Posting `0` and `1` through the registered setting stores those strings. Saving the other settings leaves a stored `0` in place and does not copy the key into `trust_optimize_options` |
| Budget | After a write, the worker deadline is the same value it had before the write. It is not increased by the writer's duration |
| Start is early | A test editor's load reads the log. The start line is already complete and contains `conversion_id`. The result line after load repeats that id. Neither the variant row nor any option contains the id |
| Row match | A `done` or `not_smaller` result from this call matches the variant row's status and reason. `not_pending`, `attempt_lost`, and `row_gone` are logged as the call returned them, and the test does not require the row to show that reason. The writer performs no variant or attachment update |
| Lock busy | Hold `write.lock` in the test. The writer returns without a new line and without blocking the test's lock holder. The variant status matches the off run |
| Write error | Replace `to-logs` with a file, or make it unwritable. The conversion status, reason, and output bytes match the off run. The test fails if an exception escapes |
| Names | Two rotations performed in the same UTC second produce two different file names, and both names end in `.jsonl` |
| Cap | Fill the site past 512 MiB with rotated files. The next write, holding the lock, removes the oldest rotated file and still appends. The variant status matches the off run. Age cleanup is not invoked by that write |
| Age | A JSONL file with mtime 61 days ago is removed by a direct age-cleanup call. A file one day old remains. The Action Scheduler table gains no `trust-optimize` action from that call |
| Line | A payload that encodes over 1280 bytes is not appended. The file's last line is still valid JSON. No key is removed from a line that was written |
| Fields | A `missing_file` result contains the reason code and does not contain a path, a URL, a file name, or a `message` key. The attempt token is absent |
| Scope | With the cgroup file unreadable, the line has `cgroup` of `unavailable` and no `cgroup_memory_current_bytes`. On the tool container, where `/sys/fs/cgroup/memory.current` is readable, that field is an integer |
| Sites | Under multisite, a line for blog 2 is absent from blog 1's `to-logs` |
| URL | The settings page, a REST status body, and `wp trust-optimize status` contain no `http` URL of `to-logs`. The directory contains `.htaccess`, `web.config`, and `index.php`. The test does not request the URL |

## Overhead on the stand

Not a CI gate. Encode time swamps the log, and the disk is not stable enough to fail a build. The deadline is not adjusted, so this measurement is what tells us how many fewer variants fit in one run.

During implementation, inside `tools-php80`:

1. Call the writer's start and result, 1000 pairs, against `to-logs` on the uploads filesystem, with no image editor. Report median and p95 of a pair.
2. Convert the existing test JPEG to WebP three times with the flag off and three times on, under the same worker deadline. Report the wall-time delta and whether the on run returned `more` sooner. Both numbers are evidence, not a pass mark.
3. Confirm a variant line from that container carries integer `cgroup_memory_current_bytes` when `/sys/fs/cgroup/memory.current` is readable, and that the environment line's `cgroup_memory_max` is digits or `max`, matching `cat /sys/fs/cgroup/memory.max` in that container.

Record the numbers in the pull-request text. Do not add a benchmark command to the repo for this.
