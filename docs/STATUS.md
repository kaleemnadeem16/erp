# Project Status

**Updated:** 2026-10-10 (Phase 4 cost implementation). Kept short and current on purpose — see
[ADR 0095](decisions/0095-status-is-short-current-and-purpose-limited.md): no logs, counts or per-task history here.
History is in [STATUS-LOG.md](STATUS-LOG.md); resume points are in the workstream handoffs below.

## Phases

| Phase | State | Detail |
|---|---|---|
| 0 Foundation | **Complete** (incl. first production deployment, 2026-09-22) | [phase-0-foundation.md](phases/phase-0-foundation.md) |
| 1 Master data | **Complete** | [phase-1-master-data.md](phases/phase-1-master-data.md) |
| 2 Purchasing & stock | **Complete** (closed 2026-10-08): purchase orders, receipts, QA, reversal, the stock-movement and valuation engine (AVCO/FIFO/SPECIFIC), cutover, negative-stock overrides, backdated recalculation, physical counts, with their screens. Carried forward: the multi-owner correction package (needs Phases 4–6), a few Purchasing PO fields and two operations follow-ups | [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) |
| 3 Estimating core | **Core complete** (closed 2026-10-08): rate dimensions, application rates, formulations (versioning, overrides, roll-up), assemblies and overhead, the read-only Material Preview with stock proposals, what-if variance control and approvals. Deferred, not unfinished: quotation adoption and generic cost resolution (Phase 4), package-aware proposals (enhancement), department-limited availability (Q26) | [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) |
| 4 Sales, quotations and job handover | **R1/R2 business research complete**; ADRs 0098–0109 accepted, Q39/Q40 resolved; C0 accepted (0110), C1a–c internal query delivered; authorised C2a–b in progress; CRM backend foundation exists; security correction handoff ready, three-policy review awaiting approval; R3 initial evidence, quotation implementation pending | [Phase 4 research plan](phases/phase-4-sales-quotations-and-handover.md) |
| 5 Job execution · 6 Finance · 7 Mobile & integrations | Not started | [phases/README.md](phases/README.md) |

## Workstreams outside the phase list

| Workstream | State | Resume / detail |
|---|---|---|
| **Grouped package tracking v1** | **Complete** (commit `1c73d81`): activation, receipt, QA-held receipt, issue, transfer, physical count, receipt reversal, QA disposition, fail-closed database enforcement, permissions, phone-width verification | [handoff](phases/package-balance-handoff.md) · [v1 sign-off](phases/package-balance-ui.md#v1-sign-off-2026-10-08) · ADRs 0090–0094 |
| **Rejected-stock exit — Supplier Return and Scrap** (ADR 0097) | **Complete**: shared exit core, the two documents and API, screens, demo seeder and personas, role recipes, release notes | [plan](phases/rejected-stock-exit-plan.md) · [handoff](phases/package-balance-handoff.md) · [ADR 0097](decisions/0097-supplier-return-and-scrap-of-rejected-stock.md) |
| Packaged purchasing (named packages, supplier offers, price basis) | Complete | [item-purchasing-improvements.md](phases/item-purchasing-improvements.md) · [ADR 0080](decisions/0080-packaged-purchasing-and-independent-price-basis.md) |
| Shared approval engine and approvals workspace | Engine complete; workspace first UI draft shipped | [approvals.md](domain/approvals.md) · [ADR 0070](decisions/0070-shared-configurable-approval-engine.md) |
| Settings, forms and access hardening (company settings, document numbering, PO form, access audit, record scope) | Delivered through a checkpoint; further slices are owner-prioritised | [handoff and open items](phases/settings-and-access-hardening.md) · [audit tracker](architecture/access-security-audit.md) |

## In progress

Phase 4 cost-contract implementation is in flight. Nothing of the package workstreams is in flight (the rejected-stock exit, ADR 0097, is complete). Other agents share the checkout: run `git status` and `git log` before assuming the tree is idle.

## Release state

Production runs `main`, last released **2026-10-02** (Phase 3 estimating core, access administration, shared approval engine). `develop` is well ahead of it; its later work —
record scope, the access audit follow-ups, the approvals workspace, grouped package tracking, and others — has **not** been released. Release steps are the owner's
([deployment.md](architecture/deployment.md), and the release notes in the settings-and-access handoff); nothing is pushed to `main` without the owner's go-ahead.

## Next — design and build areas

Each is a new slice: propose first, get the owner's answers, then build. Optional enhancements are kept apart from required work.

- **Required for the roadmap:** Phase 4 R1/R2 business research is complete; [C0 and C1/C2 contract/slices](phases/phase-4-c0-cost-evidence-contract.md) authorise correcting existing cost APIs and migrating consumers together; C1a–c query delivered; C2a–b remain in progress, C1d remains separately gated. Local commits only, no push without explicit request. [R3 pricing research](phases/phase-4-r3-sales-pricing.md) has started independently; initial recommendations await owner review. It also owes the multi-owner correction package carried from Phase 2.
- **Later package enhancements** (none started; each a new design slice): governed change of tracked levels; deactivation/collapse; container-level tracking (not before the owner asks); line-level receipt reversal; count approval thresholds; QA/count reconciliation; standalone Issue/Transfer screens. **Deferred estimating enhancement:** package-aware proposals in the Material Preview (owner decision: not ahead of return/scrap).
- **Access and security:** the remaining record-scope slices (purchasing documents, Estimating availability, approvals, delegated administration) — Q26; two-factor and step-up sign-in — Q37.

## Blocked / awaiting input

[CRM policy reconciliation](phases/phase-4-crm-policy-review.md) awaits owner review; its new business rules are not implementation-authorised. The [security handoff](phases/phase-4-crm-security-correction.md) follows existing accepted authority. No open question blocks existing delivery work. R1/Q39 and R2/Q40 are closed; C0/0110 is accepted and Q41 resolved; C1d alone retains a separate authority gate; dependent Phase 4 design retains the research gates in its [R1 closure](phases/phase-4-r1-decision-matrix.md). Other questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) (Q8, Q10, Q16, Q23, Q24, Q26, Q28, Q33, Q37). **Still pending, and not closable by documentation:** the staging sign-off for the access work (A1–A9,
ADRs 0084–0089) — it needs staging access and a person to run `erp:audit-access-inventory` and decide per person; see the [settings and access handoff](phases/settings-and-access-hardening.md). 

## Where to look

[docs/README.md](README.md) is the map · [phases/README.md](phases/README.md) the roadmap and phase state · [decisions/](decisions/) the ADRs (immutable) ·
[architecture/invariants.md](architecture/invariants.md) the rules that must not be broken · [architecture/feature-completion-checklist.md](architecture/feature-completion-checklist.md) what every new feature must pass before it is done · [architecture/pre-push-checks.md](architecture/pre-push-checks.md) the checks CI will run, by what you changed · [journeys/](journeys/README.md) how users complete tasks.

## Updating this file

Update the relevant row **in the same commit** that completes or starts a workstream, reconcile any "next" line the change makes false, and append the detailed entry to
[STATUS-LOG.md](STATUS-LOG.md) in that commit. Do not add per-task rows, commit lists, test counts or demo inventories here; a statement that would be false after the next
slice belongs in the owning doc or the log. Historical text elsewhere must say it is historical.

Session note: development runs in a Remote-WSL VS Code window — run Docker/git natively from the WSL shell.

Docker note: `docker compose restart <service>` restarts the process inside the existing container — it does **not** re-read `env_file`. After editing `backend/.env`, use
`docker compose up -d <service>` instead; `restart` alone silently keeps serving the old environment values.
