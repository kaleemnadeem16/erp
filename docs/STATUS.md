# Project Status

**Updated:** 2026-09-24
**Current phase:** Phase 3 — Estimating core (in progress; two vertical slices shipped
2026-09-23 — rate dimensions/application rates, then formulations with versioning, customer
overrides and nested cost roll-up, now followed by the 2026-09-24 draft-editing, human-readable
history, unit-defaulting and item-navigation usability pass. Before starting Assemblies, a genericity review on
2026-09-24 caught and corrected a real structural error in the overhead-policy design —
[ADR 0049](decisions/0049-assembly-group-overhead-scope.md), superseding
[ADR 0048](decisions/0048-configurable-hierarchical-overhead-allocation.md). All three steps of
the resulting Assembly plan — foundation, overhead resolver, then the remaining
assembly_versions/assembly_components cost roll-up — are now shipped (2026-09-24/25); the
originally-scoped ad-hoc **assembly cost preview** (live application-rate resolution with
default-fallback visibility) remains open, tracked in
[phase-3-estimating-core.md](phases/phase-3-estimating-core.md)). Phase 2's remaining
acceptance criteria depend on later phases' correction-package work and are picked up alongside
Phase 3; UI polish on existing Phase 1/2 screens continues in parallel. Phase 1 — Master data is
complete. Phase 0 — Foundation is done, including production deployment (2026-09-22, ahead of
the rest of Phase 2 at the user's request).
**Application code:** Laravel 12 backend with Phase 0 foundations, Phase 1 master-data APIs and
Phase 2 purchase orders, receipts, QA/reversal and the full stock movement/valuation engine.
Inventory cutover now posts and strictly reconciles AVCO/FIFO/SPECIFIC opening state; controlled
negative openings and staged open purchase-order commitments, live negative-stock overrides,
backdated recalculation and physical counts are also complete. Each workflow has a read/write API
and React UI; the stock ledger, layers and AVCO
pools have read surfaces. The React 19 + Vite frontend uses the generated client for every
implemented endpoint. Only the later Phase 4/5/6 multi-owner correction package remains.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Full demo seeding is idempotent across live-stock drift** — `StockDemoSeeder` now recognises its create-once negative-stock override requests by natural source identity and never recalculates immutable historical payloads from current stock. A regression test posts a real intervening receipt, proves both request rows remain byte-identical across the next full `demo:seed`, and confirms the final Estimating demo seeder still runs → [environments.md](architecture/environments.md#immutable-transactional-demo-data-create-once-never-reconcile) | 2026-09-24 |
| **Step 3 — the remaining Assembly slice: components, resource rates and the five-part cost roll-up** — `assembly_versions`/`assembly_components` (same DRAFT/ACTIVE/SUPERSEDED + exclusion-constraint shape as `formulation_versions`/`formulation_lines`), four stored component kinds (`MATERIAL`/`LABOUR`/`PLANT`/`SUBCONTRACT`, still no stored `OVERHEAD` row). `ExplodeAssemblyVersion` computes material/labour/plant/subcontract from components alone, *then* resolves and computes overhead from those four figures — never the reverse, so overhead can never land inside its own base; `AssemblyCostRollUpTest` hand-verifies every driver's basis, MATERIAL-only wastage/multiplier, margin-vs-markup (docs/domain/assemblies.md's own 100→120/125 worked example), a nested-formulation and an application-rate-priced MATERIAL component, and a rejected incompatible-UoM pairing. A real gap surfaced mid-build: `items` has no rate column and a non-stocked LABOUR/PLANT item has no AVCO pool, so nothing anywhere could price one — closed with `resource_rates`, a new company-scoped effective-dated table shaped like `exchange_rates` (dated rows, "most recent on-or-before wins," no update endpoint — a correction is a new row) rather than the heavier versioned-policy lifecycle, since a resource rate is a market fact like AVCO cost, not a governed policy. The cost-breakdown response is fully explainable — which version, which overhead tier/driver resolved, each component's resolved qty/unit-cost/source (AVCO, a named formulation, a resource rate as of a date, or a manual override), and cost/sell per unit. Demo data deliberately avoids `demo-f-tinted-base` for a MATERIAL `formulation_id` — that seeder's delete-and-recreate idempotency would conflict with a real `assembly_components` FK on every re-seed; that path is covered by the automated test instead. Also found, flagged, and deliberately left alone: `demo-spray-unit` (Phase 2) is a stocked, serial-tracked capital asset wearing the same `PLANT` type a costing *resource* now also uses — two different concepts sharing one type, not fixed here → [items.md](domain/items.md#resource-rates), [assemblies.md](domain/assemblies.md#cost-roll-up), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-25 |
| **Step 2 — the overhead-policy resolver, ADR 0049's plan realised in code** — `overhead_policies`: same versioned DRAFT/ACTIVE/SUPERSEDED shape as `formulation_versions` (exclusion constraint + a partial-unique "one ACTIVE per scope" index, both `COALESCE(scope_id, 0)`-adjusted since `scope_id` is `NULL` for `COMPANY` — Postgres treats every `NULL` as distinct, which would otherwise silently allow more than one active company default). `ResolveOverheadPolicy` walks `Assembly → Assembly Group → Company → none`, returning which tier won — `none` is a legitimate result here, not an error, unlike `ResolveApplicationRate`/`ResolveFormulation`. `OverheadAmountCalculator` is a pure function interpreting each of the five v1 drivers' `rate` (percentage-of-cost vs. currency-per-unit), fully unit-tested now even though Step 3's real material/labour/plant/subcontract totals don't exist yet to feed it. Mandatory reason + actor on every policy version, gated by two new permissions (`estimating.overhead-policy.manage` to write, `estimating.policy-history.view` to read — the same split `InventoryPolicyChange`/`PurchasingPolicyChange` use); the `resolve` preview endpoint stays unrestricted, same as the other Estimating resolve endpoints, since every estimator needs "what applies right now" regardless of either permission. No onboarding wizard — zero policy rows already *is* the valid "not configured yet" state (overhead defaults to $0), so a new Estimating settings screen's ordinary "set a new policy" flow is enough. UI: one reusable scope-bound panel, embedded on that settings screen (`COMPANY`) and on Assembly Group's and Assembly's own edit screens (`ASSEMBLY_GROUP`/`ASSEMBLY`); the Assembly edit screen also shows which tier its effective policy actually resolved from. Demo data adds a 10%-of-direct-cost company default plus a $5/labour-hour override for the Labour & Installation group, so the hierarchy visibly resolves rather than only ever showing one tier → [ADR 0049](decisions/0049-assembly-group-overhead-scope.md), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-24 |
| **Step 1 — minimal Assembly + Assembly Group foundation, ADR 0049's plan realised in code** — `assemblies` (identity: code/name/description/work UoM/margin/status, always `DRAFT` — no `assembly_versions`/`assembly_components` yet) and `assembly_groups` (flat, optional, company-defined classification), with a mutable `assembly_group_id` — deliberately no repoint guard, unlike `Formulation`'s locked `output_item_id`/`customer_id`, since a group is a classification, not identity. `ArchiveAssemblyGroup` hard-deletes (FK-blocked while referenced, same as `RateDimension`); `ArchiveAssembly` sets `status = ARCHIVED` (same as `ArchiveFormulation`). Full Actions/API/tests/UI/demo data shipped together: company isolation, same- and cross-company group/work-UoM assignment, changing/removing a group, archived-assembly visibility and an ungrouped assembly all covered by tests; list/create/edit screens for both, added to the Estimating nav group with no new visibility-gating mechanism invented (none exists yet in this frontend — confirmed by grep, not assumed). This exists specifically to give Step 2's overhead-policy resolver a real `assemblies`/`assembly_groups` row to scope against → [ADR 0049](decisions/0049-assembly-group-overhead-scope.md), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-24 |
| **Assembly genericity review corrects ADR 0048's overhead scope before any Assembly code exists** — validated the Assembly domain against furniture, fabrication, machinery, installation, maintenance, food/process and packaging (the paint/coating examples were only ever one validation case, never the target) before building Overhead policies or Assemblies. Found two real errors, both tracing to the same cause — overhead's `CATEGORY` scope and `assembly_components`' `OVERHEAD` kind were both modelled by analogy to formulations (which have an output item and material-only rows) without checking that against assemblies, which deliberately have neither ([ADR 0008](decisions/0008-assembly-abstraction.md)). [ADR 0049](decisions/0049-assembly-group-overhead-scope.md) supersedes [ADR 0048](decisions/0048-configurable-hierarchical-overhead-allocation.md): overhead now resolves `Assembly → Assembly Group → Company → none` through a new flat, optional, company-defined `assembly_groups` classification (not Inventory's item-category tree, which assemblies have no relationship to), and `OVERHEAD` is dropped from `assembly_components.kind` entirely — it's computed from the resolved policy at roll-up time, never a manually-entered row, closing an ambiguity (does a manual row add to or replace the policy?) that never had a chance to become a real bug. `extensibility.md` gained a general "domain capability vs. company configuration vs. UI exposure" principle, generalising `ADR 0041`'s progressive-capability-modes pattern beyond inventory locations, plus the invariant that a UI visibility toggle must never itself be a historical fact. The confirmed-deliberate make-to-stock labour-inclusive-standard-cost boundary is now recorded explicitly in the phase doc. Reordered the remaining Phase 3 tasks into three steps — a minimal Assembly+Group foundation, then the complete overhead resolver, then the rest of Assemblies — so the resolver is built once against real scope targets, not built against a placeholder and revisited → [ADR 0049](decisions/0049-assembly-group-overhead-scope.md), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md), [assemblies.md](domain/assemblies.md) | 2026-09-24 |
| **Formulations: versioning, customer overrides, nested cost roll-up — Phase 3's second vertical slice** — `Formulation`/`FormulationVersion`/`FormulationLine`, built to the same standard as slice 1: schema, Actions/API, tests, UI, demo data, docs together. A version is editable only while `DRAFT`; `ActivateFormulationVersion` is the sole path to `ACTIVE` (superseding whatever was previously active) and both `ACTIVE`/`SUPERSEDED` are frozen — this phase's concrete reading of "immutable once referenced by any document" ahead of Phase 4 actually having documents. `ExplodeFormulationVersion` computes `cost_per_output_unit` recursively: a `SEMI_FINISHED` input with its own `ACTIVE` formulation is priced from that formulation's own rate, never from its AVCO stock value, since Phase 3 never posts. A real scaling bug surfaced while testing two-level nesting — threading a "fraction of the parent's need" multiplier through the recursion and dividing by each level's own unscaled `output_qty` looked plausible but silently produced the wrong number; fixed by having each formulation compute its own rate independently, the same way a leaf ingredient's AVCO cost is just multiplied by the quantity needed. `FormulationCycleGuard` is now re-run at activation too, not only save — two formulations built independently, each cycle-free while both still `DRAFT`, could otherwise each activate successfully and only complete a live cycle on the second activation. `IngredientCostResolver` supports exactly one leaf-costing configuration (AVCO, company-wide pool) and fails loudly rather than guessing for FIFO/SPECIFIC items or a warehouse/batch-scoped pool → [phase-3-estimating-core.md](phases/phase-3-estimating-core.md), [formulations.md](domain/formulations.md) | 2026-09-23 |
| **Rate dimensions and application rates — Phase 3's first vertical slice** — a new Estimating module: company-defined `RateDimension`/`RateDimensionValue` (an axis like Surface Type and its values, no core-code change per company) and `ApplicationRate`/`ApplicationRateDimension` (how much of an item is consumed per one unit of work, tagged with zero or more dimension values), full backend and screens shipped together per the phase plan's slicing rule. `ResolveApplicationRate` implements application-rates.md's documented fallback chain — exact match on all specified dimension values, then required-dimensions-only, then `is_default`, then a loud failure rather than a silent 1:1 assumption. Testing surfaced a real ambiguity: an untagged (`is_default`-only) rate could accidentally win as an "exact match" against an empty request instead of only ever resolving through its default flag — fixed by excluding untagged rates from the exact/required-only tiers entirely. `ApplicationRateCombinationGuard` blocks two rates resolving to the same exact combination, which is what lets "exact match" assume at most one candidate. A resolution-tester panel on the application rates list previews which rate wins for a combination, and how, before Phase 4 wires this into a real document → [phase-3-estimating-core.md](phases/phase-3-estimating-core.md), [application-rates.md](domain/application-rates.md) | 2026-09-23 |
| **First production deployment, seeded as a live demo company** — `main` is live: `https://erp.x1techs.com` and `https://api-erp.x1techs.com` respond over real TLS through Traefik on mainvm, backed by dbvm's shared Postgres/Redis, with a working admin sign-in and full CORS/CSRF/cookie-session round trip verified. Company 1 is deliberately being run as a demo/sandbox company on the live site (the user's choice), so `demo:seed` was run against it too — via Artisan's `--env=local` override, which satisfies the seeder's `local`/`testing`-only guard for that one process without changing the real `APP_ENV=production` anything else uses — with the demo login's credentials overridden away from the well-known dev default that's written throughout this repo's docs. The first release was run entirely by hand over SSH; the self-hosted deploy job is now implemented, with its first automated `main` run still pending — every automated attempt so far failed with a permission error, traced to the deploy directory living at `/home/ubuntu/erp`, unreachable by the `github-runner` OS account that runs the job. Moved to `/srv/apps/erp`, owned by `github-runner`, matching the existing `invoiceflow` deploy on the same host — see [ci-cd.md](architecture/ci-cd.md#deployment). Four real gaps were found during deployment: `.env.example`'s `DB_HOST=dbvm`/`REDIS_HOST=dbvm` only resolved as an SSH alias, not inside mainvm's containers; the GHCR images are private and mainvm had no working pull credential; the demo login's default credentials would otherwise have been publicly guessable; and the permission vocabulary needed a production-safe registration path. The first three were handled in the deployment; the last is now closed by `permission:register` and the shared `PermissionRegistry` → [deployment.md](architecture/deployment.md#first-deploy-runbook) | 2026-09-22 |

## Next

The new [product journeys](journeys/README.md) layer records the implemented Phase 2 flow from
login and master-data setup through PO, receipt, QA, stock visibility and controlled exceptions.
Keep it current whenever a workflow screen, status or consequence changes; later phases add their
own journeys.

Phase 2's first stock-engine UI/API slice is complete, and every screen the operational UI journey
needs now exists — settings screens and the inventory overview were the last two. Acceptance
criteria are now 18 of 22 satisfied: the formal keyboard/focus/WCAG audit and explicit
PO→receipt→QA→availability end-to-end journey both pass in Playwright. Of the four remaining
criteria, three require Phase 4/5/6 correction-package owners and one is the future
valuation-policy boundary.

The first production deployment is done (2026-09-22) — `main`, `https://erp.x1techs.com` and
`https://api-erp.x1techs.com` are live, TLS/CORS/CSRF/cookie-session login verified end-to-end
against the real domains. Full runbook, and the three real gaps found while running it (dbvm
resolving only as an SSH alias, not inside mainvm's containers; the GHCR images being private
with no working pull credential on mainvm; `erp:create-admin --permissions=all` silently
granting nothing because no permission strings are registered outside the production-blocked
demo seeder) → [deployment.md](architecture/deployment.md#first-deploy-runbook). The current
permission vocabulary is now registered safely by `permission:register`; future modules must
extend the shared registry and its tests when they introduce new permissions. Still open, not
blocking: the first release was manual; the self-hosted deploy job is implemented but its first
automated run is pending. The old personal GHCR credential remains as an emergency fallback. The
second production database check-in is resolved — the user owns invoiceflow and crm as well as
ERP, so `REDIS_DB`/`REDIS_CACHE_DB` collisions are avoided by that single owner directly, not a
cross-team re-confirmation.

## Blocked / awaiting input

Nothing blocking. Open questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 3
non-blocking (second customer profile; cross-entity master data; movement posting actor).

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | Complete (2026-09-22; non-blocking reference-doc and infrastructure follow-ups remain) |
| 1 | [Master data](phases/phase-1-master-data.md) | Complete |
| 2 | [Purchasing & stock](phases/phase-2-purchasing-stock.md) | In progress |
| 3 | Estimating core | In progress |
| 4 | Sales pipeline | Not started |
| 5 | Job execution | Not started |
| 6 | Finance | Not started |
| 7 | Mobile & integrations | Not started |

Detail: [phases/README.md](phases/README.md)

---

## How to update this file

This file is loaded by every agent at the start of every session — its size is a recurring
cost. Rules, per [ADR 0044](decisions/0044-status-log-context-budget.md):

1. **One table row per completed item**: a bold keyword, at most two plain sentences, a link to
   the phase doc/domain doc/ADR that holds the detail, and the date — in the same commit as the
   work. The narrative belongs in the linked doc first; if it doesn't fit there, it doesn't
   belong here either.
2. **Keep at most 8 rows in "Recently done".** When adding a row past that, move the oldest row
   to the bottom of [STATUS-LOG.md](STATUS-LOG.md) verbatim.
3. **Keep this file under ~90 lines.** Move the **Next** section forward when a phase completes.

Session note: development runs in a Remote-WSL VS Code window — run Docker/git natively from the
WSL shell.

Docker note: `docker compose restart <service>` restarts the process inside the existing
container — it does **not** re-read `env_file`. After editing `backend/.env`, use
`docker compose up -d <service>` instead (Compose detects the config diff and recreates the
container); `restart` alone silently keeps serving the old environment values.
