[← Docs index](../README.md)

# Roadmap

Eight phases, each independently shippable. Current state is in
[STATUS.md](../STATUS.md) — this file is the plan, that file is the progress.

| Phase | Name | Status | Detail |
|---|---|---|---|
| 0 | Foundation | Complete | [phase-0-foundation.md](phase-0-foundation.md) |
| 1 | Master data | Complete | [phase-1-master-data.md](phase-1-master-data.md) |
| 2 | Purchasing & stock | **Complete** (closed 2026-10-08); the multi-owner correction package is carried to Phases 4–6 | [phase-2-purchasing-stock.md](phase-2-purchasing-stock.md) |
| 3 | Estimating core | **Core complete** (closed 2026-10-08); adoption and enhancements deferred to Phase 4 / later | [phase-3-estimating-core.md](phase-3-estimating-core.md) |
| 4 | Sales, quotations and job handover | Research/discussion; implementation not started | [Phase 4 plan](phase-4-sales-quotations-and-handover.md) |
| 5 | Job execution | Not started | *expanded when reached* |
| 6 | Finance | Not started | *expanded when reached* |
| 7 | Mobile & integrations | Not started | *expanded when reached* |

Phase docs are written in detail **when the phase is about to start**, using
[templates/phase.md](../templates/phase.md). Writing them all up front produces speculative
fiction that has to be rewritten anyway.

Screen ownership follows [user-interface.md](../architecture/user-interface.md). Each phase writes
its detailed interaction specification when it starts; backend completion alone does not defer an
operational UI silently to a later phase.

User-facing entry-to-exit workflows live in [product journeys](../journeys/README.md). A phase's
journey is maintained alongside its screens and is the source for future training documents or
presentations.

