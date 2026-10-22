[← Domain index](README.md)

# Tax

Tax is a separate determination, accounting and reporting capability. It consumes a resolved net
sales or purchase amount but is not part of the price formula. [ADR 0025](../decisions/0025-configurable-tax-engine.md)
established configurable tax; [ADR 0056](../decisions/0056-progressive-tax-calculation-and-recovery.md)
extends it with progressive calculation and explicit recovery distributions.
[ADR0119](../decisions/0119-determine-versioned-quotation-tax-components.md) and [0120](../decisions/0120-settle-quotation-rounding-and-financial-evidence.md) accept R4's precise multi-component,
inclusion, exemption, withholding and rounding contracts. 0119 refines only0056's blanket Item
goods-default: declared service Items use service defaults. The [financial calculation contract](quotation-financial-calculation.md)
is the current Sales implementation specification; older schema sketches below are conceptual,
not an enabled engine. Finance determination, recovery, postings and settlement remain deferred.

## Current Sales determination and component contract

Determine per-line tax from source nature/classification, Party profile, jurisdiction and date;
no debtor-account creation or commercial-group exemption. Preserve TAXABLE/ZERO_RATED/EXEMPT/
NOT_APPLICABLE/UNKNOWN per component. Versioned groups use named NET/TAX_AMOUNT/NET_PLUS_COMPONENTS
bases and fixed-line/document/per-unit methods, explicit dependency stage and cycle validation.
NET_FIXED/GROSS_FIXED input intent and named included components normalise separately to R3 net.
Withholding estimates payment only, never revenue/output tax/gross; unknown facts remain unknown.
Operative rounding/allocations and immutable evidence follow0120, independent of pricing modes.
Unsupported structure/mode refuses; architecture support is not enabled execution. Future invoice
legal tax may differ with linked evidence, never overwrite quote tax estimates.

## FR2a configuration foundation (2026-10-21)

The dedicated [Tax module](../../backend/app/Modules/Tax/README.md) provides company-scoped component identities,
append-only effective component/rate/rule/group versions, named dependency versions and generation-fenced group activation.
Versions use non-overlapping half-open company/code periods and exact bounded-predecessor succession. Group members pin same-company
rule/component/rate versions covering the whole group period. Activation rejects cycles, missing/duplicate components, incompatible
bases/methods, assessment scope, modes and currencies; a database trigger repeats validation under the same generation fence as graph
writers. Activated graphs cannot gain members or edges. All evidence rejects UPDATE/DELETE/TRUNCATE; populated rollback refuses.

`tax.configuration.manage` is STAGED and unregistered, with every configuration Action denied until reviewed activation;
`tax.profile.manage` is STAGED for the disabled FR2b foundation. There are no routes, grants, UI, source-master writes, Sales integration or client changes.
`DetermineQuotationTax` supports **classification only**: typed declared goods/services, Assembly defaults and explicit manual facts.
Missing classification is UNKNOWN, ambiguous nature UNSUPPORTED; even supported classification leaves statutory disposition UNKNOWN.
It selects no effective tax group and calculates no amount. Source persistence, Consumption of Party profile/exemption facts, composition,
inclusive normalisation and withholding (FR3) remain separate. No statutory or execution support is implied by stored method names.
Open-ended versions cannot be shortened by FR2a; scheduled successors require a bounded predecessor rather than rewriting evidence.

## FR2b protected Party profile foundation

Tax owns `tax_party_profile_versions`, `tax_party_component_evidence` and
`tax_party_profile_activations`. Parties remain unchanged: company-scoped composite foreign keys
reference existing prospects, customers or suppliers without creating a financial account or
adding reverse relations. Party `tax_id` is never used as profile or exemption evidence.

```text
tax_party_profile_versions
  id, company_id, party_id -> parties(company_id, id)
  jurisdiction, jurisdiction_evidence, registration_details nullable
  version_no, predecessor_id -> same-company profile version
  effective_from, effective_to nullable  -- half-open, non-overlapping per Party/jurisdiction
  created_by -> users(company_id, id), reason, created_at

tax_party_component_evidence
  id, company_id, profile_version_id -> same-company profile version
  component_id -> tax_components(company_id, id), disposition
  grounds, evidence_reference, issuer, issued_on, expires_on  -- textual evidence, nullable
  created_by -> users(company_id, id), reason, created_at
  unique(company_id, profile_version_id, component_id)

tax_party_profile_activations
  id, company_id, profile_version_id -> same-company profile version
  activated_by -> users(company_id, id), reason, created_at
  unique(company_id, profile_version_id)
```

A version records Party, jurisdiction and jurisdiction evidence, optional registration details,
half-open effective dates, predecessor/version number, actor, reason and creation time. Null
registration details mean no declared evidence, not unregistered or exempt. Non-overlapping
periods are per company/Party/jurisdiction. A successor begins exactly at its bounded predecessor's
end; an open-ended predecessor cannot be rewritten or shortened. Each successor supplies a new
component evidence set; omission means UNKNOWN, never inherited exemption or no tax.

