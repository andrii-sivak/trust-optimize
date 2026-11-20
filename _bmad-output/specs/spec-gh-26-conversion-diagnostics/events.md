# Events and measurement points

Companion to `SPEC.md`. This is the v1 cut against the code on main `0d69d04` and against AD-18.

## Where a line is written

`ImageConverter::convert()` is the only encode. Order today: claim the variant row, resolve the source, `wp_filesize`, `get_editor_for_target_mime()` (this loads the file), `set_quality`, `save_with_quality()` (this encodes). The load and the save are the heavy calls. A start line that waits until `save` misses a death inside the load.

`conversion_id` is a fresh `random_bytes( 16 )` at the start of that variant attempt, before the editor opens. It is not a hash of the attachment, the attempt count, or the size. The result line copies it. A later attempt draws a new value, because a finished attempt resets the count and the next one would otherwise look the same. Nothing writes it to a table, an option, or a transient.

| Point | Event | When |
| --- | --- | --- |
| Planner skip in `AttachmentProcessor`, before any editor | `attachment_skip` | One line for the attachment. Not a start. It has its own `conversion_id`. |
| `convert()`, source file exists, before `get_editor_for_target_mime()` | `variant_start` | Flushed, then the editor opens. |
| `convert()` returns, including fail and skip after the start | `variant_result` | Same `conversion_id`. Editor class and durations live here. |
| First logged line of a `job_id` in this process, and again when the fingerprint changes | `environment` | Flushed before the editor opens, ahead of the start line. Remembered in the process. Not deduplicated by reading the file. A null `job_id` writes once per process until the fingerprint changes. No `conversion_id`. |

A missing source, `not_pending`, and the other exits before the editor are a `variant_result` with no start. A start line means the heavy call was about to run. If either write is skipped, the lines that do exist still match on `conversion_id` when both were stored. A missing mate establishes nothing.

`job_id`, `attempt`, and `attempt_source` are taken once from the attachment row the processor already claimed. The converter does not query them per variant.

Queue polls, the producer, `set_quality` by itself, and each editor fallback inside `get_editor_for_target_mime()` are not events. The load duration covers that whole method.

## What v1 keeps

Cheap and already in hand, or one syscall that says what it is. These are the only fields.

| Asked for | v1 field | Scope label |
| --- | --- | --- |
| Time | `ts` | UTC `current_time( 'mysql', true )` |
| Site, job, attachment, variant | `blog_id`, `job_id`, `attachment_id`, `variant_id` | Ids. `job_id` null outside a bulk |
| Attempt | `attempt`, `attempt_source` | Integer count and `queue` or `sync`. Not the token |
| Pair | `conversion_id` | 32 hex characters. Joins start to result. Not stored. Authorizes nothing |
| Size, format | `size`, `format` | Size name from the row, not a file name |
| Source dimensions and bytes | `source_w`, `source_h`, `source_bytes` | Dimensions the planner or the row already has. Bytes from a `wp_filesize` already done. Otherwise null, not a new stat or a new header read |
| Requested quality | `quality_requested` | Settings value, on start and result |
| Editor and reported quality | `editor`, `quality_reported` | Result only. Class name, then `(int) get_quality()` |
| Load and encode time | `wall_load_ms`, `wall_encode_ms` | Process wall clock (`hrtime`) of those two calls. Result only |
| CPU | `cpu_load_user_ms`, `cpu_load_system_ms`, `cpu_encode_user_ms`, `cpu_encode_system_ms` | `RUSAGE_SELF` of this process. Not the host |
| PHP memory | `php_bytes`, `php_peak_bytes` | PHP allocator pages (`memory_get_* ( true )`). Peak is since process start. Excludes native encoder allocations |
| Container memory | `cgroup_memory_current_bytes`, `cgroup_oom_kill` | The process cgroup, including neighbour workers. The counter is the cgroup's, not a count of our deaths |
| Outcome and output size | `status`, `reason`, `output_bytes` | Status and reason returned by this `convert()` call. They match the row only when this attempt completed the transition. `output_bytes` is the encoded file size when one exists, including a file later discarded as `not_smaller` |

