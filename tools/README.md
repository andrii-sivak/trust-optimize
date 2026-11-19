# Dev tools

Wrappers around the Docker test stand (`docker-compose.yml` four levels above the plugin root, profile `tools`). Override the compose file with `TRUST_OPTIMIZE_COMPOSE_FILE`.

Prerequisites: the stand is running (`docker compose up -d`) and tool images are built (`docker compose --profile tools build`).

| Command | What it does |
|---|---|
| `tools/bin/compose <args>` | `docker compose` against the stand |
| `tools/bin/composer <args>` | composer in the PHP 8.0 container (minimum supported platform) |
| `tools/bin/wp <args>` | WP-CLI against the stand site (`/var/www/html`) |
| `tools/bin/test <unit\|integration> <8.0\|8.5> [wp-version\|latest]` | PHPUnit in the PHP 8.0 / 8.5 container |

## Resource limits (set 2026-11-19)

Each container of the stand has its own memory cap, CPU and PID caps and rotated logs (`json-file`, 3 × 10 MB). They are set in the stand's `docker-compose.yml`, apply to `up` and to every `compose run --rm`, wrappers or not, and kill a runaway process inside its container (exit 137) instead of taking the host.

The caps are per container. There is **no hard budget for the project as a whole**: every additional container (a second `wpcli`, a `composer` run beside a test) adds its own cap. Only test runs are kept to one at a time, see below.

| Service | RAM | + swap | CPUs | PIDs | Timeout | PHP `memory_limit` | ImageMagick memory / map / disk / threads |
|---|---|---|---|---|---|---|---|
| `db` | 768 MiB | 256 MiB | 1.5 | 256 | — | — | — |
| `php` (FPM, ≤ 5 workers) | 1.5 GiB | 512 MiB | 2 | 128 | — | 1024M (`config/php/custom.ini`) | 512 MiB / 1 GiB / 2 GiB / 1 |
| `nginx` | 128 MiB | 0 | 0.5 | 64 | — | — | — |
| `cron` | 1 GiB | 512 MiB | 1 | 64 | — (long-running loop) | 512M, but -1 in WP-CLI commands | 1 GiB / 2 GiB / 2 GiB / 1 |
| `wpcli` | 1 GiB | 512 MiB | 1 | 64 | 1800 s per run (`WPCLI_TIMEOUT`) | 512M, but -1 in WP-CLI commands | 1 GiB / 2 GiB / 2 GiB / 1 |
| `tools-php80`, `tools-php85` | 1 GiB | 0 | 2 | 256 | 900 s per run (`TOOLS_TIMEOUT`) | 512M, but -1 in integration tests | 512 MiB / 1 GiB / 2 GiB / 1 |
| `mysql57` (profile) | 768 MiB | 256 MiB | 1 | 256 | — | — | — |
| `phpmyadmin` | 256 MiB | 0 | 0.5 | 64 | — | — | — |

- **PHP is unlimited where it matters most.**
  - The WordPress test bootstrap defines `WP_MEMORY_LIMIT` as -1, and core then sets `memory_limit` to -1.
  - WP-CLI sets `memory_limit` to -1 after loading WordPress, which covers every `wp` command in `cron` and `wpcli`.
  - In both cases only the container cap stops a runaway process.
- **One test run at a time.** `tools/bin/test` refuses a second run (exit 75).
  - The lock is `.cache/tools-test.lock` next to the stand's compose file, whatever `TMPDIR` is.
  - The script also refuses while a container from an earlier run still works (label `trust-optimize.tools-run=test`), which happens when the compose client was killed but its container was not.
  - Without `flock` (util-linux) the script stops with exit 69.
  - `tools/bin/composer`, `tools/bin/wp` and manual `compose run` are not covered.
- **Timeouts.**
  - A timed-out one-off run is killed with SIGKILL, and its container stops (`init: true` reaps the rest).
  - Raise the timeout for one command: `TOOLS_TIMEOUT=1800 tools/bin/test integration 8.0 7.0`.
  - The `cron` loop has no per-step timeout. A stuck step holds the loop until the container is restarted, within the container's caps.
- **Change a limit:** edit the service in the stand's `docker-compose.yml`, then `docker compose up -d <service>`. One-off containers pick it up on their next run.
- **Roll back by hand**, in the stand directory. The configuration before the limits is `docker-compose.yml.bak-2026-11-19-pre-limits`, and the change is `resource-limits/docker-compose.limits.diff`.
  1. Check, then reverse the change: `patch -R --dry-run docker-compose.yml < resource-limits/docker-compose.limits.diff`, then the same without `--dry-run`. If the check fails, the file was edited since: compare it with the backup and undo the limits by hand.
  2. `config/php/cli-limits.ini` is then unused. Delete it only if you have not changed it.
  3. Apply: `docker compose up -d db php nginx cron`.
  4. In the plugin, revert the commit that changed `tools/`.
- **Check:** `docker inspect <container> --format '{{.HostConfig.Memory}} {{.HostConfig.MemorySwap}}'`, or `cat /sys/fs/cgroup/memory.max` inside it.

## Container facts (checked 2026-11-16)

| | `tools-php80` | `tools-php85` |
|---|---|---|
| PHP | 8.0.30 | 8.5.12 |
| `gd` `imagewebp()` | yes | yes |
| `gd` `imageavif()` | **no** | yes |
| Imagick WEBP / AVIF | yes / yes | yes / yes |

Extensions (both): ctype curl dom exif fileinfo gd iconv imagick intl json mbstring mysqli mysqlnd openssl pdo_sqlite sodium xml zip zlib.

Check: `tools/bin/compose run --rm -T tools-php80 php -m`.
