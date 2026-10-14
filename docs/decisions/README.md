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
| [0008](0008-assembly-abstraction.md) | Assembly as the core sellable abstraction | superseded by [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) | 2026-07-29 |
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
| [0044](0044-status-log-context-budget.md) | Keep STATUS.md inside a fixed context budget with an append-only status log | ~~superseded by [0095](0095-status-is-short-current-and-purpose-limited.md)~~ | 2026-09-03 |
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
| [0055](0055-progressive-sales-pricing-and-controlled-formulas.md) | Use progressive Sales pricing with controlled formula steps | superseded by [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) | 2026-09-26 |
| [0056](0056-progressive-tax-calculation-and-recovery.md) | Use progressive tax calculation and explicit recovery distributions | accepted; Item defaults partially superseded by 0119 | 2026-09-26 |
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

| [0082](0082-reconcile-package-balances-with-stock-operations.md) | Reconcile package balances with stock operations | ~~superseded by 0090–0094~~ | 2026-10-03 |
| [0083](0083-layered-stock-read-capabilities-and-blind-counting.md) | Layered stock-read capabilities (availability / stock / movement), and blind counting by role | accepted | 2026-10-03 |
| [0084](0084-separate-financial-master-data-fields.md) | Separate financial master-data fields from ordinary maintenance | accepted | 2026-10-06 |
| [0085](0085-request-specific-document-review-for-approvals.md) | Request-specific document review with commercial and technical approval profiles | accepted | 2026-10-06 |
| [0086](0086-gate-recipe-reads-by-capability.md) | Gate recipe reads behind a capability administrators grant through roles | accepted | 2026-10-06 |
| [0087](0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md) | Approval limits and written reasons are separate auditor capabilities | accepted | 2026-10-06 |
| [0088](0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md) | Device tokens expire, people manage their devices, and sign-in leaves room for stronger authentication | accepted | 2026-10-06 |
| [0089](0089-organisation-units-and-record-scope.md) | Organisation units and record scope, first applied to inventory by warehouse | accepted | 2026-10-06 |
| [0090](0090-package-aware-stock-issue-semantics.md) | Package-aware stock issue: one contract at three levels of detail; quantity, packaging and cost stay separate | accepted | 2026-10-07 |
| [0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) | Activate grouped package tracking atomically, and enforce it fail-closed in the database | accepted | 2026-10-07 |
| [0092](0092-package-aware-physical-count-posting.md) | Package-aware physical count: recount the composition, reconcile the quantity through a governed movement | accepted | 2026-10-08 |
| [0093](0093-package-aware-goods-receipt-reversal.md) | Package-aware goods receipt reversal: remove what is declared, never restore or invent a past composition | accepted | 2026-10-08 |
| [0094](0094-package-aware-supplier-qa-receipts.md) | Package-aware supplier QA: held packages stay in on-hand, a disposition relabels a declared physical portion | accepted | 2026-10-08 |
| [0095](0095-status-is-short-current-and-purpose-limited.md) | STATUS.md is short, current and limited by purpose, not by a row count (supersedes 0044) | accepted | 2026-10-08 |
| [0096](0096-cross-cutting-feature-standards.md) | Cross-cutting feature standards live in canonical docs and a completion checklist | accepted | 2026-10-08 |
| [0097](0097-supplier-return-and-scrap-of-rejected-stock.md) | Supplier return and scrap of rejected stock: one exit movement from the receipt's own layer | accepted | 2026-10-08 |
| [0098](0098-hybrid-sales-opportunity-requirement.md) | Govern opportunity requirements through one hybrid Sales workflow | accepted (U1 final review, 2026-10-09) | 2026-10-08 |
| [0099](0099-separate-quotation-issue-and-crm-association.md) | Separate quotation issue evidence from current CRM association | accepted (U2 final review, 2026-10-09) | 2026-10-08 |
| [0100](0100-independent-quotation-scopes-and-alternative-groups.md) | Distinguish independent quotation scopes from exclusive alternative groups | accepted (bounded business direction, 2026-10-09) | 2026-10-08 |
| [0101](0101-separate-accepted-business-and-remaining-forecast.md) | Separate accepted business from evidence-based remaining forecasts | accepted (bounded business direction, 2026-10-09) | 2026-10-08 |
| [0102](0102-govern-forecast-trust-and-explicit-valuation.md) | Restore forecast trust only through explicit review and publication | accepted (bounded business direction, 2026-10-09) | 2026-10-08 |
| [0103](0103-issue-quotations-to-party-backed-prospects.md) | Issue quotations to eligible Parties without requiring financial customer setup | accepted (bounded R1-C1 direction) | 2026-10-09 |
| [0104](0104-separate-customer-acceptance-and-readiness.md) | Separate customer acceptance from operational and financial readiness | accepted (bounded R1-C2 direction) | 2026-10-09 |
| [0105](0105-support-generic-party-identity-and-conversion.md) | Support organisation and individual Parties with controlled identity reuse | accepted | 2026-10-09 |
| [0106](0106-separate-sales-ownership-and-access.md) | Separate Sales responsibility from access and preserve reassignment history | accepted | 2026-10-09 |
| [0107](0107-separate-opportunity-stages-and-outcomes.md) | Separate pipeline stages, accepted scope and governed opportunity outcomes | accepted | 2026-10-09 |
| [0108](0108-expose-a-progressive-basic-crm.md) | Expose a practical basic CRM without forcing enterprise workflow | accepted | 2026-10-09 |
| [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) | Support shared commercial quotation lines with optional estimating | accepted; retained pricing partially superseded by 0115–0117 | 2026-10-09 |
| [0110](0110-version-cost-evidence-without-changing-legacy-estimates.md) | Correct existing cost operations through one authoritative contract | accepted | 2026-10-09 |
| [0111](0111-disqualify-leads-independently-of-derived-opportunities.md) | Disqualify Leads independently of derived Opportunities | accepted | 2026-10-10 |
| [0112](0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md) | Correct historical Opportunity responsibility with append-only evidence | accepted | 2026-10-10 |
| [0113](0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md) | Preserve original Lead intake and govern current-detail corrections | accepted | 2026-10-10 |
| [0114](0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md) | Authorise historical attribution correction as a dedicated duty | accepted | 2026-10-12 |
| [0115](0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md) | Resolve Sales pricing by explicit eligibility and commercial intent | accepted | 2026-10-12 |
| [0116](0116-govern-sales-discounts-and-final-net-controls.md) | Govern Sales discounts and final-net controls | accepted | 2026-10-12 |
| [0117](0117-preserve-pricing-evidence-across-capability-transitions.md) | Preserve pricing evidence across capability transitions | accepted | 2026-10-12 |
| [0118](0118-pin-quotation-pricing-dates-and-company-base-fx.md) | Pin quotation pricing dates and company-base FX | accepted | 2026-10-12 |
| [0119](0119-determine-versioned-quotation-tax-components.md) | Determine versioned quotation tax components | accepted | 2026-10-12 |
| [0120](0120-settle-quotation-rounding-and-financial-evidence.md) | Settle quotation rounding and financial evidence | accepted | 2026-10-12 |
| [0121](0121-public-repository-hosted-runners-and-manual-deploy.md) | Public repository: GitHub-hosted runners only, deploy by script on mainvm | accepted | 2026-10-13 |
| [0122](0122-govern-quotation-candidates-adoption-and-revalidation.md) | Govern quotation candidates, adoption and revalidation | accepted | 2026-10-13 |
| [0123](0123-issue-numbered-quotation-revisions-atomically.md) | Issue numbered quotation revisions atomically | accepted | 2026-10-13 |
| [0124](0124-preserve-quotation-availability-and-scope-replacements.md) | Preserve quotation availability and scope replacements | accepted | 2026-10-13 |
| [0128](0128-observe-with-the-existing-grafana-loki-stack.md) | Observe the ERP with the existing Prometheus, Loki and Grafana stack | accepted | 2026-10-14 |
| [0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md) | Demo and sandbox data live in a separate demo deployment (deferred) | accepted | 2026-10-14 |