`status` is `done`, `skipped`, or `failed`, the words this `convert()` call returned. `reason` is that call's machine code. Where the attempt completed the transition (`done`, `not_smaller`, and a `fail()` that updated the row), `status` and `reason` match the row afterwards. `not_pending`, `attempt_lost`, and `row_gone` are recorded as returned; the row is not required to agree, because this call did not leave it in that state. The writer does not update the row. The converter's sentence is not a field.

## What v1 leaves out

| Candidate | Why it waits |
| --- | --- |
| Free-text error, path, URL, file name | Owner's field list. The reason code stays |
| `sys_getloadavg` | It is the whole host. The pace controller is not in this release |
| PSI and `cpu.stat` throttle delta | Controller inputs. They describe the cgroup, not this encode |
| Frontend p95 | Another request's lifetime. Out of scope |
| `fsync` | `fflush` is what survives a process death. A disk sync per variant dominates a small WebP |
| File hash, action id, attempt token | The token authorizes the attempt. The action id costs a lookup |
| Full environment on every variant | The hash is enough. The block is the `environment` event |
| In-flight step or `conversion_id` on a row | AD-18 keeps the event stream out of the database |
| Second process or external post | Forbidden |
| Deadline extension | Writer time stays inside the existing budget |

Cgroup limit files (`memory.max`, swap max, `cpu.max`) are on the environment event only. `cpu.max` is stored as the raw text (`max`, or the quota and period). It is not turned into a percent. `memory.max` of `max` is stored as the string `max`, not as a byte count.

## Honesty

- Do not copy `php_bytes` into a cgroup field. If the cgroup cannot be read, set `cgroup` to `unavailable` and `cgroup_reason` to `not_found` or `not_readable`, and omit the numeric cgroup fields. Cache that result for the process.
- If `getrusage` is missing, set `cpu` to `unavailable` and omit the CPU numbers.
- JSON `null` means the phase did not run (encode never started). It is not the same as `unavailable`.
- A start line with no result line means the result was not recorded. A result with no start means the start was not recorded. A lock that was busy means that line was skipped. None of these means the kernel killed the process. Nothing writes that conclusion into a row, and nothing counts the gaps.
- `php_peak_bytes` going up can be an earlier variant in the same process. The field does not say "this encode allocated this much".

## Examples

`conversion_id` below is `c3a1f0e47b2d4a6e8f0192ab34cd56ef`. The full result line encodes to 846 bytes.

Start, cgroup readable. Written and flushed before the editor opens. No editor, no durations.

```json
{"v":1,"event":"variant_start","ts":"2026-11-20 12:00:01","blog_id":1,"job_id":14,"attachment_id":6315,"variant_id":88,"attempt":2,"attempt_source":"queue","conversion_id":"c3a1f0e47b2d4a6e8f0192ab34cd56ef","size":"original","format":"avif","source_w":2059,"source_h":1786,"source_bytes":110240,"quality_requested":62,"php_bytes":41943040,"php_peak_bytes":50331648,"php_memory_scope":"php_allocator_pages_excludes_native","cgroup_memory_current_bytes":1072144384,"cgroup_memory_scope":"cgroup_including_neighbours","cgroup_oom_kill":7,"cgroup_oom_scope":"cgroup_counter","env":"a1b2c3d4e5f6"}
```

Start, cgroup not readable.

```json
{"v":1,"event":"variant_start","ts":"2026-11-20 12:00:01","blog_id":1,"job_id":null,"attachment_id":6315,"variant_id":88,"attempt":1,"attempt_source":"sync","conversion_id":"c3a1f0e47b2d4a6e8f0192ab34cd56ef","size":"medium","format":"webp","source_w":300,"source_h":200,"source_bytes":12000,"quality_requested":85,"php_bytes":20971520,"php_peak_bytes":20971520,"php_memory_scope":"php_allocator_pages_excludes_native","cgroup":"unavailable","cgroup_reason":"not_readable","env":"b2c3d4e5f6a1"}
```

Result after a finished encode. `wall_*` is process wall clock. `cpu_*` is `RUSAGE_SELF`. Same `conversion_id` as the start.

