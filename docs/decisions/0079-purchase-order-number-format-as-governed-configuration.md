[← Decisions](README.md)

# 0079 — Let a company shape its purchase-order numbers

- **Status:** proposed — **research and design only; nothing is built.** The owner decides before any implementation.
- **Date:** 2026-10-02
- **Affects:** `sequences` / `NumberingScheme` ([phase 0](../phases/phase-0-foundation.md)), purchase-order issue,
  goods-receipt posting, and later every numbered document ([extensibility.md](../architecture/extensibility.md))

## Context

Today a purchase order is numbered **when it is issued**, from one gapless counter per company and document type, formatted
as a fixed prefix plus a zero-padded number (`0042`). The counter row is locked while a number is taken, so two people can
never receive the same number and cancelled or deleted *drafts* use none (a draft is shown as "Draft #id"). The prefix and
padding are columns, so a company's format is data, not code — but there is no screen to change them, and the format cannot
contain a date, a warehouse or any other part of the document.

Companies commonly want the number to carry meaning: `PO-2026-10-00042`, `LHR-1026-0042` (warehouse, month, year, counter).
The owner asked what that would take. **Question researched:** how do mature ERPs let a company shape document numbers, what
do they guarantee about gaps, and what would a safe version look like here?

## Findings (what the systems do)

| System | Format | Counter scope and reset | Gaps |
|---|---|---|---|
| **Dynamics 365** | A number is built from **segments**: constant text, an incrementing alphanumeric counter, and scope segments (company, legal entity, operating unit). | A **scope** (shared, company, legal entity, operating unit) decides which documents share one counter. | A sequence is **continuous** (no skipped numbers, may be used out of order) or **non-continuous** (in order, may skip); continuous costs performance. |
| **SAP** | A **number range object** per document type holds **intervals**; numbers are internal or external; the object can be **fiscal-year dependent**, giving each year its own intervals. | One interval set per object (and per year when year-dependent). | Buffering is the default for speed and **creates gaps and loses chronological order**; a rolled-back transaction loses its number. |
| **Oracle Fusion** | Document sequences assigned to a **category** of documents; a **determinant** (ledger, tax registration, …) limits a sequence to one business entity. | One sequence per category and determinant, within a date range. | Three modes: **automatic**, **gapless**, **manual** — and Oracle advises gapless only where essential, because it affects performance. |
| **ERPNext** | A **naming series** pattern: constant text, date parts (`.YYYY.`, `.MM.`), the company abbreviation, and `#####` for the counter. A user can pick among several series. | The counter is per rendered series, so a year or company in the pattern gives it its own counter. | Not framed as a guarantee. |
| **Odoo** | A prefix with date placeholders (`%(year)s`, `%(month)s`), padding, and optional **sub-sequences per date range** that reset yearly or monthly. | Per sequence, per date range. | A **no-gap** mode exists beside the standard one. (Community sources only; no official page was fetched.) |

**Common pattern.** Numbering is a *configuration of segments*, not code: constant text, date parts, a scope part (company,
site, legal entity), and a counter. **The counter's scope is whatever else is in the format** — put the year in and the counter
restarts each year; put the warehouse in and each warehouse counts alone. And **gaps are an explicit choice**: every system
that offers a strict guarantee says it costs throughput, and several default to allowing gaps for speed.

**Differences that matter here.** SAP and Oracle tie sequences to fiscal periods and legal entities, which a one-company
product can defer; Odoo and ERPNext make it a pattern string a business user edits; Dynamics and Oracle separate the
*format* from the *continuity guarantee*.

## What this means for our ERP

1. **We already have the strict version.** Issue-time numbering under a row lock is gapless, which is the expensive end of
   the range. A purchase order is not a statutory invoice; most companies need *unique and orderly*, not legally gapless. The
   guarantee should stay the default (it is already built and cheap at our scale) and be named, not silently changed.
2. **A segmented format is a small step.** Tokens such as `{YYYY}`, `{YY}`, `{MM}`, `{COMPANY}` and `{SEQ:5}`, plus constant
   text, would cover the common patterns. The counter key becomes *(document type, the rendered non-counter tokens)*, so a
   year or month token gives each period its own counter. A `(company, number)` unique constraint stays as the safety net.
3. **A warehouse token is not available yet.** A purchase order today has a supplier and a supplier site, but **no receiving
   warehouse** — that is chosen on the goods receipt, and a ship-to/deliver-to on the order is on the list of things not built.
   `{WH}` is therefore a dependency on that field, not a numbering feature. A number that names a warehouse the order does not
   have would be wrong.
