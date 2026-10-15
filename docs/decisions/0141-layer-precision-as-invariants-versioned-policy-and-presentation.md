# 0141 — Layer precision as fixed invariants, versioned module policies and presentation preferences

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** every module that stores, calculates or shows a decimal; the Settings navigation; [precision.md](../domain/precision.md); refines [0012](0012-multi-currency-dual-amounts.md), [0081](0081-central-company-profile-and-company-wide-settings.md) and [0120](0120-settle-quotation-rounding-and-financial-evidence.md)

## Context

A read-only audit (2026-10-15) found that precision was a mix of fixed constants and per-screen habit.
Money and ledger quantities are stored at four places, Assembly measures and rates at six, FX rates and
conversion factors at nine. The only real configuration is an Item/UoM/package quantity increment.
Currency minor units exist as data but only the Sales kernel reads them; Purchasing and Inventory round every
amount to four places in every currency. Each frontend screen formats on its own, several through
`Number(...)`, which cannot hold a decimal(19,4) exactly. A comparison of mature products found that the safe
ones separate *what is rounded when calculating* from *how digits are displayed*, restrict reduction once data
exists, and either lock or version the change; the unsafe pattern is one global decimal setting that silently
changes stored values.

## Decision

Precision is organised in **three layers with different owners and different change rules**:

1. **Fixed technical invariants** — storage scales (money 4, stock-ledger quantity 4, Assembly technical measures and
   resource rates 6, FX rates and conversion factors 9, percentages 4), exact decimal/rational arithmetic, API values
   as strings, and *refusal instead of silent truncation*. They are changed only by an ADR and a migration, never by
   a setting.
2. **Versioned module-owned business policies** — entry precision, rounding and settlement, each stored and
   governed by the module that owns the business meaning, effective-dated, with actor, reason and previous/new
   version recorded, applying only to new work. Historical documents keep the policy version they were made under.
3. **Presentation preferences** — locale, separators and visible digits, with a company default and a personal
   override. They never alter stored values, validation, computation or an official historical document.

Entry, calculation, settlement, storage and display precision are **five different things** and are never
collapsed into one setting.

**Settings experience.** One central Settings navigation area with a section per module (General/Company,
Finance/Currency, Sales, Purchasing, Inventory/UoM, Estimating/Jobs, Documents/Printing). It reuses the existing
module Settings APIs, screens and policy-change history. There is **no new generic settings database and no
independent precision engine**. A module gets a precision control only when it has a real business choice; fixed
values are shown read-only as text with their reason.

**Authority** (no new duty, no grant, no role assignment is created by this decision):

| Setting | Authority |
|---|---|
| Sales unit-price entry precision and settlement/rounding policy | `sales.financial.configure` ([0144](0144-version-sales-unit-price-precision-within-four-place-storage.md)) |
| Item, unit and package quantity increments | `inventory.item.manage` |
| Print precision | Documents template authority, pinned per template version ([0137](0137-share-document-and-print-infrastructure-through-module-adapters.md)) |
| Company-wide display defaults | `company.profile.manage` initially; a separate display-only duty is revisited only when an independent administrative responsibility exists |
| Personal display preferences | None — the person's own data |
| Currency minor units | Not a company setting ([0143](0143-maintain-currency-minor-units-as-governed-reference-data.md)) |

Existing approval infrastructure is used only where a policy subject is configured to require it; there is no
universal second-person approval. There is no universal "settings" permission.

**Official documents** use the policy and template version pinned when they were issued, never the current browser
or company display preference.

## Consequences

- **Positive:** one vocabulary and one owner per precision question; no setting can rewrite history; small companies
  see sensible fixed defaults.
- **Negative:** several modules each gain a small read-only "Numbers and rounding" card; frontend screens must move to a
  shared formatter over time.
- **Requires:** the slices in [precision-implementation-slices.md](../phases/precision-implementation-slices.md);
  seeders and browser checks per slice; no change to Purchasing monetary arithmetic (0120).

## Alternatives considered

**One global company decimal setting** — rejected: it would change stored arithmetic or force one scale on currencies,
prices and quantities that legitimately differ.
**Separate configurable scale per category (money, price, quantity, rate, cost)** — rejected: it multiplies knobs that can
contradict storage and invites silent history rewriting.
**A generic settings database / settings engine** — rejected: module-owned policies with their own audit already exist
and are the established pattern.
**Display preferences that also drive validation or rounding** — rejected: a viewer's preference must not decide what is
stored or what a customer receives.

## Research basis

Primary sources compared (accessed 2026-10-15): ERPNext [system settings](https://docs.frappe.io/erpnext/user/manual/en/system-settings) and
[set precision](https://docs.frappe.io/erpnext/user/manual/en/set-precision); Business Central
[General Ledger Setup](https://learn.microsoft.com/en-us/dynamics365/business-central/application/base-application/table/microsoft.finance.generalledger.setup.general-ledger-setup)
and [Item Unit of Measure](https://learn.microsoft.com/en-us/dynamics365/business-central/application/base-application/table/microsoft.inventory.item.item-unit-of-measure);
SAP Business One [General Settings: Display tab](https://help.sap.com/docs/SAP_BUSINESS_ONE/68a2e87fb29941b5bf959a184d9c6727/4506b85a7d720487e10000000a155369.html)
(read through a search summary only); Odoo decimal accuracy (community sources only, no official page found).
Common pattern: separate rounding from display, per-item unit rounding, restriction of reduction after use. Differences:
SAP's decimals change stored calculations; ERPNext states no lock; Business Central checks rounding changes against ledger
entries. We adopt the separation and the restriction, and reject silent re-interpretation of data.

## Related

- [precision.md](../domain/precision.md) · [implementation slices](../phases/precision-implementation-slices.md)
- [0142](0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) ·
  [0143](0143-maintain-currency-minor-units-as-governed-reference-data.md) ·
  [0144](0144-version-sales-unit-price-precision-within-four-place-storage.md)
- [0012](0012-multi-currency-dual-amounts.md) · [0120](0120-settle-quotation-rounding-and-financial-evidence.md) · [0081](0081-central-company-profile-and-company-wide-settings.md)
