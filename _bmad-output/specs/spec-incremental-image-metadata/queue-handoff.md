# Queue handoff and verification

## Baseline

- Repository baseline is `main` at `a04b7e2` with a clean working tree before spec creation.
- In installed WordPress 7.1.2, `WP_REST_Attachments_Controller::create_item()` suppresses server-generated sizes for `generate_sub_sizes=false`. The client uploads sub-size files and sends their metadata to `finalize_item()`. The controller merges the sizes, calls `wp_generate_attachment_metadata` with context `update`, then calls `wp_update_attachment_metadata()`. The server upload path generates sizes through core and still fires the generation filter.
- `ConversionQueue::handle_new_metadata()` currently drops its metadata argument before `VariantPlanner::eligible_source()` reads the saved value. `AttachmentProcessor::run()` consumes already-planned variant rows. It rereads during conversion, but a late insert between its last variant count and `AttachmentRepository::finish()` can leave a terminal attachment with pending variants and no action.

## Implementation boundary

Pass the filter snapshot through the existing plan-and-enqueue path into the planner. Use it for both eligibility inspection and desired sizes, with the existing database read as the fallback for calls that omit a snapshot. Keep the attached-file lookup and existing replacement and removal behavior.

Use the existing enqueue CAS after the plan writes its rows. While queued, the action already owns the work. While processing, the worker can pick up the rows on a later pass. Close the remaining race in the normal queue worker's token-guarded completion CAS. A terminal close must be conditional on no pending work in current database state. If late rows prevent that close while the same attempt still owns the attachment, release to `queued` and return the existing continuation result. An insert after a successful terminal close belongs to the planner's enqueue CAS. Avoid a cached or separate read as the only guard. Repeated events and no-work recomputes must preserve the active attempt and the scheduling owner's queue state.

Keep continuation scheduling in `ConversionQueue::process()` at priority 20. A lost token or an incomplete publication authorizes no continuation. Preserve the existing poison limit, recovery, pause and scheduling-failure paths. A paused bulk stays paused until explicit Resume.

## Verification

| Case | Evidence |
| --- | --- |
| Stale stored metadata | Store the original-only snapshot and create actual intermediate source files. Apply the generation filter with the complete payload. Assert that the filter returns it unchanged, the database still holds the old metadata, and the expected new variant rows were planned. Then persist through core. |
| Queued or processing | Deliver the late event before the first claim and during conversion. Assert that the event preserves action/attempt ownership and leaves at most one pending conversion action. Drain the queue and inspect ready rows and files. |
| Closing race or completed first task | Deterministically place the event between the last worker count and its terminal write. Assert one continuation and successful drain. Separately deliver the event after terminal completion and assert one new enqueue and successful drain. |
| Repeat, ordinary upload and guards | Replay identical metadata while active and after drain. Check row identity, file hash and encode count for unchanged ready variants. Exercise server upload and existing regeneration, attempt, pause and publication regressions. |
| HTML | Compare the fallback `<img>` before drain and the candidate URLs, descriptors and `sizes` after drain. Use both formats when supported on the stand. |

Run PHPCS and the unit and integration suites through the wrappers listed in `AGENTS.md`. Manually upload a new image through the client-side flow on the stand, let the normal queue drain, and inspect rows, files and rendered HTML. Then run the existing per-attachment sync (`tools/bin/wp trust-optimize sync-attachment 276` on the stand), verify the original's ready variants are preserved and the missing sizes and full HTML candidate sets appear.
