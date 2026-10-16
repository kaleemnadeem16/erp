[← Phase index](README.md)

# Phase 4 — QL0 quotation foundation: delivery record, decisions and the path to a SIMPLE quotation

**2026-10-16 · QL0 IMPLEMENTED locally (targeted tests, migration cycle, Pint, Larastan); not pushed, not exposed.**
Governing: [R5 slices](phase-4-r5-implementation-slices.md) (QL0), [quotation lifecycle](../domain/quotation-lifecycle.md), ADRs 0098, 0099, 0109, 0122–0124.
There is no route, Action, screen, permission, grant, price, tax, number or posting. A user still cannot create a quotation.

## 1. What QL0 delivers

Six tables (one migration, `create_quotation_foundation`), six models, a `QuotationNumbering` type, two enums.

| Table | Role | Database-enforced integrity |
|---|---|---|
| `quotation_families` | The stable business quotation; the one optional current CRM association | Never deleted; creator and company fixed; generations only move forward; association must equal its latest event (checked at commit) |
| `quotation_family_association_events` | ADR 0099 history: old → new Opportunity (either may be absent), actor, time, optional reason | Append-only; one unbroken chain (`generation` n+1, `old` = previous `new`); first event starts from no opportunity |
| `quotation_revisions` | Explicit revisions of a family; recipient Party, one currency, pricing/tax-estimate/valid-through dates, predecessor | Ordinal **allocated by the database under the family lock**; unique per family; predecessor in the same family; state `DRAFT` or `CANCELLED_DRAFT` only; cancelled drafts immutable; identity fixed; never deleted; `input_generation` advances with every material change |
| `quotation_logical_scopes` | Family-level intended deliverable, **not** an Item/Assembly id; optional overlap anchor shared by alternatives or a superseding scope | Intent unique per family; anchor in the same family; append-only |
| `quotation_choice_groups` | Family-level identity of a set of competing alternatives | Intent unique per family; append-only |
| `quotation_draft_lines` | Customer-facing selling line: scope, membership BASE / OPTIONAL / CHOOSE_ONE (+ group, baseline), typed source, offered quantity and unit | Written only while the revision is a draft; one line per scope per revision; at most one baseline per group per revision; exactly the references each source kind needs; direct-Item cannot be a service/resource, service source must be a service; assembly version must belong to the company; revision/scope never change; each change advances the revision's generation |

Every relationship that names a company-scoped row is a **composite company key**, so a cross-company link is impossible in SQL, not only in application code (tested constraint by constraint).

## 2. Simple and complex quotations on the same model

- **Simple goods quote:** one family, one revision, a few BASE lines of kind DIRECT_ITEM, recipient a Party with no CustomerAccount, no Opportunity.
- **Direct-to-managed:** the same family can later gain an Opportunity (an association event), without touching any revision.
- **Several independently acceptable deliverables:** each is a logical scope; the same Item twice is two scopes and nothing merges them. A scope persists across revisions, so "this deliverable" stays the same thing in revision 2.
- **Alternatives, not cumulative sales:** CHOOSE_ONE lines share a choice group with one baseline; alternatives share an overlap anchor so a later slice can refuse two actionable overlapping offers. Optional lines sit outside the base.
- **Projects and installations:** ASSEMBLY_WORK lines pin an Assembly version and stay customer-facing lines; nothing forces other lines through Assemblies, and no estimating evidence is stored on the line. SERVICE_ITEM and governed MANUAL lines are first-class.
- **Revision lineage:** `predecessor_revision_id` links revisions of one family; a revision is never an unrelated document.

## 3. Reconciliation with the accepted R5 shape — what was deliberately not built

R5 lists a wider QL0 shape than the minimum a draft needs. Each omission below is added by the slice that can supply its evidence, in the same migration as its guard; nothing was created speculatively.

