[← Phase index](README.md)

# R9 — Authority, audit, reports and forecast implementation slices

**2026-10-15 · A1–A10 ACCEPTED0138–0140/Q49 closed; SPECIFIED, not new application IMPLEMENTED/VERIFIED.**
Every AU/RP batch needs separate implementation authority. Approved names are not live grants.
Use [duty matrix](../domain/sales-duty-matrix.md), [authority](../domain/sales-authority.md),
[forecast/reporting](../domain/sales-reporting-and-forecast.md) and [rollout](phase-4-r9-permission-rollout.md).

| Slice | Implementation-ready deliverable/API/UI boundary | Dependencies, security and scope | Observable checks/demo |
|---|---|---|---|
| AU0 — Inventory/vocabulary manifest | Module-owned exact registry plan, ACTIVE/RESERVED list, code-slice owner reconciliation, all-writer/surface/holder inventory, no public writer | Current CRM/C2/shared source contracts, eight CRM unchanged; Tax owner path and Sales review-registry permission explicit; no duplicate generic download/submit | Literal-string registration/owner tests; source-current review; dormant entry points unreachable; current frontend WIP preserved |
| AU1 — Safe registration/grants | Idempotent registration/exclusions, approved explicit grant migrations and full-access mandate reconciliation; administration effective-access summary | Owner-reviewed holder manifest and equivalent predecessor evidence; retain independent scopes/explicit excluded grants, no live assignment authorised by this spec | Apply/rollback/reapply; sync twice; no admin-name inference, foreign/future mandate excluded, role→direct scope preserved; demo only approved fixture personas |
| AU2 — Source/field/scope guards | Exact duty guard/access helpers, owner adapters, Resources/API/UI allowed-action/minimum projections for each delivered PR/FR/QL/CA/HA/DOC surface | AU0/1 for active duties, RecordScope reused; company-wide initial mode, unsupported limited refusal; independent case/target/file eligible read | HTTP+direct Action+SQL tenant/profile/field denials; list/count/history/lookup/filter/sort/export/download/cache parity; cost-blind PASS/WARN/FAIL |
| AU3 — Limits and reference evaluation | Versioned authority/reference models, ResolveSalesAuthority/EvaluateQuotationAdjustment Requests/Resources and settings/evaluation sheets | AU2 + PR0/PR4 supported controls, FR0/1/4 decimal/FX; policy highest priority/tie refuse; no general bypass | Automatic10→discretionary20 example; input+aggregate ceilings, fixed-unit/line, split/revision reference unchanged, missing/zero, actor/delegate scope, stale policy/source/FX races |
| AU4 — Approval adapters | Quotation commercial/operational and above-discount subjects, exact review snapshots, routing/Consume approval, shared inbox/subject UI | AU3 where amount control, QL2/0070/0085/0087; register Sales technical profile without generic PO double gate | Same-actor only where configured, delegation principal counts/ceilings, no approval→issue, same-total source changes invalidate, protected reasons/limits |
| AU5 — Execution/audit receipts | Human/service typed context, append-only operation/authority evidence, current-access receipt replay and import/console/worker adapters | All AU2 delivered writers, existing Sales coordinator/identity; service company/subject/purpose/fence exact; source events durable | Changed key conflict/fresh-key business duplicate; revoked actor/read, service cannot impersonate/accept/post, truthful occurrence-recorded/provider unknown, race after business write rollback |
| RP0 — Measure/source projections | ReadSalesMeasures typed accepted/effective/available/blocked/unoffered/disposition/actual adapters; no new acceptance ledger | AU2 + actual QL/CA heads, HA/actual adapters only for operational measures; missing adapter UNKNOWN, not zero | No revision/target-stage double count; partial acceptance leaves OPEN,0107 closure with remainder; absent Finance/actual not reported none |
| RP1 — Forecast basis | MAINTAINED/SELECTED_OFFERS versions, Maintain/Select Actions/Requests/Resources, explicit scoped basis UI | RP0 + actual eligible QL/CA remaining-value adapters, AU2/5; no automatic successor/maintained subtraction | Selection overlaps/competitors, unsupported partial/fixed components refused, maintained acceptance flag; no probability weighting |
| RP2 — Valuation | ValueForecast models/Action/Request; dated base bridge/rates/source/age/policy/settled amounts, explicit valuation/history UI | RP1 + delivered FR0/1/4 reusable primitives and Currency read/invalidation; forecast policy separate from quote FX | Exact/prior-age bridge, identity legs, missing/future/unsupported rate incomplete, mixed currencies/conservation; commercial FX unchanged |
| RP3 — Trust and review | Invalidation heads/events and ReviewForecast with coexisting reasons/profile-safe diagnostics, persistent review UI | RP1/2 source writer inventory, AU4 if configured; every CA/QL/CRM/policy/Currency relied writer plus clock expiry integrated | Source/rate correction/delete race, ordinary later date no invalidation, technical recovery cannot trust, no cron-only freshness |
| RP4 — Atomic publication | PublishForecast freezes exact basis/valuation/review/source versions plus immutable receipt; publication/detail UI | RP3 + canonical locks/generations across source writers; all required current authority/approval checked | Separate-transaction publish versus acceptance/withdrawal/expiry/rate/policy/revoke; fresh-key result identity; failed review no trusted publication |
| RP5 — Historical/scoped reports/export | Known/effective-as-of and labelled restatements, visible-scope totals, combined comparable management valuation, export/cache guards | RP0–4 for forecast publication reports; accepted-only reports need actual CA not renderer; AU2/5; no report-source scope bypass | Late PO known Wednesday, original Monday publication unchanged, hidden contributors/competitor not inferable, no zero unknown, current access on export/cache replay |
| RP6 — Integrated activation | Actual route/scope/client/UI/demo/runbook/holder manifest, feature/path support matrix and staging sign-off | Only actually delivered prerequisites for feature; unsupported modes remain refused; separate release authority | Narrow personas/full journey, source-generation coverage, safe downgrade/backout; accurate implemented-versus-verified public status |

