[← Domain index](README.md)

# Sales service release (Phase 5 Batch 1, Sales half — disabled backend)

2026-11-12 · tracker [#69](https://github.com/kaleemnadeem16/erp/issues/69) · scope [Batch 1 authorisation package](../phases/phase-5-batch1-authorisation-package.md) · direction [ADR 0186](../decisions/0186-deliver-one-progressive-jobs-engine-through-a-bounded-service-pilot.md).
**Inactive.** Both new duties are STAGED and ungranted, there is no route or screen, and a release refuses unless the Jobs module binds a receiver. Nothing here is an
operational delivery by itself; the Jobs receiver ships in the paired half.

## What exists

| Piece | Rule |
|---|---|
| `service_completion_bases` | Append-only, versioned per accepted issued line: the exact customer-agreed completion conditions, the **written, authority-bearing consent evidence** (same case that accepted the line) it cites, recorder, database-clock time, predecessor chain that cannot fork. The issued line text stays the deliverable text. Never backfilled from a checkbox: an acceptance must already exist. |
| `handover_release_events` | Append-only release evidence. Unique per `(stage, purpose)` (business identity) and per `(actor, request key)` (intent identity). Binds the stage, its baseline and the **head** basis; stores the condition identity/version, cost requirement and status (separate), the cost-policy version and the source generations relied on. Preparation rows are never updated: release status is derived from this row. |
| `RecordServiceCompletionBasis` | Duty `sales.handover.completion-basis.record` (STAGED). Idempotent; a changed text is the next version. |
| `PreviewServiceRelease` / `ReleaseHandoverComponent` | Duty `sales.handover.release` (STAGED). Read-only review returns blockers, cost and applicability status and the fingerprint the release must carry; the release is one transaction (`ReleaseCoordinator`). |
| `ReleasedServiceReceiver`, `JobSalesOrigin` | Sales-owned ports the Jobs module implements/binds. Unbound receiver ⇒ every release refuses (`RELEASE_RECEIVER_UNAVAILABLE`). |
| `SalesJobExecutionContextVerifier` | Implements the Jobs `JobExecutionContextVerifier` for the Sales origin only; takes Sales fences before the Job row is locked; an unsupported origin refuses. Not bound here. |

## Release transaction and lock order

Actor/early duty → (inside the transaction) fresh actor + duty → **authority head → acceptance → case → claim heads (ordered) → quotation revision** (the preparation order, `HandoverAccess::lock`)
→ receipt/replay → business-uniqueness check → basis (must be the head the reviewer saw) → readiness fingerprint → release event row → `ReleasedServiceReceiver::receive` → proof that the
receiver is still in the same connection, backend pid and transaction and durably linked exactly this release to the Job it reported. Any failure rolls back everything. A release only **reads**
the claim heads; it consumes no acceptance claim. `HandoverTransactionContext` reads the connection, transaction level, `pg_backend_pid()` and `txid_current()` from the database; a
caller-supplied boolean or hash proves nothing. `VerifiedServiceRelease` can only be issued by the coordinator.

## Readiness and policy

- Blocking facts reused from preparation: source Item, location, contact, site. The other preparation gaps are answered by: a recorded **completion basis**, the **cost policy**, the **applicability proof** and the **bound receiver**.
- **Cost** (`ReleaseCostPolicy`): cost *required* and cost *status* are separate. The default policy finds no mandatory commercial control, so cost is explicit `UNASSESSED` and does not block. A policy that makes cost mandatory blocks (`SERVICE_COST_EVIDENCE_REQUIRED`) until a supported source supplies evidence; the database also refuses a required cost that is not `EVIDENCED`. Preparation no longer lists `SERVICE_COST_CERTAINTY_UNKNOWN` unless the policy requires cost; the assessment carries a separate `cost` object.
- **No material / no subcontractor** (`SourceApplicabilityProof`): proved by the catalogue — no table outside the Jobs and handover owners may hold a foreign key to the lineage. Any such table makes applicability `UNKNOWN` and release and start refuse. Architecture ratchet `JobLineageIsolationTest` also fails if an Inventory, Purchasing or Imports table gains a column named like job/handover/lineage. Free text on stock/PO records is never evidence of attribution.

## Limits (deliberate)

No Jobs rows, numbering, route, UI, completion, closeout, cancellation or recovery. The mandatory-cost policy has no delivered source of cost evidence, so a *required* cost always blocks; only the default (not required) path can release. Existing acceptances have no completion basis until an authorised actor records one.

## Related

[Service preparation](sales-service-handover-preparation.md) · [Full acceptance](sales-full-acceptance.md) · [Duty matrix](sales-duty-matrix.md) · [Jobs module](../../backend/app/Modules/Jobs/README.md) · [Journey](../journeys/release-service-handover.md)
