[← Phase index](README.md)

# R6 — Customer acceptance implementation slices

**2026-10-14 · SPECIFIED, not coding-authorised, IMPLEMENTED or VERIFIED.**
C1–C8 ACCEPTED in0125–0127; planning Q46 closed. Scope is the
[customer-decision domain contract](../domain/sales-customer-decisions.md), not permission to edit
implementation-owned CRM/C2 work. Owner coordinates any future coding handoff.

## Dependency and enablement contract

Every slice below needs separate code authorisation. Delivery follows R2 commercial scope, PR/FR/QL
interfaces, source cost/control ports and complete R9 permission/read-scope contracts. Disabled
writers must refuse at Request, Action and database integrity boundary; hidden controls alone fail.
R9 vocabulary/scope/grant architecture is now accepted0138–0140; no real role/person grant authorised. Duty matrix: ordinary evidence/case recording,
authoritative acceptance recording, late recognition, accepted-business correction, quote issue and
operational handover remain distinct. Proposed names belong in R9; no "Sales manager" inference.
R9 now specifies independent read eligibility, duty-specific effective scope, sensitive fields,
registry/explicit holder grants and mandate-aware re-sync consistent with0114. Ship grants/ratchets
with first surface, not a later patch. Unsupported limited scope is refused under0106.

First authoritative delivery can cover ordinary currently actionable **full independently acceptable
scope**, exact unchanged consent, supported Item/service/package/manual or full Assembly adapters,
and no operational target. An Assembly still requires its own full baseline/control support; listing
it here does not assert an adapter exists. Full acceptance of one independent line may leave another
unaccepted: this does **not** enable remaining-family replacement/withdrawal/reoffer automatically.

| Slice | Deliverable and gates | Required observable regression / demo |
|---|---|---|
| CA0 — Evidence/claim foundation | Add typed tenant-safe case/component/evidence/acceptance/claims/entitlement/receipt model; integrate R5 coordinator and FinancialActivity. No public writer before R9. Build explicit supported-adapter registry; missing port is unavailable, never zero claims. | Tenant FKs, immutable evidence, constrained counters/unique claims, direct invalid-write refusal, simultaneous missing-anchor creation; migration rollback/reapply and existing CRM unchanged. Demo feature remains disabled until coherent activation. |
| CA1 — Shared intake and observations | OpenCase/AppendEvidence Actions, Requests, Resources, scoped list/detail/evidence UI. Supported PO/signature/email/verbal provenance; no portal integration. Pending late/contradictory facts carry no accepted value or holds. R8 must govern upload/storage/download mechanics before binary evidence exposure; textual factual evidence can ship independently when R9 permits. | Same PO across employees/versions resolves shared case; genuine new release distinct; no-reference ambiguity pending, not magical dedup. Occurrence interval versus recording, stale version, forbidden attachment, no future fake occurrence. Demo ordinary/pending/revised PO and denied recorder. |
| CA2 — Ordinary full acceptance first | Preview/Record Actions with exact issued terms, current eligibility, source adapter, verified consent and atomic claims; receipt/business identity replay. Resource/readiness checklist and full confirmation sheet. R5 issue + R3/R4 frozen evidence and R9 approved/delivered required; R7 target creation unnecessary. | Full100 plus40/60 delivery intention records100 once; one independent optional scope can be accepted alone; expired/withdrawn/replaced or unverified consent refuses. Fresh-key/employee duplication, replay after expiry with current scope, changed-key intent conflict, competing family/option race. Demo accepted-not-ready and claim-blocked competitor without fake cancellation. |
| CA3 — Supported retained-price partials | Enable only explicitly offered DIVISIBLE adapters with compatible quantity/technical/pricing/tax/benefit rules. Cumulative consumption and per-component/base residual evidence. R7 baseline/correction dependencies required for affected adapters; no generic fraction switch. |40/60 eligible original100 conserves all caps;40/70 race no shrink; package increments/minimums;0.03 budget splits conserve0.03; changed consent quantum refused; fixed tax unsupported refuses. Demo retained-price40gross352 plus60gross528 only where original entitlement explicitly offered. |
| CA4 — Reoffer/remaining operations | Use R5 whole-unaccepted reoffer first. After any accepted slice, replacement/withdrawal/reoffer of remainder remains disabled until **necessary R6 and R7 contracts approved AND delivered**, including baseline, capacities, correction/claim ownership. No general acceptance variation editor. | Original100gross880 unchanged; newly consented40gross396, remaining60 unoffered; later60gross594 only after gates and fresh consent. New number cannot reset benefit lineage or accepted root cap. Simultaneous acceptance/replacement stale loser; no successor retry. |
| CA5 — Governed late recognition/correction | Evidence capture exists in CA1; authoritative recognition and accepted-business corrections stay disabled until dedicated R9 authority, historical proof and R7 competing commitment/correction adapters delivered. Workflow uses shared0070/0085 where required, not universal maker/checker. | Late valid external40at9 is never rewritten to10; conflicting later target blocks recognition pending authorised plan; no reopen/reversal/backdating. Demo sensitive duty separation and pending reconciliation, no real sensitive role grant without owner assignment. |
| CA6 — Projections and operational integration | Expose accepted, available, claim-blocked, unoffered, withdrawn/rejected and fulfilled independently; versioned readiness and bounded R7 consumption interface. R9 report/forecast trust and dated FX publication contracts before forecast/WON changes; R8 customer projection/delivery separately. | No revision double-count, optional unaccepted not sale, target split not extra acceptance, provisional forecast stays provisional; repeated handover fresh key cannot double consume when R7 delivered. Demos mixed goods/service with no mandatory Job/account. |