## Per-slice implementation obligations

- **Models/migrations:** logical domain tables become additive tenant-safe FKs/unique identities/
  immutable facts with source generations, FinancialActivity on monetary tables, real down and
  isolated fresh/rollback/reapply. Shared Approvals/FX/RecordScope/receipt/Document infrastructure
  reused, not duplicated. No inferred historical consent/forecast/trust/grant provenance.
- **Actions/Requests/API:** named owning operations enforce duty/scope/field/authority internally
  and at HTTP boundary; non-HTTP same path, exact keys/version/policy evidence, canonical source
  locks/re-read. Stable refusal reason meanings with safe Resources; OpenAPI/generated client and
  ratchet route/read/permission/scope inventory committed with actual API, not docs-only placeholders.
- **UI:** configure versioned limits/policies and explicit holder review where authorised, exact
  allowed actions/protected-field omission; reports distinguish accepted/remainder/partial outcome,
  basis/date/FX/trust/coexisting diagnostics and known/effective history. Standard sheets/states,
  keyboard/light/dark/phone; no missing API control, client arithmetic or hidden security check.
- **Demos:** idempotent narrow approved fixtures for ordinary quote drafter/issuer, cost-blind
  evaluator, commercial/operational reviewer, evidence recorder versus acceptor/corrector, file
  reader versus recovery operator, maintained/selected forecast and denied/limited/suspended users.
  No real grants, credential repair, production seeding or external email in planning/demos.
- **Verification:** per-duty HTTP/Action/worker/tenant/profile/unknown/refusal cases; raw-DB constraints,
  separate real-connection races for authorisation/replay/claims/publish, scope-before-aggregate,
  exact rounding/FX/reference examples and immutable history. Run actual applicable pre-push matrix
  incl. migration/ratchets/backend/client drift/build/browser for changed implementation; code owner
  reports evidence/commit/date/limits and staging owner. Planning checks only documentation.

## Activation boundary

AU foundation is not authorisation to register/grant in production. Every new public PR/FR/QL/CA/HA/
DOC/DF route needs AU0 manifest, AU1 safe registration/authorised holders, AU2 complete projection/
scope guards and AU5 execution/audit appropriate to its operation. AU3/4 before financial controlled
transitions; RP slices before new forecast/trust/reports. Already existing authorised CRM/C2 surfaces
stay independent, no global retrofit or new blanket read gate. Internal foundation may stage disabled
with no reachable new entry point. Actual limited scope/adapter impossible means affected route refuses.

R5 partial-family replacement/withdrawal/reoffer, CA3 affected technical partials, CA5 conflicts and
HA6 correction keep exact actual-delivery dependencies; approved R9 neither provides downstream
Finance/Inventory/Phase5 impacts nor enables claim-release/returns/repricing/cancellation. DOC6 email,
DOC7 future extensions, PR6 advanced and FR6 unsupported tax methods remain separately gated.

## Related

- [Consolidated implementation handoff](phase-4-implementation-handoff.md)
- [Rollout](phase-4-r9-permission-rollout.md) · [Duty matrix](../domain/sales-duty-matrix.md)
- [Accepted R9 review](phase-4-r9-authority-reporting-review.md) · [Pre-push checks](../architecture/pre-push-checks.md)