| Not in QL0 | Why | Arrives with |
|---|---|---|
| `ISSUED` state, `official_number`, `numbering_allocation_id`, `issued_at`, `quotation_issues` | A state with no evidence behind it must not exist; number consumption is forbidden here | QL3 |
| Candidates, adoptions, revalidations, invalidation events | Need priced, costed, sourced content | QL2 |
| Operation receipts, write fences, company policy versions | Idempotency and coordinator belong to the first Actions; policy to QL3/QL4 | QL1 / QL3 |
| Offer events and scope projections (withdraw/replace) | Need issued revisions | QL4 / QL5 |
| Money columns on lines, dual-money/FX evidence, **FinancialActivity registration** | Entered money needs ADR 0012/R4 evidence; no money exists yet, so there is nothing for the base-currency lock to protect | QL1 |
| Priced / package / stock measures and conversion versions | Derived from source resolution | QL1 |
| Explanatory child lines, sections as rows, contact/site references | Section is a label column today; children and contact/site need their own contracts (`party_contacts` work is another agent's) | QL1 |
| Replacement intent / source-copy reference | Revision copying is QL5 | QL5 |

`QuotationNumbering` (type `QUOTATION`, `{SEQ:5}`, managed by the existing `sales.numbering.manage`) is **registered** so it is visible and configurable, as the R5 QL0 text assigns. Nothing consumes it; a test asserts creating families and revisions allocates no number.
The offered quantity is stored at four places (every transaction column is four); a finer value is refused by the model hook through the shared PX0 contract rather than silently rounded by the column. The offered Assembly quantity scale therefore remains an **open R2/R5 decision**: QL0 does not invent six places.

## 4. Decisions recorded (for the planning/documentation owner to reconcile — no accepted text was edited)

1. **Advisory controls with unknown evidence.** The evaluation state stays **UNEVALUABLE** whenever required evidence is missing, incomplete or incompatible. An advisory control may *present* as a non-blocking review requirement; a mandatory one blocks official issue unless an accepted governing contract provides an authorised alternative. Missing evidence is never PASS; WARN stays distinguishable from UNEVALUABLE internally. *Reconciles ADR 0116 ("advisory unknown states unevaluable") with PR4 ("advisory unknown warns"): the evaluator already behaves this way (`3d298ed`).*
2. **Empty control sets.** The pure evaluator returns PASS for no controls and records `evaluated: 0`. That is **not issue readiness**: a later issue-readiness Action must separately establish that every applicable mandatory control, policy version, required evidence item and authority check is satisfied.
3. **Allocation identities.** Residual-allocation tie-breaking uses durable component identities (the logical scope / line identity, now persisted by QL0), never array position or query order.

## 5. Tax: the smallest valid path to the first usable quotation

No universal single-percentage shortcut is introduced, and nothing here changes the accepted model ([0119](../decisions/0119-determine-versioned-quotation-tax-components.md), [tax.md](../domain/tax.md), [R4 FR2/FR3](phase-4-r4-implementation-slices.md)). QL0 holds no tax field and is compatible with it (`tax_estimate_date` exists on the revision).

- The accepted **BASIC tax mode** already is the small case: effective percentage, exclusive, per-component dispositions TAXABLE / ZERO_RATED / EXEMPT / NOT_APPLICABLE / UNKNOWN. R4 FR3 names its first execution as one supported percentage NET component per line with explicit dispositions. That is a *subset of the typed model*, not a separate design.
- A **tax-free** quotation is valid only as a **determination result with evidence** (NOT_APPLICABLE, ZERO_RATED or EXEMPT) from an explicit, versioned, authorised company declaration — never because FR3 is unfinished. A taxable line must not silently become tax-free, and a missing component stays UNKNOWN (issue refuses `TAX_CONTEXT_UNKNOWN`).

Smallest valid sequence, each a separate authorisation:

| Step | Delivers | Needs |
|---|---|---|
| **T1 — FR2 minimal** | Typed tax determination contract; versioned company tax declaration and component rule versions; source classification default (goods/service); disposition result per line with evidence; UNKNOWN when undetermined | Tax-owner registration path for `tax.configuration.manage` (and `tax.profile.manage` only if Party profiles are in scope); reviewed holder; AU registration alongside its Action |
| **T2 — FR3 BASIC** | One percentage NET component per line, exclusive, rounding through the existing FR4 policy and allocation | T1; FR4 (done) |
| *(QL1 may start before T1)* | Draft preparation saves inputs and previews; tax is shown as UNKNOWN; **issue** is what waits | — |

So a SIMPLE quote can be *prepared and previewed* before tax exists, but cannot be *issued* until T1 (tax-free by declaration) or T1+T2 (percentage) is delivered. **Decision needed from the owner:** which of the two the first company needs (tax-free by declaration, or a percentage).

## 6. Proposed access design for SIMPLE preparation and official issue (not activated; not registered)

Current state: none of these duties is registered; `sales.quotation.*` remain SPECIFIED in the [AU manifest](phase-4-au0-au1-rollout-manifest.md). Each is staged with its Action in the batch that delivers it, then activated only after an owner-reviewed recipe. Nothing is inferred from administrator or manager names.

| Duty (matrix class) | Proposed holders | Notes |
|---|---|---|
| `sales.quotation.view` (N) | People who must see quotations: the preparers and issuers, plus a small read-only group (for example a sales coordinator) | Safe metadata only; commercial figures need the next row |
| `sales.quotation.prepare` (N) | **Quotation preparers**: the people who actually draft quotes | Draft, edit, preview, cancel own drafts, link an Opportunity. Exposes the minimum exact chosen commercial workflow for the authorised record — not general commercial or confidential data |
| `sales.quotation.issue` (N) | **Quotation issuers**: a smaller group than preparers (for a small company, the owner and one senior seller) | Issuing creates an official number and an immutable offer; keeping it separate from preparing is the maker/checker the routine path allows without an approval engine |
| `sales.quotation.commercial.view` (S) | Managers who review figures generally | Not needed to prepare or issue |
| `sales.quotation.history.view` (N) | Preparers and issuers | Lifecycle history |
| Cost | **No one in Sales** | A seller never receives `estimating.internal_cost.view`. Controls run server-side through the cost-blind evaluation adapter (a separate, still unauthorised dependency) and the seller sees PASS/WARN/FAIL/UNEVALUABLE only |

Legitimate existing CRM context, unchanged: a person who holds `sales.pipeline.view`, `sales.opportunity.manage` or the other CRM duties **does not thereby gain** any quotation duty, and incumbents keep exactly what they hold. Linking a draft to an Opportunity requires `prepare` **and** an independent legitimate read of that Opportunity (`SalesAccess::mayRead`), each judged by its own scope; a person limited to "my units" is refused (403) until restricted visibility is built.

Rules for activation: (1) STAGED first — the Actions exist and are tested while `SalesAccess` refuses everyone; (2) roles only if dedicated and single-purpose (**Quotation preparer**, **Quotation issuer**), with a named membership list approved by the owner; preferably direct grants to named people while the company is small; (3) an existing full-access mandate (ADR 0114) would receive every ACTIVE duty on its next sync — review that before flipping the status; (4) negative tests: an ordinary Opportunity manager and an administrator-named role must be refused on create and on issue; a preparer must be refused on issue; (5) backout is flipping the status back; history remains.

## 7. Test-infrastructure record

- **Interrupted full run (2026-10-16):** 2,581 passed, 71 failed, 1 skipped, in 4,130 s. All 71 failures were database-connection errors ("the database system is in recovery mode", "not yet accepting connections") in five Inventory classes (`StockLayerApiTest`, `StockIssueTest`, `RequestStockRecalculationTest`, `ReplayPackageCheckpointTest`, `RejectedStockExitTest`) after the throwaway `postgres-test` server restarted mid-run. The five classes then passed alone (126 tests). **Treated as an unresolved infrastructure-stability issue, not an application regression and not a verified-green suite.** Cause of the restart is not established (the run was unusually slow, consistent with machine load). A clean full run on an isolated database, with no other heavy suites running, is still owed.
- **Login limit:** `bootstrap/testing-environment.php` pins `LOGIN_RATE_LIMIT_PER_MINUTE=5`; it runs only when the testing environment is requested (`artisan` and the PHPUnit bootstrap), so development and production are unaffected. All seven `LoginTest` tests pass in the container that carries the 10000 development value.
- **FR1 invalid-rate test:** drops the Currency owner's positivity check inside its own test transaction (Postgres DDL is transactional); after the test the constraint still exists, and the Currency owner's validation test passes in the same run.

## 8. Documentation reconciliation for the documentation owner

`docs/STATUS.md` and [the Phase 4 handoff](phase-4-implementation-handoff.md) still describe B1 as undelivered and predate the items below. Nothing in them was edited here.

| Item | Actual state | Commit |
|---|---|---|
| B1 — FR0/PR0 kernel | Implemented, internal | `2ab3552` |
| PX0 shared quantity contract | Implemented (decimal agent) | `d42c3ce` |
| PX1 — source-specific quantities, exact `CurrencyQuantum` | Implemented | `def0c48` |
| FR1 — dated FX and frozen evidence | Implemented, internal, no tables | `f09b2a9` |
| AU0/AU1 — duty manifest and safe registration | Implemented; 8 CRM duties ACTIVE, 50 not live | `b12acd8` |
| FR4/PX6 — versioned rounding and unit-price precision | Implemented; `sales.financial.configure` STAGED | `13bd40d` |
| FR4 — residual allocation | Implemented, internal | `1592e1e` |
| FR4 — final-net controls; test login limit | Implemented, internal | `3d298ed` |
| QL0 — quotation identities and editable draft | Implemented, internal (this record) | commit titled "feat(sales): QL0 quotation identities and draft foundation" |

Still not delivered: any quotation Action, route or screen; tax determination (FR2/FR3); the cost-blind evaluation adapter; pricing master (PR1+); candidates, issue, numbering consumption; documents; acceptance and handover.

## 9. Proposed next slice — QL1a (for authorisation, not started)

The first slice a person can use: **draft preparation without a screen first.** `CreateQuotationDraft`, `UpdateQuotationDraft` (typed fields, expected generation) and a read-only `PreviewQuotation` over the existing kernel (PR0 source resolution, FR4 policy, FX evidence, controls), plus the first idempotency receipts, entered line money with dual-money evidence and FinancialActivity registration. Duties `sales.quotation.view/prepare` are delivered STAGED with their Actions. Manual and direct-Item lines with seller-entered prices first (no pricing master); Assembly lines follow once the cost-blind adapter is authorised. The screen is a following slice in the same QL1 line.

## Related

[R5 slices](phase-4-r5-implementation-slices.md) · [Quotation lifecycle](../domain/quotation-lifecycle.md) · [AU0/AU1 manifest](phase-4-au0-au1-rollout-manifest.md) · [Implementation handoff](phase-4-implementation-handoff.md) · [Tax](../domain/tax.md)
