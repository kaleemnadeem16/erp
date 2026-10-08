# Project Status

**Updated:** 2026-10-08 (documentation reconciliation). Kept short and current on purpose — see
[ADR 0095](decisions/0095-status-is-short-current-and-purpose-limited.md): no logs, counts or per-task history here.
History is in [STATUS-LOG.md](STATUS-LOG.md); resume points are in the workstream handoffs below.

## Phases

| Phase | State | Detail |
|---|---|---|
| 0 Foundation | **Complete** (incl. first production deployment, 2026-09-22) | [phase-0-foundation.md](phases/phase-0-foundation.md) |
| 1 Master data | **Complete** | [phase-1-master-data.md](phases/phase-1-master-data.md) |
| 2 Purchasing & stock | **Complete** (closed 2026-10-08): purchase orders, receipts, QA, reversal, the stock-movement and valuation engine (AVCO/FIFO/SPECIFIC), cutover, negative-stock overrides, backdated recalculation, physical counts, with their screens. Carried forward: the multi-owner correction package (needs Phases 4–6), a few Purchasing PO fields and two operations follow-ups | [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) |
| 3 Estimating core | **Core complete** (closed 2026-10-08): rate dimensions, application rates, formulations (versioning, overrides, roll-up), assemblies and overhead, the read-only Material Preview with stock proposals, what-if variance control and approvals. Deferred, not unfinished: quotation adoption and generic cost resolution (Phase 4), package-aware proposals (enhancement), department-limited availability (Q26) | [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) |
| 4 Sales, quotations and job handover | **Research/discussion in progress**; R1-A/B1/B2 and B3 scope/group direction reviewed; forecast-basis research awaits discussion; no new decisions accepted or implementation started | [Phase 4 research plan](phases/phase-4-sales-quotations-and-handover.md) |
| 5 Job execution · 6 Finance · 7 Mobile & integrations | Not started | [phases/README.md](phases/README.md) |

## Workstreams outside the phase list

| Workstream | State | Resume / detail |
|---|---|---|
| **Grouped package tracking v1** | **Complete** (commit `1c73d81`): activation, receipt, QA-held receipt, issue, transfer, physical count, receipt reversal, QA disposition, fail-closed database enforcement, permissions, phone-width verification | [handoff](phases/package-balance-handoff.md) · [v1 sign-off](phases/package-balance-ui.md#v1-sign-off-2026-10-08) · ADRs 0090–0094 |
| Packaged purchasing (named packages, supplier offers, price basis) | Complete | [item-purchasing-improvements.md](phases/item-purchasing-improvements.md) · [ADR 0080](decisions/0080-packaged-purchasing-and-independent-price-basis.md) |
| Shared approval engine and approvals workspace | Engine complete; workspace first UI draft shipped | [approvals.md](domain/approvals.md) · [ADR 0070](decisions/0070-shared-configurable-approval-engine.md) |
| Settings, forms and access hardening (company settings, document numbering, PO form, access audit, record scope) | Delivered through a checkpoint; further slices are owner-prioritised | [handoff and open items](phases/settings-and-access-hardening.md) · [audit tracker](architecture/access-security-audit.md) |

## In progress

The rejected-stock exit (ADR 0097) has slices 1–3 built; no slice is mid-way. Other agents share the checkout: run `git status` and `git log` before assuming the tree is idle.

## Release state

Production runs `main`, last released **2026-10-02** (Phase 3 estimating core, access administration, shared approval engine). `develop` is well ahead of it; its later work —
record scope, the access audit follow-ups, the approvals workspace, grouped package tracking, and others — has **not** been released. Release steps are the owner's
([deployment.md](architecture/deployment.md), and the release notes in the settings-and-access handoff); nothing is pushed to `main` without the owner's go-ahead.

## Next — design and build areas

Each is a new slice: propose first, get the owner's answers, then build. Optional enhancements are kept apart from required work.

- **Required for the roadmap:** Phase 4 research/discussion starts with R1 (leads, opportunities and relationships), then the dependent topics in its [plan](phases/phase-4-sales-quotations-and-handover.md); implementation waits for agreed decisions. It also owes the multi-owner correction package carried from Phase 2.
- **Next package slice — supplier return and scrap for rejected stock** (owner priority). **Decided** ([ADR 0097](decisions/0097-supplier-return-and-scrap-of-rejected-stock.md), accepted 2026-10-08: two separate documents, draft → post, dedicated permissions, informational supplier credit). **Slices 1–3 of 4 built and browser-proven** (the shared backend exit core; the Supplier Return and Scrap documents with their API; their screens, entered from a delivery's QA panel and the item's package card); slice 4 (demo seeder, role recipes, release notes) follows the plan in [rejected-stock-exit-plan.md](phases/rejected-stock-exit-plan.md). Rejected stock, grouped or quantity-only, now has an exit.
- **Later package enhancements** (none started; each a new design slice): governed change of tracked levels; deactivation/collapse; container-level tracking (not before the owner asks); line-level receipt reversal; count approval thresholds; QA/count reconciliation; standalone Issue/Transfer screens. **Deferred estimating enhancement:** package-aware proposals in the Material Preview (owner decision: not ahead of return/scrap).
- **Access and security:** the remaining record-scope slices (purchasing documents, Estimating availability, approvals, delegated administration) — Q26; two-factor and step-up sign-in — Q37.

## Blocked / awaiting input

No open question blocks existing delivery work; Q39 gates dependent Phase 4 design. Other questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) (Q8, Q10, Q16, Q23, Q24, Q26, Q28, Q33, Q37); R1 model decisions are pending as [Q39](OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries). **Still pending, and not closable by documentation:** the staging sign-off for the access work (A1–A9,
ADRs 0084–0089) — it needs staging access and a person to run `erp:audit-access-inventory` and decide per person; see the [settings and access handoff](phases/settings-and-access-hardening.md). 

## Where to look

[docs/README.md](README.md) is the map · [phases/README.md](phases/README.md) the roadmap and phase state · [decisions/](decisions/) the ADRs (immutable) ·
[architecture/invariants.md](architecture/invariants.md) the rules that must not be broken · [architecture/feature-completion-checklist.md](architecture/feature-completion-checklist.md) what every new feature must pass before it is done · [journeys/](journeys/README.md) how users complete tasks.

## Updating this file

Update the relevant row **in the same commit** that completes or starts a workstream, reconcile any "next" line the change makes false, and append the detailed entry to
[STATUS-LOG.md](STATUS-LOG.md) in that commit. Do not add per-task rows, commit lists, test counts or demo inventories here; a statement that would be false after the next
slice belongs in the owning doc or the log. Historical text elsewhere must say it is historical.

Session note: development runs in a Remote-WSL VS Code window — run Docker/git natively from the WSL shell.

Docker note: `docker compose restart <service>` restarts the process inside the existing container — it does **not** re-read `env_file`. After editing `backend/.env`, use
`docker compose up -d <service>` instead; `restart` alone silently keeps serving the old environment values.