**Next number: 0130**

## By theme

- **Stack:** [0001](0001-laravel-backend.md) · [0002](0002-api-first-react-spa.md) · [0005](0005-sanctum-authentication.md) · [0006](0006-generated-api-client.md) · [0015](0015-capacitor-for-mobile.md) · [0016](0016-php-84-alignment.md) · [0020](0020-postgresql-only.md) · [0026](0026-english-only-no-rtl.md) · [0060](0060-accept-true-and-false-for-boolean-list-filters.md) · [0061](0061-retry-only-transient-failures-and-say-why-a-list-failed.md)
- **Structure:** [0004](0004-multi-tenancy-ready-schema.md) · [0010](0010-core-plus-plugins.md) · [0014](0014-modular-monolith.md) · [0018](0018-single-repository.md) · [0022](0022-code-documentation-strategy.md) · [0027](0027-generic-column-mapping-import.md) · [0028](0028-dynamic-namespaced-permissions.md) · [0044](0044-status-log-context-budget.md) · [0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md) · [0062](0062-company-member-and-access-administration.md) · [0063](0063-starter-roles-and-user-directory.md)
- **Infrastructure:** [0013](0013-docker-dev-and-production.md) · [0017](0017-deployment-topology.md) · [0021](0021-multi-architecture-images.md) · [0029](0029-develop-main-branching-model.md) · [0121](0121-public-repository-hosted-runners-and-manual-deploy.md) · [0128](0128-observe-with-the-existing-grafana-loki-stack.md) · [0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)
- **Domain:** [0007](0007-avco-valuation.md) · [0008](0008-assembly-abstraction.md) · [0009](0009-company-defined-rate-dimensions.md) · [0030](0030-unified-party-model.md) · [0032](0032-per-item-valuation-method.md) · [0033](0033-item-suppliers-relationship.md) · [0034](0034-party-child-tables-inherit-company-scope.md) · [0035](0035-separate-purchasing-operational-and-financial-facts.md) · [0036](0036-controlled-inventory-cutover.md) · [0037](0037-controlled-negative-stock-overrides.md) · [0038](0038-company-wide-per-item-avco-pool.md) · [0039](0039-configurable-avco-valuation-pools.md) · [0040](0040-control-backdated-stock-recalculation-and-corrections.md) · [0041](0041-progressive-inventory-location-capabilities.md) · [0042](0042-immutable-item-supplier-identity.md) · [0043](0043-control-purchase-order-receipt-and-qa-posting.md) · [0045](0045-avco-transfer-rate-final-not-provisional.md) · [0046](0046-strict-staged-inventory-cutover-reconciliation.md) · [0047](0047-govern-inventory-structure-and-default-valuation-policies.md) · [0048](0048-configurable-hierarchical-overhead-allocation.md) · [0049](0049-assembly-group-overhead-scope.md) · [0050](0050-item-specific-uom-conversion-and-divisibility.md) · [0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md) · [0052](0052-progressive-inventory-package-tracking.md) · [0053](0053-explainable-inventory-package-selection-presets.md) · [0054](0054-risk-based-material-plan-variance-controls.md) · [0055](0055-progressive-sales-pricing-and-controlled-formulas.md) · [0058](0058-phase-3-material-resolution-preview-contract.md) · [0059](0059-name-a-location-as-warehouse-and-location.md)
- **Finance:** [0011](0011-double-entry-open-item-ar.md) · [0012](0012-multi-currency-dual-amounts.md) · [0023](0023-retention-retainage-enabled.md) · [0024](0024-onboarding-base-currency-and-fiscal-year.md) · [0025](0025-configurable-tax-engine.md) · [0031](0031-manual-exchange-rate-entry.md) · [0056](0056-progressive-tax-calculation-and-recovery.md)

