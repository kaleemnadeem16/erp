[← Decisions](README.md)

# 0079 — One document-numbering system: templates over a registry of variables

- **Status:** proposed — **research and design only; nothing is built.** The owner decides before any implementation.
- **Date:** 2026-10-02 (revised the same day: the first draft covered purchase orders only; the owner asked for one
  system every numbered document can use)
- **Affects:** `sequences` / `NumberingScheme` ([phase 0](../phases/phase-0-foundation.md)), purchase orders and goods
  receipts first, then every document that carries a number; one shared settings component in each owning module's settings
  screen; [extensibility.md](../architecture/extensibility.md) rung 1

## Context

Today a purchase order or goods receipt is numbered **when it is issued or posted**, from one gapless counter per company and
document type, formatted as a fixed prefix plus a zero-padded number (`0042`). The counter row is locked while a number is
taken, so two people never receive the same number and a draft that is never issued uses none (a draft shows as
"Draft #id"). Prefix and padding are columns, so a company's format is data — but there is no screen, and a format cannot
contain a date, a warehouse or anything else from the document.

Only two document types are numbered through the system. Most of the ERP's documents will carry a number as they arrive
(sales documents, invoices, stock transfers, counts, requests, adjustments), and companies expect to shape those numbers
(`PO-2026-10-00042`, `LHR-GRN-1026-0042`). Hard-coding a format per document would repeat the work and drift. The owner's
direction: **one backend system, dynamic and extensible, with variables that can be added later, configured from each module's
own settings screen.**

**Questions researched:** how do mature ERPs let a company shape document numbers; what variables do they offer; what do they
guarantee about gaps and duplicates; and what is the smallest design that stays safe and lets new variables and new document
types be added without schema changes?

## Findings

### What the systems offer

| System | Format building blocks | Counter scope and reset | Gaps and rules |
|---|---|---|---|
| **Dynamics 365** | A number is built from **segments**: constant text, an incrementing alphanumeric counter, and scope segments (company, legal entity, operating unit). | A **scope** (shared, company, legal entity, operating unit) decides which documents share a counter. | A sequence is **continuous** (never skips) or **non-continuous** (in order, may skip); continuous costs performance. |
| **SAP** | A **number range object** per document type with **intervals**; numbers are internal or external; the object can be **fiscal-year dependent**. | One interval set per object, and per year when year-dependent. | Buffering is the default for speed and **creates gaps and loses chronological order**; a rolled-back transaction loses its number. |
| **Oracle Fusion** | Document sequences assigned to a **category** of documents; a **determinant** (ledger, tax registration, …) limits a sequence to one business entity. | One sequence per category and determinant, within a date range. | Three modes: **automatic**, **gapless**, **manual**; gapless only where essential (performance). |
| **Sage Intacct** | A **fixed prefix** (up to 30 characters), a **numeric or alphabetic counter**, **separators** (one character each), a **fixed suffix** (up to 30), and an optional **fiscal year** as prefix or suffix. | Fiscal-year rollover restarts the counter; offered only for some document families. | The sequence id and type cannot change after creation; editing a live sequence is warned against, and **lowering the counter below issued values causes duplicate-number errors**. |
| **ERPNext** | A **naming series** pattern: constant text, `.YYYY.`, `.MM.`, the company abbreviation (`.abbr.`), a field value of the document (for example `.vendorid.`), and `#####` for the counter; several series can be offered per document type. | The counter is per rendered series, so a year or company in the pattern gives it its own counter; an optional per-fiscal-year series. | Counter changes affect only new documents; no duplicate guard is described. |
| **Odoo** | A prefix and suffix with **placeholders**: year (with and without century), month, day, day of year, week of year, weekday, hour, minute, second — plus `range_*` forms tied to a **date-range sub-sequence**; padding. | Optional **sub-sequences per date range** that reset yearly or monthly. | A **no-gap** mode beside the standard one. (Community sources only; no official page was fetched.) |

### What they agree on

1. **A number is a template of parts, not code**: literal text, date parts, a *scope* part (company, site, legal entity,
   fiscal year), and exactly one counter.
2. **The counter's scope is whatever else varies in the template.** Put the year in and the counter restarts each year; put
   the warehouse in and each warehouse counts alone. (Odoo and Intacct make the reset an explicit setting beside the format;
   Dynamics and ERPNext let the template imply it.)
