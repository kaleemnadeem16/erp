[← Phase index](README.md)

# Q55 — refined unified-costing resolution for owner review

**2026-10-16 · PROPOSED final business resolution and contract candidate.** Owner supports reporting
principles; no new ADR accepted, physical schema fixed, application code/grant/migration/push authorised.
Reuse [existing primary comparison](phase-5-unified-costing-research.md),0145–0149/R7/R9; no repeated
vendor research needed. This refinement narrows supported architecture rather than reopening sources.

## Three practical examples

**Small Job, no estimate or hours:** recognised amount-only labour15000.0000 and evidenced specialist
8000.0000 produce recognised known contribution23000.0000. Show no hours captured/no baseline available,
source valuation and category coverage separately. If material coverage is unassessed, do not claim
complete Job cost. Optional recording never blocks supported completion. No fabricated estimate,
quantity1, zero labour or margin percentage.

**Partial installation:** original baseline100000.0000, current authorised plan110000.0000 and recognised
known actual60000.0000. Current-plan spending gap50000.0000 is not50000.0000 savings. Outstanding supplier
commitment20000.0000 and accepted unpriced work stay separate with overlap labels; do not total them
as actual or infer final cost/progress. Original baseline comparison preserves its different version.

**Late cost corrections:** recognised60000.0000 includes supported material consumption and external
performance.0040 Inventory value adjustment1000.0000 makes current known actual61000.0000 with physical
quantity unchanged. Prior immutable report60000.0000 remains dated evidence. Invoice confirming the
external work adds nothing; authorised difference uses0149 reconciliation. No ordinary Job reopen.

## Recommended resolution

1. One coherent logical source-owned attribution/correction model and rebuildable reporting projection;
   reuse suitable existing patterns before deciding storage. Reports/rebuilds never repost effects.
2. Initial measures use the exact labels, evidence and eligibility in the [contract candidate](../domain/job-unified-costing-candidate.md):
   original estimate/baseline and current authorised plan where available; recognised known actual by
   category/source; correction bridge; separate outstanding commitment and provisional/unpriced exposure;
   valuation/coverage/completeness; only compatible comparisons and labelled spending gaps.
3. Preserve one effective source/allocation contribution, owning-domain identity/revisions/receipts and
   correction lineage. Shared quantities/amounts and company remainder conserve source; no duplicate
   actuals from child projections, later invoice/payment or stock purchase stages.
4. Initial priority is current-restated cost and immutable saved dated evidence where actually supported.
   Incurred cutoff, recorded/known date, selected baseline, source generations and coverage basis remain
   explicit. Arbitrary historical reconstruction is unavailable until verified, never guessed.
5. Keep future ETC/EAC evidence/overlap semantics but defer forecasting from first implementation scope.
   Spending/open POs/manual progress are insufficient; unknown overlap refuses trusted complete forecast.
6. Apply independent source/category/whole-Job completeness and current-authorised restricted projections.
   Missing source/baseline is not zero; unknown/withheld/unsupported remains explicit, without leakage.
7. Authoritative reports require verified adapters, consistent source snapshot/generations and reconciled
   projection freshness.0040/0149 remain mandatory correction gates. Missing required effects refuse safely.

## Existing infrastructure inspection —2026-10-16, HEAD42494ba

Source review only; no runtime tests or claim of delivered Job infrastructure:

| Inspected existing pattern | Reuse recommendation / limit |
|---|---|
| Sales Models/OperationReceipt + Support/CrmOperation | Exact keyed intent/result and transaction conventions; CRM subject/table/actor context is domain-specific, not shared Job storage; current authority remains owning caller duty |
| Sales Support/Kernel/OperationReceiptPort | Reusable interface idea, explicitly no generic implementation/table supplied |
| Sales CorrectOpportunityAttribution + correction model/history | Predecessor/source chains, stale checks and append-only evidence; corrects recorded responsibility, not monetary cost; no direct reuse as cost ledger |
| Inventory Models/StockValuationEntry | Append-only stock-move valuation source, not a general Job ledger; keep Inventory ownership and0040 correction participants |
| QueryMaterialCost documented read session/snapshot | Consistent-read conventions; advisory hypothetical costing, not actual posting or arbitrary as-of reconstruction |
| Shared Quantity/Currency/RecordScope support | Existing precision/context/scope primitives; no universal cost-projection engine established by these helpers |

Inspected shared/module paths did not reveal a reusable generic economic attribution/projection engine.
SalesEvent is history vocabulary, not evidence of an event bus or event-sourced accounting framework.
Recommendation: synchronous owning Actions and immutable identity/receipt/correction conventions,
with rebuildable read projection; prove any extraction/reuse in later physical design. Do not create
new event-driven complexity, repurpose CRM/Inventory tables or finalise a new ledger schema now.

## Failure and reconciliation cases

| Case | Required outcome |
|---|---|
| Source fully valued, Job coverage unassessed | Known amount only; no complete total/margin claim |
| No/partial/incompatible baseline | Comparison unavailable/partial with explanation; no zero substitute |
| Full baseline versus partial actual | Spending gap, not efficiency or final variance |
| Duplicate direct/performance/invoice representation | Reconcile same component once or expose ambiguous overlap |
| Concurrent source correction + coverage/plan/report read | Common verified generation/snapshot or retry/refusal |
| Failed required0040/0149 participant | No unsafe partial authoritative contribution/report |
| Stale projection/cache or rebuild interruption | Disclose stale/incomplete or refuse trusted report; never repost source |
| Restricted reader and saved whole-Job snapshot | Current-authorised projection/refusal; no inference from totals/differences/exports |
| Historical date without reliable source history | Explain unavailable reconstruction, not current values labelled historical |
| Valuation-only correction on closed Job | Append linked value correction, preserve physical/source/report history |
| Commitment/ETC overlap unknown | No trusted complete EAC; advanced forecasting deferred |

## Remaining owner choices and next review boundary

No new unresolved business alternative is required beyond final confirmation of this refined resolution
and candidate: first release excludes ETC/EAC, prioritises current-restated reporting and defers physical
storage until infrastructure compatibility is proven. Source-specific adapter readiness and precise
snapshot/lock implementation are delivery evidence obligations, not choices for the owner to guess.
Any newly discovered semantic conflict returns for review; Q55 remains AWAITING_OWNER, not closed.

The [Phase5 overview](phase-5-job-execution.md) now separates accepted architecture, this pending
candidate, remaining ordered research, deferred capabilities and implementation gates. Next milestone
is a reviewed bounded service-pilot planning/handoff package, not an implemented Job module.

## Related

- [Contract candidate](../domain/job-unified-costing-candidate.md) · [Prior research](phase-5-unified-costing-research.md)
- [Phase5 overview](phase-5-job-execution.md) · [Questions](../OPEN-QUESTIONS.md)
