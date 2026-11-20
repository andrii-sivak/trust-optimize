# Adversarial review: AD-18 directory, flag, deadline, lock, retention, conversion_id

- **Reviewed:** AD-18 as amended later on 2026-11-20 in `ARCHITECTURE-SPINE.md` (the rule, the sources line, Deferred "Diagnostics file names…"), read against AD-10, AD-11, AD-15, and AD-17.
- **Code read:** `includes/processing/AttachmentProcessor.php` (`sync` passes `INF`; `convert_pending` captures one deadline), `includes/storage/AttachmentRepository.php` (`claim`, `finish`), `includes/admin/Settings.php` (`sanitize`), `uninstall.php` (`trust_optimize_uninstall_site`, `trust_optimize_delete_plugin_options`), `wp-includes/option.php` (`get_option`, `update_option`), `wp-includes/functions.php` (`maybe_serialize`), `wp-includes/class-wpdb.php` (`process_field_formats`).
- **Method:** two units, one level down, each obeying AD-18, AD-10, AD-11, AD-15, and AD-17 as written. Owner decisions treated as fixed: URL residual risk, writer time inside the budget, a non-blocking lock, 60 days, and the 64 MiB / 8 MiB maxima. File names, the line-size number, and the age-cleanup interval stay deferred.

## Verdict

**fail**

Directory, deadline, and lock each collapse to one build. The flag, `conversion_id`, and uninstall retention do not. F1–F3 are wording. None of them reopens the accepted decisions.

## Findings

### F1 — "The stored value `1`" is not one flag once it meets the options API

- **Severity:** high
- **Disposition:** autofix

`update_option` runs the value through `maybe_serialize`, which leaves scalars as they are, then `$wpdb` binds `option_value` as `%s` (`class-wpdb.php` `process_field_formats`). `(string) true` and `(string) 1` are both `'1'`. `get_option` returns that string: `maybe_unserialize` does not turn `'1'` back into an integer. An absent row returns `false`.

**Two units.**

- **A** enables only on `get_option( 'trust_optimize_diagnostics' ) === 1`. The spine writes `1`, not `'1'`. After `update_option( ..., 1 )` the reader gets `'1'`, so A stays off forever. Absent is off. There is no checkbox. AD-15's per-site option is the row it reads.
- **B** enables on the string `'1'`, which is what the table holds. The same `update_option( ..., 1 )` turns B on. `update_option( ..., true )` also stores `'1'`, so B turns on for a boolean the other reading calls a different value. `'0'`, `'yes'`, and `'true'` stay off for both.

Same row, opposite recording. A never writes `uploads/to-logs`. B does. Variant status does not change either way, so isolation does not catch it.

**Proposed wording, in the Isolation bullet:**

> Only the string `1` that `get_option` returns enables it. Integer `1` is not a value that reader returns. Absent is off. Any other returned value is off. The option is its own row, not a key of `trust_optimize_options`, and the settings form does not create, change, or delete it.

### F2 — `conversion_id` joins a start to its result, and need not identify that pair

- **Severity:** high
- **Disposition:** autofix

The shared fields are site, job id, attachment id, attempt source, attempt count, size, format, and `conversion_id`. The id "joins a start line to its result, authorizes nothing, and is not stored." Nothing says it is unique, or that it is not a pure function of the other shared fields.

AD-10 makes the attempt count a weak key. `finish()` sets `attempts = 0` on every normal end, including continue and defer (`AttachmentRepository.php` around 251). The next queue `claim()` does `attempts + 1`, so the next attempt is 1 again. A sync `claim()` writes `1` every time (around 357). AD-11's defer is a normal end, so the same size and format are tried again under attempt count 1.

**Two units.**

- **A** draws a new `random_bytes( 16 )` per variant attempt, writes it only on that start and result, and never writes it to a table, option, or transient. It is not the attempt token. One id, one pair, for as long as the files are kept.
- **B** sets `conversion_id` to a hash of the other shared fields. It authorizes nothing, it is not the attempt token, and it is not stored in the database (AD-17 unchanged). The start and the result of one call match. The next sync, and the retry after a defer, reuse the id. A reader joins the new result to the old start. Both lines are inside the 60-day directory.

A second pair on the same sentence, once the gate exists. The gate "appends its own line." "Lines of one conversion share … `conversion_id`."

