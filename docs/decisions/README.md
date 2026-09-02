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
| [0039](0039-configurable-avco-valuation-pools.md) | Use constrained configurable AVCO valuation pools | accepted | 2026-09-01 |
| [0040](0040-control-backdated-stock-recalculation-and-corrections.md) | Control backdated stock recalculation and corrections | accepted | 2026-09-02 |
| [0041](0041-progressive-inventory-location-capabilities.md) | Use one capable inventory schema with progressive location capabilities | accepted | 2026-09-02 |
| [0042](0042-immutable-item-supplier-identity.md) | Keep item-supplier relationship identity immutable | accepted | 2026-09-02 |

**Next number: 0043**

## By theme

- **Stack:** [0001](0001-laravel-backend.md) · [0002](0002-api-first-react-spa.md) · [0005](0005-sanctum-authentication.md) · [0006](0006-generated-api-client.md) · [0015](0015-capacitor-for-mobile.md) · [0016](0016-php-84-alignment.md) · [0020](0020-postgresql-only.md) · [0026](0026-english-only-no-rtl.md)
- **Structure:** [0004](0004-multi-tenancy-ready-schema.md) · [0010](0010-core-plus-plugins.md) · [0014](0014-modular-monolith.md) · [0018](0018-single-repository.md) · [0022](0022-code-documentation-strategy.md) · [0027](0027-generic-column-mapping-import.md) · [0028](0028-dynamic-namespaced-permissions.md)
- **Infrastructure:** [0013](0013-docker-dev-and-production.md) · [0017](0017-deployment-topology.md) · [0021](0021-multi-architecture-images.md) · [0029](0029-develop-main-branching-model.md)
- **Domain:** [0007](0007-avco-valuation.md) · [0008](0008-assembly-abstraction.md) · [0009](0009-company-defined-rate-dimensions.md) · [0030](0030-unified-party-model.md) · [0032](0032-per-item-valuation-method.md) · [0033](0033-item-suppliers-relationship.md) · [0034](0034-party-child-tables-inherit-company-scope.md) · [0035](0035-separate-purchasing-operational-and-financial-facts.md) · [0036](0036-controlled-inventory-cutover.md) · [0037](0037-controlled-negative-stock-overrides.md) · [0038](0038-company-wide-per-item-avco-pool.md) · [0039](0039-configurable-avco-valuation-pools.md) · [0040](0040-control-backdated-stock-recalculation-and-corrections.md) · [0041](0041-progressive-inventory-location-capabilities.md) · [0042](0042-immutable-item-supplier-identity.md)
- **Finance:** [0011](0011-double-entry-open-item-ar.md) · [0012](0012-multi-currency-dual-amounts.md) · [0023](0023-retention-retainage-enabled.md) · [0024](0024-onboarding-base-currency-and-fiscal-year.md) · [0025](0025-configurable-tax-engine.md) · [0031](0031-manual-exchange-rate-entry.md)

## Superseded chain

`0003` → `0019` → **`0020`** — the database decision, revised twice as the deployment target
became clearer. Read 0020; the earlier two are kept for the reasoning.

`0007` → **`0032`** — the original AVCO-only decision was replaced by per-item valuation once
the mixed formulation/resale business case was established.

`0038` → **`0039`** — the company-item AVCO default remains, but migration continuity requires a
small controlled set of immutable valuation-pool scopes.

`0030` → **`0034`** — the unified-party decision remains, but its child-table scoping and
customer control-account wording were corrected after implementation exposed the precise shape.
