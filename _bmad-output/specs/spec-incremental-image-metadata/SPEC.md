---
id: SPEC-incremental-image-metadata
status: done
baseline_commit: a04b7e25a32e3ae4eb316e3f3204978a9182f1c7
issue: https://github.com/andrii-sivak/trust-optimize/issues/52
companions:
  - queue-handoff.md
  - ../../planning-artifacts/architecture/architecture-trust-optimize-2026-11-16/ARCHITECTURE-SPINE.md
sources: []
---

# Plan variants when attachment metadata arrives in stages (#52)

## Why

Client-side WordPress uploads can add intermediate sizes after TrustOptimize plans the original. The finalization filter already supplies those sizes, but planning reads the previously stored metadata. Attachment #276 consequently has WebP and AVIF variants of the original only. Neither format covers the image's full `srcset`, so the page serves the original `<img>`.

## Capabilities

- **CAP-1**
  - **intent:** Plan eligible variants using the metadata available at each upload or regeneration event.
  - **success:** With only original metadata persisted, a generation filter event carrying intermediate sizes creates the expected variant rows before WordPress saves the new metadata. A normal server-side upload still converts its generated sizes. Existing regeneration tests for replaced and removed sizes pass.
- **CAP-2**
  - **intent:** Convert late sizes whether the original conversion is queued, processing, or complete.
  - **success:** Each ordering drains the eligible variants, including an insertion between the worker's final read and closing write. Replaying the same event leaves valid files and ready rows intact and produces no duplicate action or encode.
- **CAP-3**
  - **intent:** Serve responsive optimized candidates once their variants are ready.
  - **success:** The original markup falls back to the same `<img>` while neither enabled format covers all candidates. After conversion, the `<picture>` contains a complete `srcset` for each ready format and preserves the original fallback, descriptors and `sizes`.

## Constraints

- Use the supplied filter snapshot throughout planning. Preserve the returned metadata and let WordPress persist it.
- Preserve queue ownership, attempt limits, bulk pause, publication and file ownership under AD-3 through AD-5, AD-7, AD-10 and AD-13 through AD-14. Keep the existing source eligibility and reconciliation rules.
- Use the existing async action and queue failure recovery. Add an Unreleased changelog entry.

## Non-goals

- Automatic reprocessing of the library, renderer changes, and a new job, hook or schema.
- The diagnostic OOM counter, earlier spec corrections, and CI exit 127.

## Success signal

A fresh client-side upload on the stand reaches complete WebP and AVIF candidate sets when both encoders are available. An explicit per-attachment sync restores the missing variants of #276 and its page serves the complete `<picture>`.

## Review Triage Log

| Finding | Verdict | Evidence and disposition |
| --- | --- | --- |
| Unguarded sync close references pending variants | high | Both initial full integration runs reproduced a stuck sync attempt under a variant write failure. The unguarded close now omits the variant table, and the existing failure-injection test passes. |
| Eligible no-work plan can clear a concurrent queued state | medium | The planner formerly read `skipped` before clearing it. The queue-preserving write now performs the reset atomically, with a deterministic enqueue regression test. |
| Old aggregate can overwrite a newer terminal result | medium | A recompute based on an older read can still race a terminal write, as it could before this change. Generic terminal aggregate serialization is outside this fix. |
| Guarded close can select an empty continuation | low | A different plan that deletes the pending rows after the guard refuses close can leave one empty continuation. Replaying an identical additive snapshot cannot trigger that deletion. |
| Metadata event at synchronous close | medium | A synchronous claim has no Action Scheduler continuation. The approved timing matrix covers the initial upload task, and synchronous behavior remains outside this change. |
| Metadata event during a throwing worker | medium | The exception path closes the attachment as failed and rethrows so Action Scheduler records the failure. This change handles normal worker completion and preserves that failure path. |
| Finalize replaces the original during conversion | medium | Reconciliation already skips a processing row whose source changes. Source replacement during an active encode is distinct from late additions to the same original. |
| A different caller plans stale stored metadata before core persists | medium | A separate sync or bulk request can reconcile against a different snapshot in that interval. Coordinating independent plans across core persistence is outside the approved upload-task timing matrix. |
| Ineligible regeneration can leave an old queued action | low | Existing skip handling removes variants without cancelling an already queued action. Eligibility transitions for an already queued attachment are outside the late-size fix. |
| Replay test observes processing transitions | low | The test isolates one processing callback and checks per-row transitions, completed rows and hashes. The SQL observation measures state transitions directly. |
| Replacement and removal tests saved the updated snapshot first | medium | The previous ordering would miss a planner that silently reused stored size keys. The tests now assert behavior before persistence. |
| Staged automated checks use WebP only | low | The stand check used a real Gutenberg upload and verified seven ready WebP and seven ready AVIF rows plus both complete source sets. |
| Staged tests simulate the filter instead of the client REST sequence | false | The prescribed stand check exercised original upload, six sideloads and the REST finalize call through Gutenberg. |
