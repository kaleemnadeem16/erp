[← Domain index](README.md)

# Sales service release (Phase 5 Batch 1, Sales half — disabled backend)

2026-11-12 · tracker [#69](https://github.com/kaleemnadeem16/erp/issues/69) · scope [Batch 1 authorisation package](../phases/phase-5-batch1-authorisation-package.md) · direction [ADR 0186](../decisions/0186-deliver-one-progressive-jobs-engine-through-a-bounded-service-pilot.md).
**Inactive.** Both new duties are STAGED and ungranted, there is no route or screen, and a release refuses unless the Jobs module binds a receiver. Nothing here is an
operational delivery by itself; the Jobs receiver ships in the paired half.

## What exists

| Piece | Rule |
|---|---|
| `service_completion_bases` | Append-only, versioned per accepted issued line. Two honest ways to record one (the database guard enforces both): **ISSUED_LINE** — the issued line's description verbatim, citing the acceptance's own consent evidence, no scope stated (the lightweight default); **EVIDENCE_TEXT** — new conditions or an `EXCLUDED`/`REQUIRED` materials/subcontractor scope, only on **dedicated written, authority-verified evidence of the accepting case** that is not the acceptance's own consent evidence, recorded after it, whose factual description *is* the agreed text. One line cannot cite the same evidence twice. Recorder, database-clock time and a predecessor chain that cannot fork. |
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
- **Scope classification integrity (review P1).** The material/subcontractor classification is NOT a flag the operator selects. An `EVIDENCE_TEXT` basis's agreed text must begin with the strict two-line header `MATERIALS: <EXCLUDED|REQUIRED|UNSTATED>` / `SUBCONTRACTORS: <EXCLUDED|REQUIRED|UNSTATED>` (LF line ends, each keyword once, optional free text after), and its cited customer-agreement evidence must carry exactly that text. `RecordServiceCompletionBasis` takes no scope parameter: it parses the header (`ScopeHeader`, fixed grammar) and **derives** `materials_scope`/`subcontract_scope`; the database guard (`service_completion_basis_header_scopes`, migration `2026_12_13_400000`) requires the stored columns to equal the header and refuses a missing, reordered, duplicated or malformed header, so raw SQL cannot contradict the evidenced text. Free text is never interpreted (no keyword reading): the header is the classification the customer-evidenced record states. Independent verification that the evidence is genuine remains the optional company policy of [#114](https://github.com/kaleemnadeem16/erp/issues/114). The ISSUED_LINE path always stays UNSTATED, even if the line description looks like a header.
- **No material / no subcontractor** (`SourceApplicabilityProof`, #122, version `SOURCE_APPLICABILITY.2`) — `NOT_APPLICABLE` needs **all three**: (1) the customer-agreed scope itself `EXCLUDED` both materials and subcontractors on an `EVIDENCE_TEXT` basis; (2) **every** source family answers `NOT_APPLICABLE` through the neutral `App\Support\JobSource` contract (`INVENTORY_MATERIAL`, `PURCHASING_SUPPLY`: no supported, validated Job binding exists; generic source provenance is never a Job allocation); (3) the foreign-key catalogue is clean (secondary only). A required scope or a family reporting a supported binding is `APPLICABLE` (blocker `SOURCE_OBLIGATION_UNSUPPORTED`; the first pilot refuses). Anything else — including the **issued-line default basis, which states no scope**, an unregistered family, `SERVICE_ITEM`, an empty query or the absence of foreign keys — is `UNKNOWN` (`SOURCE_APPLICABILITY_UNPROVEN`). So a company that records only the issued-line basis cannot release a pilot service until it records scope evidence. The result is pinned (basis head, scopes, every family status and contract version) in the release's relied-on facts; `SalesJobExecutionContextVerifier` re-proves it for fresh work (start) and refuses when any pinned fact changed. Production registration of the family authorities belongs to the Inventory and Purchasing owners; without it the proof is `UNKNOWN`.

## Limits (deliberate)

No Jobs rows, numbering, route, UI, completion, closeout, cancellation or recovery. The mandatory-cost policy has no delivered source of cost evidence, so a *required* cost always blocks; only the default (not required) path can release. Existing acceptances have no completion basis until an authorised actor records one.

## Related

[Service preparation](sales-service-handover-preparation.md) · [Full acceptance](sales-full-acceptance.md) · [Duty matrix](sales-duty-matrix.md) · [Jobs module](../../backend/app/Modules/Jobs/README.md) · [Journey](../journeys/release-service-handover.md)