The [physical package-balance delivery](package-balance-delivery.md) is a separate workstream (ADRs 0052, 0090–0094) that extends the purchasing package masters
without starting Phase 5 job execution. **Grouped package tracking v1 is complete** — see its [handoff](package-balance-handoff.md) and
[v1 sign-off](package-balance-ui.md#v1-sign-off-2026-10-08); container-level tracking and the other enhancements are future design slices.

---

## Outline

### Phase 0 — Foundation
Environment and cross-cutting scaffolding, no business logic. Docker Compose, production
Dockerfile, Laravel + React skeletons, PostgreSQL-only CI, Sanctum auth, companies, users, roles,
audit log, operable company member/role administration, UoM system, custom fields, numbering
sequences, Scramble → Orval pipeline. Business modules continue to contribute their own stable
capabilities as they are implemented; companies bundle those capabilities into roles rather than
waiting for a fixed application-wide role catalogue.

**No dependency on any open question — can start immediately.**
→ [phase-0-foundation.md](phase-0-foundation.md)

### Phase 1 — Master data
Items and categories, customers and suppliers (unified party model,
[ADR 0030](../decisions/0030-unified-party-model.md)), currencies and exchange rates (manual
entry, [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)), chart of accounts. The GL
account mapping on items is defined here even though posting comes later. Backend-only — no new
frontend screens.

*Formerly blocked by Q2 and Q9 — base currency and exchange-rate source now decided
([ADR 0024](../decisions/0024-onboarding-base-currency-and-fiscal-year.md),
[ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)).*
→ [phase-1-master-data.md](phase-1-master-data.md) · [items.md](../domain/items.md) ·
[parties.md](../domain/parties.md) · [multi-currency.md](../domain/multi-currency.md)

### Phase 2 — Purchasing & stock
Purchase orders, supplier sourcing (`item_suppliers`,
[ADR 0033](../decisions/0033-item-suppliers-relationship.md)), goods receipt, warehouses and
locations, stock moves, per-item valuation (AVCO/FIFO/SPECIFIC,
[ADR 0032](../decisions/0032-per-item-valuation-method.md)), physical counts. The negative-stock
and backdated-receipt policies must be decided here. Inventory onboarding also establishes a
controlled cutover/opening state without weakening live policy
([ADR 0036](../decisions/0036-controlled-inventory-cutover.md)).
→ [phase-2-purchasing-stock.md](phase-2-purchasing-stock.md) · [stock.md](../domain/stock.md)

### Phase 3 — Estimating core
**The differentiator.** Formulations with versioning and customer overrides, rate dimensions,
application rates, assemblies, cost roll-up, and a configurable hierarchical overhead policy
scoped through Assembly Groups ([ADR 0049](../decisions/0049-assembly-group-overhead-scope.md)).
→ [phase-3-estimating-core.md](phase-3-estimating-core.md) · [formulations.md](../domain/formulations.md) · [application-rates.md](../domain/application-rates.md) · [assemblies.md](../domain/assemblies.md)

### Phase 4 — Sales pipeline

Research and discussion have started; see the [research plan](phase-4-sales-quotations-and-handover.md),
[existing-system review](phase-4-existing-system-review.md) and [R1](phase-4-r1-leads-and-relationships.md).
[R1 research is complete](phase-4-r1-decision-matrix.md), governed by accepted ADRs 0098–0108;
Q39 resolved. [R2 quotation structure/scope](phase-4-r2-quotation-structure.md) U1–U6 is approved
in accepted ADR 0109; Q40 resolved. [Verified impact matrix](phase-4-r2-compatibility-audit.md) and
[compatibility plan](phase-4-r2-compatibility-plan.md) passed owner review; bounded R2 research is
formally closed. [C0 proposal](phase-4-c0-cost-evidence-contract.md) / [C1/C2 slices](phase-4-c1-c2-cost-evidence-slices.md)
have owner approval and C1a–c/C2a–b code authority (accepted 0110/Q41 resolved); C1d remains gated.
[Actual cost dependencies](phase-4-cost-dependency-map.md) support correcting existing APIs together
with their consumers; the compatibility-first draft is historical. Local commits only.
[Initial capability review](phase-4-r2-existing-capabilities.md) and [ERP comparison](phase-4-r2-erp-comparison.md)
remain linked evidence. CRM backend exists; R2 quotation implementation remains pending and C1/C2 implementation is in progress.
[CRM security handoff](phase-4-crm-security-correction.md) is ready;
[three-policy review](phase-4-crm-policy-review.md) is accepted in 0111–0113;
[correction specification](phase-4-crm-correction-specification.md) is ready for coordinated delivery,
with B’s authority accepted in 0114/Q42 closed and safe grant/re-sync rollout specified.
[R3 retained evidence](phase-4-r3-sales-pricing.md), [approved P1–P8 review](phase-4-r3-business-review.md),
[expanded research](phase-4-r3-expanded-research.md) and [worked example](phase-4-r3-worked-example.md)
support accepted 0115–0117/Q43 closed. [Implementation slices](phase-4-r3-implementation-slices.md)
and [resolution contract](../domain/sales-pricing-resolution.md) are specified, not code-authorised;
R4–R9 gates remain. [Accepted question coordination](../architecture/implementation-question-reconciliation.md)
uses OPEN-QUESTIONS and coding-owned verification.



Leads, opportunities, quotations with revisions, cost snapshotting, PDF output. Mobile layout
patterns should be settled before this. RTL is not needed — English only
([ADR 0026](../decisions/0026-english-only-no-rtl.md)).

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once sold stock exists, a backdated-recalculation correction
delta touching it needs a Sales/COGS correction line — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."

*Formerly blocked by Q3 — tax model now decided
([ADR 0025](../decisions/0025-configurable-tax-engine.md)).*
→ [sales-pipeline.md](../domain/sales-pipeline.md)

### Phase 5 — Job execution
Jobs, work orders, material issue, timesheets, unified job cost entries, estimate-vs-actual
variance reporting. This is where the system starts paying for itself.

**Inventory package tracking — largely delivered ahead of this phase.** The package-tracking design the owner required (confirmed 2026-10-01) is built as the grouped
`PACKAGE_BALANCE` mode (`item_packagings`, sealed and open/loose state, issue, transfer, count, reversal and QA; v1 complete 2026-10-08). **Still owed before material issue is
designed:** the `CONTAINER_TRACKED` mode (individual vessels and remnants), package-aware proposals in the Material Preview, and the waste/residual variance bands that need them
([ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md); the hand-built stock mix it must extend is
[ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md); variable-content packages stay
[Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages), alongside
[Q24](../OPEN-QUESTIONS.md#q24--phase-5-material-exceptions)).

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once job-consumed stock exists, a correction delta touching it
needs a Job Execution job-cost correction line — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."
→ [job-costing.md](../domain/job-costing.md)

### Phase 6 — Finance
Double-entry GL, progress invoicing, receipts, allocation, customer ledger and statements,
aging, realised FX. The largest phase.

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once the GL exists, a correction's economic allocation needs a
balanced-journal representation, not just Inventory's own valuation-adjustment feed — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."

*Formerly blocked by Q1 and Q3 — retention and tax model now decided
([ADR 0023](../decisions/0023-retention-retainage-enabled.md),
[ADR 0025](../decisions/0025-configurable-tax-engine.md)).*
→ [general-ledger.md](../domain/general-ledger.md) · [receivables.md](../domain/receivables.md)

### Phase 7 — Mobile & integrations
PWA polish, Capacitor wrapper, barcode scanning, push notifications, OAuth connection framework,
Gmail.
→ [ADR 0015](../decisions/0015-capacitor-for-mobile.md)

---

The [Item and Purchasing improvements](item-purchasing-improvements.md) sequence tracks the
pack-conversion usability deliveries and the research gates for further Purchasing extensions.

The [Settings, forms and access hardening](settings-and-access-hardening.md) record tracks the company/settings screens, the Purchase
Order form redesign and the access-security audit slices, with what is done, what is open and how to resume.

## Sequencing notes

**Phases 3–5 are the differentiator** and the hardest part. Phases 0–2 are table stakes, but
everything else depends on them being right — particularly stock valuation, which feeds every
cost figure in the system.

**Phase 6 could partially precede Phase 5** if invoicing matters more than variance reporting.
The GL is a hard dependency for neither, since [stock](../domain/stock.md) postings can be
buffered and posted once accounts exist.

**Do not build extension points speculatively.** The seams listed in
[extensibility.md](../architecture/extensibility.md) are enough. Further abstraction waits for a
real second customer ([Q8](../OPEN-QUESTIONS.md#q8--second-customer-profile)).

## Related

- [Rejected-stock exit (supplier return and scrap) — implementation plan](rejected-stock-exit-plan.md) — ADR 0097, accepted, not started
- [Package feature handoff](package-balance-handoff.md) — grouped package tracking v1 is complete; lists known limitations and future enhancements, independent of other agents' workstreams

- [Grouped package tracking — screens and API design](package-balance-ui.md) — workflows, endpoints, permissions and the smallest first UI slice
- [STATUS.md](../STATUS.md) — current progress
- [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) — what's blocking what
- [templates/phase.md](../templates/phase.md)
- [User-interface delivery](../architecture/user-interface.md)