- **A** puts the same id on the gate line, the start, and the result.
- **B** leaves the id off the gate line, because a conversion "writes two lines of its own" and the gate line is a third. B joins the gate answer with the tuple that F2 already showed repeats.

**Proposed wording:**

> `conversion_id` is new for each variant attempt and unique among lines that site still keeps. It is not the attempt token and not a function of the other shared fields. The start line, the result line, and, when the gate exists, that gate line carry it. It is written only on those lines, never in a table, an option, or a transient.

### F3 — "Uninstall deletes `to-logs` only when the flag is set" allows two partial uninstalls

- **Severity:** medium
- **Disposition:** autofix

`trust_optimize_uninstall_site()` returns immediately when `remove_data_on_uninstall` is off (`uninstall.php` around 66). When it is on, generated-file cleanup runs first. If that cleanup does not finish, the function stores `trust_optimize_pending_cleanup` and returns without dropping tables or options (around 79–92). Options are removed only on the complete path, by the `trust_optimize_%` prefix delete (around 201–209). AD-15 already forces that walk to be per site, inside `switch_to_blog`.

"Only when" is a necessary condition. It does not say the flag is sufficient, and it does not say what happens when uninstall keeps the tables.

**Two units.**

- **A** deletes that site's `to-logs` as soon as the flag is set, including the branch that then times out and keeps the tables. Historical lines are gone. A later uninstall can still finish the image cleanup; it cannot finish anything from the log.
- **B** deletes `to-logs` only on the path that drops `trust_optimize_%` options, and deletes it before those options. A timed-out uninstall keeps the directory, as it keeps the tables, so a retry still has both. The flag off deletes nothing.

Both refuse to delete when the flag is off. Both are per site. Neither is an Action Scheduler action. After the same failed uninstall, A's site has no diagnostics and B's does.

**Proposed wording:**

> Uninstall deletes that site's `to-logs` only when `remove_data_on_uninstall` is set and this request drops that site's options, and it deletes the directory first. An uninstall that stops early and keeps the tables keeps `to-logs`. Deactivation does not delete them.

## Closed axes

Constructed and collapsed. Not findings.

- **Directory.** A uses `wp_upload_dir( null, false )['basedir'] . '/to-logs'`. B uses `WP_CONTENT_DIR . '/uploads/to-logs'` for every blog, or `wp_upload_dir()['path'] . '/to-logs'` (the year/month folder). On a subsite, or when `UPLOADS` or the upload path is not the default, B is not that site's `uploads/to-logs`. AD-15 gives each site its own uploads. The legal path is the per-site uploads basedir plus the directory name `to-logs`, with no secret suffix. Deny-file bytes are noise next to the caps; the URL risk stays the accepted one.
- **Deadline.** The queue deadline is the existing timestamp: `microtime( true )` plus `trust_optimize_worker_time_budget`, default 20 (`AttachmentProcessor.php` around 333 and 349). Writer time is on that clock. The timestamp is not moved forward. Fewer variants in the run are the accepted result; `finish()` still resets `attempts`, and the continuation is the normal `more` path, so the flag does not by itself fail the Action Scheduler action or count a death. Sync passes `INF` (around 202) and AD-11 requires it to return in the request and never defer. A second unit that puts sync on the 20 s budget when the flag is on changes the attachment outcome and sticks pending variants with no continuation. That unit breaks isolation and AD-11. One build remains.
- **Lock.** The size check and the append share one non-blocking try, and age cleanup uses that same try. Failure skips the line or the pass. The lock and the handle are not held across the editor or the encoder. A per-file lock with a distinct active file per process lets two writers (AD-11: sync stays outside the one-at-a-time slot) both pass the 64 MiB check and both append. That exceeds a maximum, so it is not legal. One site-wide try is required. `flock` and a non-blocking `GET_LOCK` can both be that try on one host; naming the syscall is not required for the cap or the skip rule to be one build.

## Not reopened

- URL residual risk, deny files, and no secret directory.
- Writer time stays inside the worker budget. The deadline is not extended. A shorter run is accepted.
- The lock try does not wait.
- 60 days from last write, and 64 MiB per site and 8 MiB per file as maxima. A busy site may keep less. A site that never runs age cleanup may keep files longer. The cleanup interval and the file names stay with the #26 spec.
- A missing or skipped line is not a cause and is not an input. AD-10 still uses the attempt row and Action Scheduler only.
