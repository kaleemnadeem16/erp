[← Journeys](README.md)

# Release a prepared service handover (backend only, disabled)

Phase 5 Batch 1. No screen or route yet; this is the Action-level journey the Batch 2 screens will drive. See [Sales service release](../domain/sales-service-release.md).

1. An accepted, **prepared** service stage exists ([prepare journey](prepare-service-handover.md)).
2. An authorised Sales actor records the customer-agreed completion conditions (`RecordServiceCompletionBasis`); a material/subcontractor scope is stated only by a four-line declaration (`MATERIALS`, `SUBCONTRACTORS`, `LINE`, `DELIVERABLE_SHA256` of the issued description) that is the whole customer-evidenced text — no free text — and the scope is derived from it, never selected. The lightweight default is the issued line verbatim on the acceptance's own evidence; it states no material/subcontractor scope, so the preview stays blocked (`SOURCE_APPLICABILITY_UNPROVEN`). To release a pilot service, first record dedicated customer-agreement evidence (written, authority-verified, after the acceptance) stating that materials and subcontractors are excluded, then record the basis from that evidence's exact text (the declaration with both scopes `EXCLUDED`). Recording free-text conditions afterwards resets the head to no scope, so the release is blocked again until the declaration is re-recorded.
3. The releaser reviews (`PreviewServiceRelease`): blockers, cost status ("not assessed" is shown, never zero), source applicability and a fingerprint.
4. The releaser confirms (`ReleaseHandoverComponent`) with the basis and fingerprint they reviewed. With the Jobs receiver ([receiver doc](../domain/job-receiver.md)) this atomically records release evidence and creates exactly one numbered Job, its baseline, component and default activity; otherwise it refuses and nothing is written.
5. A retry (same key) replays; a fresh key or another actor resolves to the same single release; a changed basis after release is refused.

Failure paths: no basis, stale review, unproven applicability, mandatory cost without evidence, missing duty, suspended/revoked actor, receiver unavailable or failing — each writes nothing.