## Per-slice implementation delivery checklist

- **Actions/Requests:** implement domain table inputs, double guards, exact evidence/version binding,
  fail-closed adapters, one coordinated transaction and scoped receipt replay. Console/import/job
  callers use the same Actions. Read-only previews never reserve or create authoritative claims.
- **Resources/OpenAPI/UI:** one safe contract for availability versus acceptance versus readiness;
  no financial internals leaked in hashes/errors. Generate client from real endpoints, register all
  routes/read scopes and ratchets, use standard sheets/hooks/states and source-aware quantities.
- **Migrations:** additive tables/indexes/immutable guards, composite company FKs, positive quantity,
  cap constraints and cross-row enforcement; register FinancialActivity on first monetary table.
  Explain real down path; refuse destructive rollback after evidence exists where required. Apply,
  rollback and reapply on isolated DB. Existing issued/imported acceptance claims cannot be assumed
  absent; writer inventory and truthful baseline/import reconciliation before enablement. No invented
  historic consent, original receipt times, complete cost or CustomerAccount. Label captured baselines.
- **Concurrency/idempotency:** receipt and business component identities separate; stable anchors,
  ordered locks and all R5 conflicting writers integrated. Deterministic race tests with real separate
  transactions/connections, including receipt uniqueness race and expiry boundary. No uniqueness
  exception exposed as500; winner lookup still checks company/current access and matching intent.
- **Evidence/demos:** append-only source/consent snapshots, authority/reason where required, both times,
  exact component/FX/budget lineage. Idempotent Sales demo fixtures with genuine independent purchases,
  duplicates, denied scope, pending facts, supported/unsupported partials and accepted-not-ready.
- **Regression checks:** meaningful Action-direct and HTTP denial, cross-company and per-duty scope,
  hidden competitors, cost/attachment redaction, lifecycle/quantity/choice races, stale source tokens,
  price/tax/FX unchanged after master changes, typed pending-vs-accepted and exact rounding examples.
  Approval-required conditions cannot be bypassed via alternative writer. Existing full-unaccepted
  R5 flows and independently delivered CRM/C2 consumers must remain compatible.
- **Completion:** domain/API/client/demo/journeys/status alongside delivered slice; run applicable
  pre-push matrix (backend checks, ratchets, migration cycle, generated drift, client/build and UI
  browser checks for actual changed surfaces). Report results/owner accurately. Documentation here
  passes no implementation test and authorises no application tests in this planning session.

## Exact remaining gates

**R7:** accepted0131–0132 and 0136; adapters NOT DELIVERED/VERIFIED. Specified source→target mapping, Sales Order versus optional Job, one/many disjoint consumption,
schedules versus new commitments, site/contact readiness, original estimate/material/cost baseline,
target business uniqueness, accepted amendments/cancellations and who may release which claims.
A target cancellation cannot release customer commitment by assumption. R7 approval alone is not
adapter delivery. Partial Assembly/fixed charge/unsupported alternatives remain refused.

**R8:** delivery/channel evidence, customer-safe document rendering, protected file access, signatures/
portal and withdrawal communication. Ordinary verified manual consent need not await a portal, but
must not invent transmission/receipt or legal effect. Upload implementation waits its security contract.

**R9:** recording/read/late/correction/handover duties and grants, effective record/field scope, hidden
claim conflict projection, reports/partial-win/outcome/forecast basis and explicit valuation/trust.
R9 is required before public R6 routes, including CA1; schema representation confers no capability.

**Finance/Phase5:** statutory tax/invoicing/payment/credits and actual execution/stock postings remain
separate. No operational readiness or future Finance acceptance is fabricated by R6.

## R8 evidence reconciliation — accepted2026-10-15

0135 and [delivery/evidence contract](../domain/quotation-delivery-evidence.md) distinguish acknowledgement/signature from
commercial consent. Only R6 Action establishes accepted business with exact revision/scope/terms/
authority and claims. Company/path method may require exact document/delivery; no universal email,
portal or PDF receipt prerequisite displaces legitimate verified manual/PO consent. Binary upload/
access and affected method integration still need actual secure R8 adapters and R9 grants/scope.
Late/conflicting recognition retains CA5/R7 delivered-impact gates. Architecture is not verification.

## R9 closure and coordinated delivery —2026-10-15

R9 A1–A10 accepted0138–0140/Q49 closed. [Canonical duty matrix](../domain/sales-duty-matrix.md),
[safe rollout](phase-4-r9-permission-rollout.md) and [R9 slices](phase-4-r9-implementation-slices.md)
resolve previously proposed vocabulary/grant architecture; historical candidate names above yield
to this reconciled catalogue, not duplicate guards. Actual guards/holder inventory/adapters/verification
remain required before affected public routes. [Consolidated handoff](phase-4-implementation-handoff.md)
defines small dependency batches; no implementation/live grant authorisation. Partial-family and
downstream gates stay until exact affected contracts actually delivered.

## Related

- [R7 accepted contract and actual-delivery gates](phase-4-r7-implementation-slices.md) — R7 authority is accepted0131–0132 and 0136; necessary adapters remain undelivered, so affected partial-family/correction gates remain.

- [R6 final accepted review](phase-4-r6-customer-decisions.md)
- [Numerical journey](phase-4-r6-allocation-and-journey.md) · [Evidence/claims](phase-4-r6-evidence-and-claims.md)
- [R7 research](phase-4-r7-handover-research.md)
- [R5 slices](phase-4-r5-implementation-slices.md) · [Question coordination](../architecture/implementation-question-reconciliation.md)
- [Pre-push checks](../architecture/pre-push-checks.md)