```json
{"v":1,"event":"variant_result","ts":"2026-11-20 12:00:09","blog_id":1,"job_id":14,"attachment_id":6315,"variant_id":88,"attempt":2,"attempt_source":"queue","conversion_id":"c3a1f0e47b2d4a6e8f0192ab34cd56ef","size":"original","format":"avif","status":"done","reason":null,"editor":"WP_Image_Editor_Imagick","quality_requested":62,"quality_reported":62,"output_bytes":12191,"wall_load_ms":120,"wall_encode_ms":8900,"wall_scope":"process_wall_clock","cpu_load_user_ms":40,"cpu_load_system_ms":15,"cpu_encode_user_ms":8600,"cpu_encode_system_ms":180,"cpu_scope":"rusage_self","php_bytes":44040192,"php_peak_bytes":52428800,"php_memory_scope":"php_allocator_pages_excludes_native","cgroup_memory_current_bytes":980000000,"cgroup_memory_scope":"cgroup_including_neighbours","cgroup_oom_kill":7,"cgroup_oom_scope":"cgroup_counter","env":"a1b2c3d4e5f6"}
```

Result when encode did not run. `wall_encode_ms` is `null`, not zero. There is no `message`.

```json
{"v":1,"event":"variant_result","ts":"2026-11-20 12:00:02","blog_id":1,"job_id":14,"attachment_id":6315,"variant_id":88,"attempt":2,"attempt_source":"queue","conversion_id":"c3a1f0e47b2d4a6e8f0192ab34cd56ef","size":"original","format":"avif","status":"failed","reason":"no_editor","editor":null,"quality_requested":62,"quality_reported":null,"output_bytes":null,"wall_load_ms":30,"wall_encode_ms":null,"wall_scope":"process_wall_clock","cpu":"unavailable","php_bytes":41943040,"php_peak_bytes":50331648,"php_memory_scope":"php_allocator_pages_excludes_native","cgroup":"unavailable","cgroup_reason":"not_readable","env":"a1b2c3d4e5f6"}
```

Planner skip. No start line exists for this attachment. `source_w` and `source_h` are the numbers the limit check already computed. `source_bytes` is null because that path does not call `wp_filesize`. A variant start still has `source_bytes`, because `convert()` already measures the file before it opens the editor.

```json
{"v":1,"event":"attachment_skip","ts":"2026-11-20 12:04:00","blog_id":1,"job_id":14,"attachment_id":7001,"attempt":1,"attempt_source":"queue","conversion_id":"9f0e1d2c3b4a5968778695a4b3c2d1e0","reason":"insufficient_memory","source_w":12000,"source_h":12000,"source_bytes":null,"env":"a1b2c3d4e5f6"}
```

Environment, once per process per job until the fingerprint changes. Settings are the numbers, not a dump of the option. No `conversion_id`.

```json
{"v":1,"event":"environment","ts":"2026-11-20 12:00:01","blog_id":1,"job_id":14,"php":"8.4.27","sapi":"fpm-fcgi","wp":"7.0","memory_limit_bytes":536870912,"max_execution_time_s":300,"gd":"2.3.3","gd_webp":true,"gd_avif":false,"imagick_ext":"3.8.1","imagemagick":"7.1.1-43 Q16","imagick_limits":"unavailable","imagick_limits_reason":"not_readable","editors":["WP_Image_Editor_Imagick","WP_Image_Editor_GD"],"cgroup_memory_max":"1073741824","cgroup_swap_max":"1073741824","cgroup_cpu_max":"200000 100000","cgroup_scope":"process_cgroup_limits","convert_to_webp":1,"convert_to_avif":1,"webp_quality":80,"avif_quality":62,"env":"a1b2c3d4e5f6"}
```

`env` is the first 12 hex characters of a sha256 of the canonical JSON of the environment payload, excluding `ts`, `job_id`, and `env` itself. The same fingerprint in another SAPI is a different hash, so FPM and WP-CLI each get a line.

Every line is one JSON object, key `v` equal to `1`, no pretty-print, newline terminated. `wp_json_encode` escapes newlines inside strings. A line over 1280 bytes is not written. The writer does not delete keys and does not append a broken line.
