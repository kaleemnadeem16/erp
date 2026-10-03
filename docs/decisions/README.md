[← Docs index](../README.md)

# Architecture Decision Records

One decision per file. **Accepted ADRs are never edited** — to change a decision, write a new
ADR that supersedes the old one and update the old one's status. The record of why we changed
our mind is as valuable as the decision itself.

New ADRs use [../templates/adr.md](../templates/adr.md). Number sequentially and add a row here
in the same commit.

## Index

| # | Decision | Status | Date |
|---|---|---|---|
| [0001](0001-laravel-backend.md) | Laravel for the backend | accepted | 2026-07-29 |
| [0002](0002-api-first-react-spa.md) | API-first React SPA, not Inertia | accepted | 2026-07-29 |
| [0003](0003-postgres-with-mysql-compatibility.md) | PostgreSQL primary, MySQL-compatible | ~~superseded by [0019](0019-mariadb-portability-target.md)~~ | 2026-07-29 |
| [0004](0004-multi-tenancy-ready-schema.md) | Multi-tenancy-ready schema, single-org operation | accepted | 2026-07-29 |
| [0005](0005-sanctum-authentication.md) | Sanctum for authentication | accepted | 2026-07-29 |
| [0006](0006-generated-api-client.md) | Generated API client (Scramble → Orval) | accepted | 2026-07-29 |
| [0007](0007-avco-valuation.md) | AVCO stock valuation, not FIFO | ~~superseded by [0032](0032-per-item-valuation-method.md)~~ | 2026-07-29 |
| [0008](0008-assembly-abstraction.md) | Assembly as the core sellable abstraction | accepted | 2026-07-29 |
| [0009](0009-company-defined-rate-dimensions.md) | Company-defined rate dimensions | accepted | 2026-07-29 |
| [0010](0010-core-plus-plugins.md) | Core + plugin packages, not per-customer forks | accepted | 2026-07-29 |
| [0011](0011-double-entry-open-item-ar.md) | Double-entry GL with open-item AR | accepted | 2026-07-29 |
| [0012](0012-multi-currency-dual-amounts.md) | Multi-currency with dual amount storage | accepted | 2026-07-29 |
| [0013](0013-docker-dev-and-production.md) | Docker for development and production | accepted | 2026-07-29 |
| [0014](0014-modular-monolith.md) | Modular monolith, not microservices | accepted | 2026-07-29 |
| [0015](0015-capacitor-for-mobile.md) | Capacitor for mobile, not a WebView wrapper | accepted | 2026-07-29 |
| [0016](0016-php-84-alignment.md) | PHP 8.4, aligned with existing infrastructure | accepted | 2026-07-29 |
| [0017](0017-deployment-topology.md) | Reuse the existing two-VM topology | accepted | 2026-07-29 |
| [0018](0018-single-repository.md) | One repository for docs and application | accepted | 2026-07-29 |
| [0019](0019-mariadb-portability-target.md) | PostgreSQL primary, MariaDB 10.11 target | ~~superseded by [0020](0020-postgresql-only.md)~~ | 2026-07-29 |
| [0020](0020-postgresql-only.md) | **PostgreSQL only** *(supersedes 0019, 0003)* | accepted | 2026-07-29 |
| [0021](0021-multi-architecture-images.md) | Multi-architecture images (amd64 + arm64) | accepted | 2026-07-29 |
| [0022](0022-code-documentation-strategy.md) | Module-level code docs, no file-by-file map | accepted | 2026-07-29 |
| [0023](0023-retention-retainage-enabled.md) | Retention/retainage support enabled | accepted | 2026-08-10 |
| [0024](0024-onboarding-base-currency-and-fiscal-year.md) | Base currency and fiscal year set at onboarding | accepted | 2026-08-10 |
| [0025](0025-configurable-tax-engine.md) | Configurable tax engine, no hardcoded rates | accepted | 2026-08-10 |
| [0026](0026-english-only-no-rtl.md) | English-only UI, no RTL support | accepted | 2026-08-10 |
| [0027](0027-generic-column-mapping-import.md) | Generic column-mapping data import | accepted | 2026-08-10 |
| [0028](0028-dynamic-namespaced-permissions.md) | Dynamic, namespaced permissions, Teams-ready | accepted | 2026-08-10 |
| [0029](0029-develop-main-branching-model.md) | `develop`/`main` branching model, deploy gated on `main` only | accepted | 2026-08-14 |
| [0030](0030-unified-party-model.md) | Unified party model for customers and suppliers | ~~superseded by [0034](0034-party-child-tables-inherit-company-scope.md)~~ | 2026-08-19 |
| [0031](0031-manual-exchange-rate-entry.md) | Exchange rates populated by manual entry only | accepted | 2026-08-19 |
| [0032](0032-per-item-valuation-method.md) | Per-item valuation method | accepted | 2026-08-20 |
| [0033](0033-item-suppliers-relationship.md) | Multiple suppliers per item | accepted | 2026-08-20 |
| [0034](0034-party-child-tables-inherit-company-scope.md) | Party child tables inherit company scope | accepted | 2026-08-21 |
| [0035](0035-separate-purchasing-operational-and-financial-facts.md) | Separate purchasing operational and financial facts | accepted | 2026-08-31 |
| [0036](0036-controlled-inventory-cutover.md) | Separate controlled inventory cutover from live stock operations | accepted | 2026-08-31 |
| [0037](0037-controlled-negative-stock-overrides.md) | Control negative stock through explicit overrides and settlement | accepted | 2026-09-01 |
| [0038](0038-company-wide-per-item-avco-pool.md) | Maintain one company-wide AVCO pool per item | ~~superseded by [0039](0039-configurable-avco-valuation-pools.md)~~ | 2026-09-01 |
| [0039](0039-configurable-avco-valuation-pools.md) | Use constrained configurable AVCO valuation pools | ~~superseded by [0045](0045-avco-transfer-rate-final-not-provisional.md)~~ | 2026-09-01 |
| [0040](0040-control-backdated-stock-recalculation-and-corrections.md) | Control backdated stock recalculation and corrections | accepted | 2026-09-02 |
| [0041](0041-progressive-inventory-location-capabilities.md) | Use one capable inventory schema with progressive location capabilities | accepted | 2026-09-02 |
| [0042](0042-immutable-item-supplier-identity.md) | Keep item-supplier relationship identity immutable | accepted | 2026-09-02 |
| [0043](0043-control-purchase-order-receipt-and-qa-posting.md) | Control purchase-order, receipt and QA posting | accepted | 2026-09-02 |
| [0044](0044-status-log-context-budget.md) | Keep STATUS.md inside a fixed context budget with an append-only status log | accepted | 2026-09-03 |
| [0045](0045-avco-transfer-rate-final-not-provisional.md) | AVCO transfers price final at post time, never provisional | accepted | 2026-09-03 |
| [0046](0046-strict-staged-inventory-cutover-reconciliation.md) | Require strict reconciliation of the staged inventory opening state | accepted | 2026-09-09 |
| [0047](0047-govern-inventory-structure-and-default-valuation-policies.md) | Govern inventory structure and default valuation policies | accepted | 2026-09-09 |
| [0048](0048-configurable-hierarchical-overhead-allocation.md) | Configurable, hierarchical overhead allocation for assemblies | ~~superseded by [0049](0049-assembly-group-overhead-scope.md)~~ | 2026-09-23 |
| [0049](0049-assembly-group-overhead-scope.md) | Scope overhead through Assembly Group, not item category; compute it, don't store it | accepted | 2026-09-24 |
| [0050](0050-item-specific-uom-conversion-and-divisibility.md) | Item-specific UoM conversions; divisibility is an item-and-UoM rule | accepted | 2026-09-25 |
| [0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md) | Use internal-inventory package proposals in Phase 3; keep Purchasing separate | accepted | 2026-09-26 |
| [0052](0052-progressive-inventory-package-tracking.md) | Use progressive inventory package tracking | accepted | 2026-09-26 |
| [0053](0053-explainable-inventory-package-selection-presets.md) | Ship explainable inventory-package selection presets before custom policies | accepted | 2026-09-26 |
| [0054](0054-risk-based-material-plan-variance-controls.md) | Audit every adopted material variance; escalate by configured risk | accepted | 2026-09-26 |
| [0055](0055-progressive-sales-pricing-and-controlled-formulas.md) | Use progressive Sales pricing with controlled formula steps | accepted | 2026-09-26 |
| [0056](0056-progressive-tax-calculation-and-recovery.md) | Use progressive tax calculation and explicit recovery distributions | accepted | 2026-09-26 |
| [0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md) | Use stable material-resolution capabilities with dynamic company assignment | accepted | 2026-09-26 |
| [0058](0058-phase-3-material-resolution-preview-contract.md) | Define a read-only, explainable Phase 3 material-resolution preview | accepted | 2026-09-26 |
| [0059](0059-name-a-location-as-warehouse-and-location.md) | Name a location as warehouse / location, and always return its warehouse | accepted | 2026-09-28 |
| [0060](0060-accept-true-and-false-for-boolean-list-filters.md) | Accept "true" and "false" for boolean list filters, once, in the shared list-query trait | accepted | 2026-09-28 |
| [0061](0061-retry-only-transient-failures-and-say-why-a-list-failed.md) | Retry only transient failures, and say why a list failed | accepted | 2026-09-28 |
| [0062](0062-company-member-and-access-administration.md) | Administer company members through invitations and additive roles | ~~superseded by [0065](0065-role-first-member-access-and-manual-credential-recovery.md)~~ | 2026-09-28 |
| [0063](0063-starter-roles-and-user-directory.md) | Offer reviewable starter roles and a user directory | ~~superseded by [0065](0065-role-first-member-access-and-manual-credential-recovery.md)~~ | 2026-09-29 |
| [0064](0064-pickers-report-a-failed-lookup-and-offer-retry.md) | Pickers report a failed lookup and offer a retry | accepted | 2026-09-29 |
| [0065](0065-role-first-member-access-and-manual-credential-recovery.md) | Keep member access role-first and permit controlled manual credential recovery | accepted | 2026-09-29 |
| [0066](0066-capability-driven-screen-navigation.md) | Drive screen navigation from effective capabilities | accepted | 2026-09-29 |
| [0067](0067-governed-per-location-usability-mark.md) | Let a company mark which locations count as usable stock, as a governed policy | accepted | 2026-09-29 |
| [0068](0068-purchasing-document-read-capabilities.md) | Authorise Purchasing document reads separately from writes | accepted | 2026-09-29 |
| [0069](0069-separate-purchasing-commercial-visibility-from-receiving.md) | Separate Purchasing commercial visibility from physical receiving | accepted | 2026-09-29 |
| [0070](0070-shared-configurable-approval-engine.md) | One shared, configurable approval engine | accepted | 2026-09-30 |
| [0071](0071-stored-material-selection-strategy-as-governed-history.md) | Store the material selection strategy as governed, versioned history | accepted | 2026-10-01 |
| [0072](0072-hand-built-stock-mix-in-base-quantity.md) | Let a person build the stock draw by hand, in base quantity, validated and priced by the server | accepted | 2026-10-01 |
| [0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md) | Separate Inventory valuation visibility from stock operations | accepted | 2026-09-30 |
| [0074](0074-authorise-recalculation-economic-record-reads-by-workflow-duty.md) | Authorise recalculation economic-record reads by workflow duty | accepted | 2026-09-30 |
| [0075](0075-authorise-inventory-cutover-economic-record-reads.md) | Authorise Inventory cutover economic-record reads | accepted | 2026-09-30 |
| [0076](0076-company-control-over-hand-chosen-stock.md) | Let each company choose how tightly hand-chosen stock is controlled | accepted | 2026-10-01 |
| [0077](0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md) | Resolve material cost through one generic contract, with a configurable costing scope | accepted (design only) | 2026-10-01 |
| [0078](0078-debounced-list-search-and-stable-tables-while-loading.md) | Wait for a pause before searching, and keep the table steady while it loads | accepted | 2026-10-02 |
| [0079](0079-document-numbering-system-templates-over-a-variable-registry.md) | One central document-numbering framework: series of templates over a registry of variables | accepted | 2026-10-02 |