3. **Gaps are an explicit, named choice** with a performance price (Dynamics, SAP, Oracle, Odoo).
4. **Changing a live format is dangerous**: formats are stable within a year; a counter must never be lowered; change at a
   period boundary (Intacct's guidance and general practice).
5. **Variables come from the document or its context**: its dates, its company, its site/warehouse, sometimes a master-data
   field (ERPNext's custom field, SAP's material type for material numbers).

### Where they differ

SAP and Oracle tie numbering to legal entities and fiscal periods and treat it as heavy configuration; ERPNext and Odoo make
it a string a business user edits (and accept duplicate risk when it is misused); Dynamics and Intacct separate the *format*
from the *continuity guarantee*. Our fit is closest to Dynamics/Intacct: a validated structure, not a free string, and the
guarantee as its own setting.

## Decision proposed (for the owner)

### 1. A number format is a template over registered variables

A format is a short template — for example `PO-{YYYY}-{MM}-{SEQ:5}` — made of:

- **literal text**: letters, digits and `- _ / .`; no spaces; at most 30 characters in a run (Intacct's limit);
- **variables**, written `{NAME}` or `{NAME:option}` — the date parts, scope parts, and any variable a module registers;
- **exactly one counter**, `{SEQ:n}` (zero-padded to n digits, 1–12).

It is parsed on save into a structure and **never evaluated**: no expressions, no code, no free formulas. An unknown variable,
a missing or repeated counter, or an over-long result (proposed: 40 characters) is rejected with a message that says why.

### 2. The variable registry — code-owned, extendable without a schema change

Variables are registered in code, the way permissions and approval subjects already are (`PermissionRegistry`,
`ApprovalSubjectRegistry`), by the module that owns the data. Adding a variable later is adding one registered class:

| A variable declares | Meaning |
|---|---|
| `key`, label, description | What it is called and how it is explained beside the editor |
| **resolver** | Given a numbering context, returns its text (`2026`, `LHR`) |
| **options** | For example a date pattern, a width, upper/lower case |
| **scopes the counter** | Whether its value is part of the counter key (a year or a warehouse is; a literal is not) |
| **sample** | A value for the live preview |

A **document type** also registers itself — code (`purchase_order`), label, owning module, the **numbering date** it uses
(issue date by default), the permission that manages its format, its default template, and **which variables it can
supply and whether each is stable once the number is taken**. So the editor offers a document type only the variables it can
really fill, and the validator refuses the rest. A number that names a warehouse the document does not have would be wrong.

**Two kinds of variable**, so a company can also shape the string itself:

- **System variables** — resolvers written in code (below).
- **Company-defined constants** — a named string the company creates (`{REGION}` = `NORTH`), used in any template. Changing a
  constant affects only numbers issued afterwards. This is the "strings we create" half of the request; it needs no code.

A later extension — a **field-backed variable** (a stable master-data field such as an item category's code, as SAP does for
material numbers) — is one more kind of registration, not a redesign.

### 3. Proposed starting variables

| Group | Variables | Notes |
|---|---|---|
| **Date** (from the document type's numbering date) | `{YYYY}` `{YY}` `{MM}` `{DD}` | `{DOY}` (day of year) and `{WK}` can follow; Odoo offers them, few companies use them |
| **Fiscal** | `{FY}` (for example `2026` or `2025-26`), `{FP}` (period number) | Fiscal years and periods exist in the ledger already |
| **Organisation** | `{COMPANY}` | Needs a **company code** (a company has only a name today) |
|  | `{WAREHOUSE}` | Goods receipts have a location, hence a warehouse, **now**; a purchase order has **no receiving warehouse** (that is a ship-to field not built), so purchase orders get it only after that field exists |
|  | `{BRANCH}`/`{DEPARTMENT}`/`{ENTITY}` | Wait for organisational scope (Q26) and multi-company |
| **Document** | `{DOCTYPE}` (a short code such as `PO`, `GRN`) | Often just literal text |
|  | `{SUPPLIER}`, `{CURRENCY}`, `{CATEGORY}` | Offered per document type **only where the value is frozen when the number is taken** (an issued purchase order's supplier and currency are frozen, so they qualify; an item category on a draft does not) |
| **Constants** | company-defined strings | Section 2 |
| **Counter** | `{SEQ:n}` | Exactly one |

Not proposed: user initials or any other attribute of the person (it changes who "issued" it, not what it is); anything that
can change after the number is taken.

### 4. The counter's scope is derived, not configured

The counter key is *(document type, series, the values of every variable flagged "scopes the counter")*. So `{YYYY}` gives a
yearly counter, `{YYYY}{MM}` a monthly one, `{WAREHOUSE}` one per warehouse — **no separate "reset policy" field to get wrong.**
Two validations keep numbers unique:

- a **date chain**: `{MM}` needs a year (`{YYYY}`/`{YY}`/`{FY}`) in the template, and `{DD}` needs `{MM}`, otherwise `0001`
  would repeat in a new year;
- the database keeps `UNIQUE (company, number)` as a backstop (purchase orders already have it).

### 5. Allocation and guarantees

- **Allocation stays at the moment the document becomes official** (issue, post), in the **same transaction** and under the
  counter's row lock — so the **gapless** guarantee we have today remains the default. A per-document-type setting
  `GAPLESS | ALLOW_GAPS` is reserved; `ALLOW_GAPS` (cached ranges for throughput) is built only when a document type needs it.
- **A counter may only be raised**, never lowered (Intacct's duplicate warning), with a reason; a start value above the issued
  maximum supports migrating legacy numbers.
- Every number taken is also recorded in an **allocation log** (document type, document, number, format version, counter key,
  who, when). It makes gap analysis possible, supports a "preview the next number" without consuming it, and gives the audit
  trail the structure of ADR 0071 already uses.

### 6. Changing a format is governed and future-only

Formats are a **governed history** — the pattern of [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md) and
[ADR 0076](0076-company-control-over-hand-chosen-stock.md): a row per change with a **reason** and **actor**, effective from the
change, the old row closed (never edited), the scope column (`COMPANY` now) leaving room for a warehouse- or series-level
format later. **Issued numbers are never rewritten.** The editor warns that formats are best changed at a period boundary.

### 7. Series (several formats per document type) — later, same mechanism

A **series** is a named format for a document type (for example local vs import orders). It is one more component of the
counter key, chosen by the user at creation or by a rule; the data shape (a `series` code, default `DEFAULT`) is included from
the start so adding it later is not a migration of history.

### 8. One shared settings component, placed in each module

A single `NumberFormatPanel` — template box, a **palette of the variables this document type offers** (click to insert), a
**live preview** ("your next order will be `PO-2026-10-00042`", rendered from the allocation log's last number and sample
values), the validation messages, the history — is embedded in the owning module's settings screen under **Document
numbers**: Purchasing Settings (purchase orders, goods receipts), Inventory Settings (transfers, counts, adjustments), Sales
(quotations, orders, invoices) and so on. Each document type's registration names its screen and its manage permission
(`<module>.numbering.manage`, proposed; reading it needs the same or the history capability). One backend API:
`GET numbering/document-types`, `GET/POST numbering/formats`, `POST numbering/preview`.

### 9. Migration and compatibility

- `sequences` rows become the first active format: `prefix + {SEQ:padding}` with the existing `next_number` preserved, so no
  number is repeated or skipped by the change.
- `NumberingScheme` stays the extension point ([extensibility.md](../architecture/extensibility.md)); its signature gains a
  **numbering context** (the document, its date) beside `docType`. A plugin can still rebind it.
- Imported or legacy documents carry their own numbers; they are outside the counter (SAP's *external* assignment) and the
  start value is raised above them.

### 10. Phasing

1. **Engine**: registries (variables, document types), template parser and validator, counters, governed formats, allocation
   log, preview endpoint; purchase orders and goods receipts moved onto it with their current format as the first row
   (nothing visible changes). System variables: date set, `{FY}`, `{DOCTYPE}`, `{SEQ}`.
2. **Settings screens**: the shared panel in Purchasing Settings; `{COMPANY}` with a company code; company-defined constants;
   `{WAREHOUSE}` for goods receipts.
3. **As documents arrive**: each new numbered document registers its type and the variables it can supply.
4. **Later**: purchase-order `{WAREHOUSE}` (after ship-to), series, `ALLOW_GAPS`, field-backed variables, organisational scope.

## Consequences

- **Positive:** one place to maintain numbering; a new document type is a registration, a new variable is a class; no per-
  document format code; safe by construction (parsed not evaluated, validated, unique, future-only, auditable).
- **Positive:** nothing changes for a company that never opens the screen.
- **Negative:** a real piece of infrastructure (two tables plus the log, two registries, a parser, an editor); worth it only
  because many documents will use it.
- **Negative:** deriving the counter scope from the template means a template edit can start a new counter (a new month, a new
  warehouse) — correct, but the preview must show it.
- **Requires:** a company code field; a ship-to field on purchase orders before `{WAREHOUSE}` applies to them; owner answers below.

## Open questions for the owner

1. **Which date** decides the date parts for purchase orders: the order date (typed, can be back-dated) or the **issue date**
   (the moment the number is taken)? The issue date is the safer default and what the registry's "numbering date" defaults to.
2. **Calendar or fiscal year** as the usual choice (`{YYYY}` vs `{FY}`)? Both are offered; which should the default
   template use?
3. **Is a gapless guarantee required** for any document a customer will number, or is "unique and in order" enough? (Keeping
   gapless costs little at our scale, so the default stays.)
4. **Company-defined constants** — wanted from the first release, or only system variables first?
5. **Several series per document type** (the user picks one) — needed soon, or one format per document type is enough?
6. **Statutory numbering for invoices** (some tax regimes require sequential series) was **not** researched and must be
   verified against current official guidance when invoice numbering is designed.

## Alternatives considered

**A per-document hard-coded format** — rejected: repeats the work for every document and drifts.

**A free-text pattern string like ERPNext's** — flexible, but a typo can create duplicates; a validated variable registry with a
live preview is safer for a business user.

**An explicit "reset yearly/monthly" setting beside the template (Odoo, Intacct)** — rejected as the primary mechanism: it
lets the two disagree (a yearly reset with no year in the template); deriving the scope from the template cannot.

**Editable or manual numbers after issue** — rejected: it defeats uniqueness and audit.

**Leave numbering fixed** — keeps the guarantee and costs nothing, but cannot express the formats companies already use and
leaves every future document to solve it again.

## Research basis

*Question:* how do mature ERPs let a company shape document numbers, which variables do they offer, and what do they
guarantee about gaps and duplicates? *Accessed:* 2026-10-02. Vendor behaviour comes from documentation pages and search
summaries of them; it was not tested.

- Dynamics 365: [Number sequences overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/number-sequence-overview)
  (segments, scope, continuous vs non-continuous).
- SAP: [Maintaining a number range object](https://help.sap.com/docs/SAP_NETWEAVER_702/fe143c646c5510148906c2564726e947/48d58f92982b424be10000000a421937.html)
  (intervals, internal/external, fiscal-year dependence) and [How the number range buffer works](https://help.sap.com/doc/saphelp_nw73ehp1/7.31.19/en-US/47/d5659d167e3c84e10000000a42189c/content.htm?no_cache=true)
  (buffering gaps). The page on the official document-number format could not be read.
- Oracle Fusion: [Document sequences](https://docs.oracle.com/en/cloud/saas/financials/26b/fafcf/document-sequences.html) and
  [Guidelines for managing document sequences](https://docs.oracle.com/en/cloud/saas/procurement/25c/oapro/guidelines-for-managing-document-sequences.html)
  (automatic / gapless / manual; determinants; performance caution).
- Sage Intacct: [Document sequences for auto-numbering IDs](https://www.intacct.com/ia/docs/en_US/help_action/Company/Document_numbering/document-sequences-for-ids.htm)
  (prefix, counter, separators, suffix, fiscal-year rollover; edit and duplicate warnings).
- ERPNext: [Document naming settings](https://docs.frappe.io/erpnext/document-naming-settings),
  [Naming](https://frappeframework.com/docs/v14/user/en/basics/doctypes/naming) and
  [Company-wise naming series](https://docs.erpnext.com/docs/v14/user/manual/en/customize-erpnext/articles/company-wise-naming-series).
- Odoo: community discussions of `ir.sequence` placeholders and date-range sub-sequences (secondary; an official page should be
  read before implementation).

*Remaining uncertainty:* the exact placeholder lists of each vendor are not authoritative here (Odoo's comes from forum
posts; ERPNext's page is partial); the starting variable list above is our own judgement from the common pattern and our data.
Legal gapless rules are out of scope (open question 6).

## Related

- [Phase 0 — sequences](../phases/phase-0-foundation.md) · [invariants.md](../architecture/invariants.md#8) ·
  [purchasing.md](../domain/purchasing.md) · [extensibility.md](../architecture/extensibility.md)
- [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md) (governed history pattern),
  [ADR 0070](0070-shared-configurable-approval-engine.md) (a registry the same way)