- **Physical package tracking:** [0052](0052-progressive-inventory-package-tracking.md) · [0053](0053-explainable-inventory-package-selection-presets.md) · [0080](0080-packaged-purchasing-and-independent-price-basis.md) · [0082](0082-reconcile-package-balances-with-stock-operations.md) *(superseded by 0090–0094)* · [0090](0090-package-aware-stock-issue-semantics.md) · [0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) · [0092](0092-package-aware-physical-count-posting.md) · [0093](0093-package-aware-goods-receipt-reversal.md) · [0094](0094-package-aware-supplier-qa-receipts.md) · [0097](0097-supplier-return-and-scrap-of-rejected-stock.md)
- **Access, approvals and security:** [0028](0028-dynamic-namespaced-permissions.md) · [0062](0062-company-member-and-access-administration.md) · [0065](0065-role-first-member-access-and-manual-credential-recovery.md) · [0066](0066-capability-driven-screen-navigation.md) · [0070](0070-shared-configurable-approval-engine.md) · [0083](0083-layered-stock-read-capabilities-and-blind-counting.md) · [0085](0085-request-specific-document-review-for-approvals.md) · [0088](0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md) · [0089](0089-organisation-units-and-record-scope.md)
- **Documentation process and standards:** [0022](0022-code-documentation-strategy.md) · [0095](0095-status-is-short-current-and-purpose-limited.md) · [0096](0096-cross-cutting-feature-standards.md)

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

`0082` → **`0090`–`0094`** — the early, never-accepted proposal for package balances; its principles (physical and availability state are separate, held stock is on hand but not usable, every writer integrated or guarded) were decided and built by the five accepted package ADRs. Read those; 0082 is kept for the research.

`0044` → **`0095`** — the fixed row/line budget for STATUS.md failed in practice (104 rows by 2026-10-08); replaced by purpose limits, with the append-only status log kept.

`0062`, `0063` → **`0065`** — member administration and starter roles were partly replaced by the role-first model and manual temporary credentials; read 0065 with them.

`0048` → **`0049`** — the overhead-policy decision remains (no accumulation, five drivers,
versioned lifecycle, audit trail), but a genericity review done before any code existed found its
`CATEGORY` scope assumed assemblies have an output item to walk a category from — they don't.
Replaced with a dedicated, flat Assembly Group concept, and `OVERHEAD` was moved out of
`assembly_components.kind` entirely (computed from the resolved policy, never a stored row).