4. **Meaning in a number must not change after issue.** A year, month, company or issuing warehouse is fixed at issue and safe.
   A *supplier* code or a status is not: the supplier on a draft can change, and an issued order's number cannot. Recommend
   allowing only tokens that are fixed at the moment of issue.
5. **Changing the format is a governed, future-only change** — the same pattern as [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md):
   a row per change with a reason and actor, effective from the change, never rewriting issued numbers. A format change in
   mid-year must not be able to repeat a number: validation requires the counter's reset scope to be visible in the format
   (a yearly reset without a year token would repeat `0001` every year).

## Decision proposed (for the owner)

**Phase A — a settings screen and safe tokens (small).** Per document type: a template with `{YYYY}`, `{YY}`, `{MM}`,
`{COMPANY}`, constant text and `{SEQ:n}`; a counter reset of *never / yearly / monthly* (validated against the template);
governed history; a live preview ("your next order will be `PO-2026-10-00042`"); gapless as today. Permission
`purchasing.numbering.manage` (name proposed). Applies to purchase orders and goods receipts first; the numbering contract
already serves every document type.

**Phase B — scope tokens that need a field.** `{WH}` (after the order carries a ship-to/receiving warehouse) and, when
multi-company or legal-entity scope exists, `{ENTITY}`. A user-selectable **series** per order (for example local vs import)
is a later extension, modelled as a second counter key, not a different mechanism.

**Not recommended:** letting a company reorder or edit numbers after issue; tokens from mutable fields (supplier, status);
silently switching to non-gapless counters.

## Open questions for the owner

1. **Which date** decides `{YYYY}`/`{MM}`: the order date (typed by the user, can be back-dated) or the issue date (the
   moment the number is taken)? The issue date is the safer default.
2. **Calendar or fiscal year** — `{FY}`? Fiscal years exist in the ledger, so this is answerable, but it adds a token.
3. **Is gapless required** for purchase orders for any company you serve, or is "unique and in order" enough?
4. **Does any customer need the warehouse in the number now?** If so, the ship-to field on the order comes first.
5. **Several series per document type** (the user picks one when creating), or one format per company?
6. Statutory numbering rules for *invoices* (for example sequential series requirements in some tax regimes) were **not**
   researched here and must be verified against current official guidance when invoice numbering is designed.

## Alternatives considered

**Leave numbering fixed** — keeps the guarantee and costs nothing, but cannot express the formats companies already use.

**Free-text pattern strings like ERPNext (`PO-.YYYY.-.#####`)** — flexible, but a typo can create duplicate numbers; a token
list with validation and a live preview is safer for a business user.

**Per-user or per-document editable numbers** — rejected: it defeats uniqueness and audit.

## Research basis

*Question:* how do mature ERPs let a company shape document numbers, and what do they guarantee about gaps? *Accessed:*
2026-10-02. Sources compared (search summaries of the vendors' documentation; individual pages were not all fetched):

- Dynamics 365: [Number sequences overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/number-sequence-overview)
  (segments, scope, continuous vs non-continuous).
- SAP: [Maintaining a number range object](https://help.sap.com/docs/SAP_NETWEAVER_702/fe143c646c5510148906c2564726e947/48d58f92982b424be10000000a421937.html)
  (intervals, internal/external, fiscal-year dependence) and [How the number range buffer works](https://help.sap.com/doc/saphelp_nw73ehp1/7.31.19/en-US/47/d5659d167e3c84e10000000a42189c/content.htm?no_cache=true)
  (buffering gaps).
- Oracle Fusion: [Document sequences](https://docs.oracle.com/en/cloud/saas/financials/26b/fafcf/document-sequences.html) and
  [Guidelines for managing document sequences](https://docs.oracle.com/en/cloud/saas/procurement/25c/oapro/guidelines-for-managing-document-sequences.html)
  (automatic / gapless / manual; determinants; the performance caution).
- ERPNext: [Naming](https://frappeframework.com/docs/v14/user/en/basics/doctypes/naming) and
  [Company-wise naming series](https://docs.erpnext.com/docs/v14/user/manual/en/customize-erpnext/articles/company-wise-naming-series).
- Odoo: community discussions of `ir.sequence` date-range sub-sequences and `no_gap` (secondary sources; an official page
  should be read before implementation).

*Remaining uncertainty:* vendor behaviour was taken from documentation summaries, not tested; legal gapless requirements were
out of scope (see open question 6).

## Related

- [Phase 0 — sequences](../phases/phase-0-foundation.md) · [invariants.md](../architecture/invariants.md) ·
  [purchasing.md](../domain/purchasing.md)