Each component pins a same-company stable Tax component and preserves TAXABLE, ZERO_RATED,
EXEMPT, NOT_APPLICABLE or UNKNOWN distinctly. Known dispositions require declared grounds;
EXEMPT additionally requires a textual evidence reference, issuer and issue date. Optional
`expires_on` is an **exclusive** bound, matching profile dates; dated evidence covers the whole
profile interval. These are declared configuration facts, not verification of statutory entitlement.
No certificate is uploaded, stored or fetched. Profiles may declare no component facts and can be
activated as unknown; activation does not manufacture complete coverage or enable FR3 determination.

`CreateTaxPartyProfileSuccessor` atomically writes the version and all component facts;
`ActivateTaxPartyProfile` appends reviewed activation. Both independently check actor company,
company-wide scope and `tax.profile.manage` through the duty manifest before any lookup or replay.
The duty is STAGED, unregistered and denied even with a handmade permission grant. Configuration
management and ordinary Party visibility grant no profile authority. There is no profile view duty,
public read/write route, grant, command, job, UI, Sales adapter or determination hook.

All inserts share FR2a's company generation lock; idempotency records store only a digest and
result IDs. Replay rechecks authority, returns the original outcome and does not change history;
a changed intent or stale generation refuses. Database constraints enforce tenant references,
periods and adjacency. Evidence rows reject UPDATE/DELETE/TRUNCATE; activated profiles reject
additional component facts. Populated rollback refuses before dropping anything.

Protected models deliberately serialize to an empty array and emit no generic activity entries;
actor/reason/time history remains in the protected immutable tables. No Party relationship exposes
them. Invalid definitions and unavailable references use the same redacted refusal without chained
SQL/bindings or evidence. There is no profile lookup/filter/count/export/history projection to leak
these facts. Any future exposure requires an accepted authority decision and reviewed projections.

## Problem statement

Tax is not one percentage added to every document. Sales/output and purchase/input tax have
different accounting; purchase tax may be fully, partly or not claimable; withholding happens at
payment rather than changing invoice tax. Jurisdictions may require several components,
inclusive prices, tax-on-tax, exemptions and reporting codes.

Example — a $100 purchase plus $15 input tax:

| Recovery | Tax credit | Added to inventory/asset/expense | Economic cost |
|---|---:|---:|---:|
| Full | $15 | $0 | $100 |
| None | $0 | $15 | $115 |
| 60% | $9 | $6 | $106 |

The ERP must preserve these amounts separately; `is_recoverable = true/false` cannot represent
the third case.

## Where it sits

Tax is estimated on quotations and determined on invoices and purchase documents. Components
post to Output Tax, recoverable Input Tax, non-recoverable acquisition cost/expense and relevant
withholding accounts in [general-ledger.md](general-ledger.md). Configuration begins as master
data; transactional use begins in Phase 4 and financial reporting in Phase 6.

## Capability modes

| Mode | Intended fit | Capabilities |
|---|---|---|
| `BASIC` | Straightforward small-company regime | Effective percentage, output/input direction, exclusive tax, full/no recovery, zero/exempt/not-applicable, optional withholding |
| `LOCALISED` | Ordinary statutory localisation | Multiple components, tax classifications, party profiles, inclusive tax, partial recovery, jurisdictions, report grids, exemptions and withholding certificates |
| `ADVANCED` | Complex or multi-jurisdiction operation | Compound/fixed/quantity taxes, tax-on-tax, reverse charge, self-assessment, intended-use recovery, restricted formulas and localisation/external providers |

Tax mode is independent of Sales-pricing mode. Companies enable only the complexity they need;
all modes use the same component snapshot and posting boundaries.

## Conceptual data shape

```text
tax_regimes / tax_codes / tax_rule_versions
  company, jurisdiction, direction, effective dates, status
  calculation_method   PERCENTAGE | FIXED | QUANTITY | RESTRICTED_FORMULA
  input inclusion set; named component dependencies and RAW | SETTLED basis stage
  NET | TAX_AMOUNT(ids) | NET_PLUS_COMPONENTS(ids); fixed line/document or declared-unit scope
  report_code/grid, GL mappings

tax_classifications
  company, code, GOODS | SERVICES | BOTH, status

party_tax_profiles
  company, party, registrations, exemptions, jurisdiction facts

tax_recovery_rules
  company, tax code/component, intended-use facts
  recovery_type, recovery_percentage, effective dates

document_tax_components
  document line, rule/rate version, method, base, rate/quantity, amount
  direction, inclusive, jurisdiction, report tag
  recovery_percentage, recoverable_amount, non_recoverable_amount
  GL mappings and calculation evidence

withholding_tax_codes / withholding_certificates
  company, code, effective rate/rule, payment and certificate facts
```