| [0080](0080-packaged-purchasing-and-independent-price-basis.md) | Separate named packages, supplier offers and purchasing price basis | accepted | 2026-10-02 |
| [0081](0081-central-company-profile-and-company-wide-settings.md) | A central Company screen: profile, company-wide settings, a stable code, and lifecycle locks | accepted | 2026-10-02 |

| [0082](0082-reconcile-package-balances-with-stock-operations.md) | Reconcile package balances with stock operations | proposed | 2026-10-03 |

**Next number: 0083**

## By theme

- **Stack:** [0001](0001-laravel-backend.md) · [0002](0002-api-first-react-spa.md) · [0005](0005-sanctum-authentication.md) · [0006](0006-generated-api-client.md) · [0015](0015-capacitor-for-mobile.md) · [0016](0016-php-84-alignment.md) · [0020](0020-postgresql-only.md) · [0026](0026-english-only-no-rtl.md) · [0060](0060-accept-true-and-false-for-boolean-list-filters.md) · [0061](0061-retry-only-transient-failures-and-say-why-a-list-failed.md)
- **Structure:** [0004](0004-multi-tenancy-ready-schema.md) · [0010](0010-core-plus-plugins.md) · [0014](0014-modular-monolith.md) · [0018](0018-single-repository.md) · [0022](0022-code-documentation-strategy.md) · [0027](0027-generic-column-mapping-import.md) · [0028](0028-dynamic-namespaced-permissions.md) · [0044](0044-status-log-context-budget.md) · [0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md) · [0062](0062-company-member-and-access-administration.md) · [0063](0063-starter-roles-and-user-directory.md)
- **Infrastructure:** [0013](0013-docker-dev-and-production.md) · [0017](0017-deployment-topology.md) · [0021](0021-multi-architecture-images.md) · [0029](0029-develop-main-branching-model.md)
- **Domain:** [0007](0007-avco-valuation.md) · [0008](0008-assembly-abstraction.md) · [0009](0009-company-defined-rate-dimensions.md) · [0030](0030-unified-party-model.md) · [0032](0032-per-item-valuation-method.md) · [0033](0033-item-suppliers-relationship.md) · [0034](0034-party-child-tables-inherit-company-scope.md) · [0035](0035-separate-purchasing-operational-and-financial-facts.md) · [0036](0036-controlled-inventory-cutover.md) · [0037](0037-controlled-negative-stock-overrides.md) · [0038](0038-company-wide-per-item-avco-pool.md) · [0039](0039-configurable-avco-valuation-pools.md) · [0040](0040-control-backdated-stock-recalculation-and-corrections.md) · [0041](0041-progressive-inventory-location-capabilities.md) · [0042](0042-immutable-item-supplier-identity.md) · [0043](0043-control-purchase-order-receipt-and-qa-posting.md) · [0045](0045-avco-transfer-rate-final-not-provisional.md) · [0046](0046-strict-staged-inventory-cutover-reconciliation.md) · [0047](0047-govern-inventory-structure-and-default-valuation-policies.md) · [0048](0048-configurable-hierarchical-overhead-allocation.md) · [0049](0049-assembly-group-overhead-scope.md) · [0050](0050-item-specific-uom-conversion-and-divisibility.md) · [0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md) · [0052](0052-progressive-inventory-package-tracking.md) · [0053](0053-explainable-inventory-package-selection-presets.md) · [0054](0054-risk-based-material-plan-variance-controls.md) · [0055](0055-progressive-sales-pricing-and-controlled-formulas.md) · [0058](0058-phase-3-material-resolution-preview-contract.md) · [0059](0059-name-a-location-as-warehouse-and-location.md)
- **Finance:** [0011](0011-double-entry-open-item-ar.md) · [0012](0012-multi-currency-dual-amounts.md) · [0023](0023-retention-retainage-enabled.md) · [0024](0024-onboarding-base-currency-and-fiscal-year.md) · [0025](0025-configurable-tax-engine.md) · [0031](0031-manual-exchange-rate-entry.md) · [0056](0056-progressive-tax-calculation-and-recovery.md)

## Superseded chain

`0003` → `0019` → **`0020`** — the database decision, revised twice as the deployment target
became clearer. Read 0020; the earlier two are kept for the reasoning.

`0007` → **`0032`** — the original AVCO-only decision was replaced by per-item valuation once
the mixed formulation/resale business case was established.

`0038` → **`0039`** — the company-item AVCO default remains, but migration continuity requires a
small controlled set of immutable valuation-pool scopes.

`0030` → **`0034`** — the unified-party decision remains, but its child-table scoping and
customer control-account wording were corrected after implementation exposed the precise shape.

`0039` → **`0045`** — the AVCO pool-scope decision remains, but its transfer section's
provisional-repair provision was corrected once review established a transfer's quantity is
always fully physically backed and can never itself be shortage-backed.

`0048` → **`0049`** — the overhead-policy decision remains (no accumulation, five drivers,
versioned lifecycle, audit trail), but a genericity review done before any code existed found its
`CATEGORY` scope assumed assemblies have an output item to walk a category from — they don't.
Replaced with a dedicated, flat Assembly Group concept, and `OVERHEAD` was moved out of
`assembly_components.kind` entirely (computed from the resolved policy, never a stored row).