This supersedes the earlier conceptual `tax_codes.is_recoverable` boolean shape in this domain
document; ADR 0025's principles remain accepted. Exact migrations and table boundaries belong to
the implementing slices.

## Determination

The resolver uses declared facts only:

- company tax regime;
- customer/supplier tax profile and registrations;
- item or Assembly tax classification and goods/service nature;
- ship-to, service or other configured tax location;
- transaction and tax-point dates;
- intended use for purchase-tax recovery.

Tax classification is not an Inventory category. Categories may supply defaults:

```text
Item explicit classification → nearest applicable category default → declared-nature company default
  goods Item → goods default; service Item → service default; manual → explicit declared nature
assembly explicit classification → assembly-group default → company services default
```

Persist the resolved classification before first use and snapshot it on issue/posting. A later
category move must not rewrite historical tax.

## Calculation order

```text
pricing formula → rounded net unit price → line net
  → determine tax components and explicit bases
  → net + tax = gross
```

Inclusive pricing back-calculates net and tax but still stores them separately. Component order
and whether earlier tax forms part of a later base are explicit rule data, never inferred from UI
order. Calculations retain high precision until configured component and document/currency
rounding boundaries.

## Recovery and postings

Each input-tax component is distributed into recoverable and non-recoverable amounts; their sum
must equal the component amount. Recoverable tax posts to a tax-credit asset. Non-recoverable tax
is added to inventory/asset acquisition cost or expensed according to context. Output tax posts
to a liability.

For a $200 net sale with $30 output tax and $15 fully recoverable input tax, a simplified filing
position is `$30 − $15 = $15 payable`. The ledger retains both $30 and $15 rather than storing
only their net.

Withholding is separate. The invoice and its output tax remain unchanged; payment is lower and a
tax credit/receivable or payable plus certificate is recorded separately.

## Dates, snapshots and corrections

Effective dates preserve changing rules. A quotation tax is an estimate from quotation context.
Invoice issue resolves legal tax using the company's configured tax-point basis and invoice facts;
quotation tax is not assumed to be final. Each issued document snapshots component, rule/rate,
classification, recovery and posting evidence. Corrections use credit/debit notes or reversals,
never edits to issued tax facts.

## Configuration, formulas and localisation

No jurisdiction, rate or statute is hardcoded in core. Country/province packages can provide
templates or localisation rules; an external provider may implement the same determination
contract. Company configuration must be reviewed by a qualified local tax adviser.

Advanced formulas are versioned and restricted to allowlisted typed inputs and operations. They
have validation, bounded execution, preview tests and intermediate evidence. Arbitrary PHP,
JavaScript, SQL or application-function execution is forbidden.

## Authorisation and data scope

Tax configuration, exemption override, recovery override and document approval require separate
permissions. Each implementation slice must complete the
[authorisation checklist](../architecture/authorization.md#per-feature-authorisation-checklist).
All tax configuration and transactional facts are company-scoped. Access to a source document
does not imply permission to change tax rules or recovery treatment.

## Landmines

- Exempt, zero-rated and not-applicable may produce the same amount but different reporting.
- Goods/service and jurisdiction must be resolved from master/document facts, never guessed.
- A quote date and invoice tax point can select different rules.
- Netting withholding into invoice tax breaks certificate and customer-ledger reconciliation.
- Treating all purchase tax as claimable overstates tax assets and understates inventory/expense.
- Mixing tax into a price formula destroys independent statutory versioning and corrections.

## Research ledger

- [Odoo tax computation](https://www.odoo.com/documentation/18.0/applications/finance/accounting/taxes/tax_computation.html)
- [Odoo tax-return grids](https://www.odoo.com/documentation/18.0/applications/finance/accounting/reporting/tax_returns.html)
- [Oracle tax recovery](https://docs.oracle.com/en/cloud/saas/financials/25d/faitx/tax-recovery.html)
- [Oracle tax recovery rates and controls](https://docs.oracle.com/en/cloud/saas/financials/25d/faitx/tax-recovery-rates-controls-and-defaults.html)
- [Oracle tax rates and controls](https://docs.oracle.com/en/cloud/saas/financials/26a/faitx/tax-rates-controls-and-defaults.html)
- [Oracle tax recovery distributions](https://docs.oracle.com/en/cloud/saas/financials/25d/fautx/tax-recovery-distributions.html)

## Related

- [Financial calculation contract](quotation-financial-calculation.md)

- [Sales pricing](sales-pricing.md) · [Sales pipeline](sales-pipeline.md)
- [Receivables](receivables.md) · [General ledger](general-ledger.md)
- [Items](items.md) · [Assemblies](assemblies.md)
- [ADR 0025](../decisions/0025-configurable-tax-engine.md) · [ADR 0056](../decisions/0056-progressive-tax-calculation-and-recovery.md)
