[← Decisions](README.md)

# 0079 — One document-numbering system: series of templates over a registry of variables

- **Status:** proposed — **research and design only; nothing is built.** Revised twice on 2026-10-02: first from a
  purchase-order-only proposal to one system for every document, then with the owner's seven adjustments (below), each
  checked against vendor documentation.
- **Affects:** `sequences` / `NumberingScheme` ([phase 0](../phases/phase-0-foundation.md)), purchase orders and goods
  receipts first, then every document that carries a number; one shared settings component in each owning module's settings
  screen; [extensibility.md](../architecture/extensibility.md) rung 1

## Context

Today a purchase order or goods receipt is numbered **when it is issued or posted**, from one gapless counter per company and
document type, formatted as a fixed prefix plus a zero-padded number (`0042`). The counter row is locked while a number is
taken, inside the document's own transaction, so two people never receive the same number and a draft that is never issued uses
none. Prefix and padding are columns, so a company's format is data — but there is no screen, and a format cannot contain a
date, a warehouse or anything else from the document.

Only two document types use the system. Most of the ERP's documents will carry a number as they arrive, and companies expect to
shape those numbers (`PO-2026-10-00042`, `LHR-GRN-1026-0042`). The owner's direction: **one backend system, dynamic and
extensible, with variables that can be added later and a settings screen in each module** — and, after review, seven specific
adjustments, now part of the design:

1. counter scope is an **explicit concept**, not derived from whatever appears in the template; variables may be decorative;
2. date variables use the **issue / officialisation date**, kept separate from the document's own date;
3. **gapless is a configurable policy**, not mandatory for every document type;
4. **multiple series per document type** are built into the structure from the start;
5. **company-defined constants** ship in the first release;
6. business-derived variables must use a value **frozen when the number is issued**;
7. the settings screen has a **preview/tester** that explains the date used, counter scope, reset, next numbers and any
   uniqueness conflict.

Owner decisions recorded: date basis = issue date; default year = calendar year with fiscal year also supported; gapless =
configurable; constants = yes; multiple series = architecture now, simple UI first.

## Findings (what established ERPs do, and what that says about the adjustments)

| System | Format | Scope | Gap policy | Several sequences for one document |
|---|---|---|---|---|
| **Dynamics 365** | **Segments**: constant text, an incrementing counter, and scope segments. | A declared **scope** — shared, company, legal entity, operating unit — optionally combined with **fiscal calendar period**. **The counter restarts per scope even when the format has no scope segment** ("SO-0029" is used in each company). | **Continuous** (never skips) vs **non-continuous** (in order, may skip, uses preallocation/caching). Official advice: *unless there is a regulatory or legal requirement for continuous, use non-continuous.* | **Number sequence groups** allocate specific sequences to specific customers or vendors. |
| **SAP** | A **number range object** per document type with **intervals**; internal or external assignment; can be **fiscal-year dependent**. | Fiscal-year dependence is an explicit property of the object. | Buffering is the default for speed and creates gaps and loses chronological order; *applications that must not have gaps, for example legally, should not use the buffer.* | Several intervals per object (number-range groups). |
| **Oracle Fusion** | Document sequences assigned to **categories** of documents. | A **determinant** (ledger, tax registration, …) is chosen explicitly; assignments are effective over date ranges. | Three explicit modes — **automatic** (gaps possible), **gapless**, **manual**; gapless only where needed because it slows processing; **avoid switching mode after use**; gapless can validate that numbers follow document dates. | Many sequences per category through assignments. |
| **Sage Intacct** | Prefix (≤ 30 characters), numeric/alpha counter, separators, suffix, optional **fiscal year** prefix/suffix. | Fiscal-year rollover is an explicit option per family. | Sequence id and type cannot change after creation; lowering the counter risks duplicates. | — |
| **ERPNext** | **Naming series** pattern: constant, `.YYYY.`, `.MM.`, company abbreviation, a document field, counter. | Per rendered series; optional per-fiscal-year series. | Not framed as a guarantee. | **Several series per document type**, chosen by the user. |
| **Odoo** | Prefix/suffix with date placeholders and `range_*` forms. | An explicit option: *sub-sequences per date range*. | A **no-gap** mode beside the standard one. (Community sources.) | Sequences per journal and per type. |
| **PostgreSQL** (our database) | — | — | Its native sequences **cannot give gapless numbers**: a value taken by `nextval` is not returned if the transaction aborts, and a crash can lose values. | — |

### What this verifies, point by point

- **Explicit scope (1).** Dynamics, SAP, Oracle, Intacct and Odoo all make the counter's scope a *declared property*, and
  Dynamics shows the counter restarting per company with no company text in the number. A derived scope (my first draft) is the
  minority approach and is the one that lets a harmless edit to a template silently start a new counter. **Adjustment 1 is
  confirmed.**
- **Issue date (2).** Oracle's gapless option exists precisely to keep numbers in the order of document dates, and SAP names loss
  of chronological order as the cost of buffering; both treat the number's date as the date the number was *taken*. Dynamics
  combines scope with the *fiscal calendar period*. **Adjustment 2 is consistent with all three.**
- **Gapless is a policy (3).** Dynamics ("use non-continuous unless regulation requires"), Oracle ("gapless only where
  essential; avoid switching mode after use") and SAP ("do not buffer where gaps are not allowed") all treat it as a
  per-sequence choice with a cost. PostgreSQL's own documentation rules out its native sequence for the gapless case, so the
  two guarantees need **two allocation methods**. **Adjustment 3 is confirmed — and mandatory-gapless would be the outlier.**
- **Several series from the start (4).** Dynamics (groups), SAP (intervals), Oracle (categories/assignments) and ERPNext (user
  picks a series) all let one document type own several sequences. None of them retrofitted it cheaply — it is a structural
  feature. **Adjustment 4 is confirmed.**
- **Constants (5)** correspond to Dynamics' *constant segments* and ERPNext's constant text, made reusable and named.
- **Frozen values (6)** have no vendor statement of their own, but every vendor numbers *an official document*, whose
  attributes are frozen by then; the rule is ours, and it is the safe reading.
- **Preview (7).** Odoo shows its placeholders and an example beside the form; the rest is our design.

## Decision proposed

### 1. Vocabulary

- **Document type** — a numbered kind of document (`purchase_order`), **registered in code** by its owning module.
- **Series** — a named numbering line for one document type (`DEFAULT`, `IMPORT`, `LHR`): its own format, counters and policy.
- **Format** — a template such as `PO-{YYYY}-{MM}-{SEQ:5}`: literal text, variables, and exactly one counter.
- **Counter scope** — which counter a number is drawn from; explicit (section 3).
- **Variable** — a registered value that can appear in a template: a *system variable* (code) or a *company constant*.
- **Numbering date** — the moment the document becomes official (issue, post), in the company's time zone.

### 2. Templates are parsed, never evaluated

A format is a short string: literal text (letters, digits and `- _ / .`; no spaces; at most 30 characters in a run), `{NAME}` or
`{NAME:option}` variables, and exactly one `{SEQ:n}` (zero-padded, 1–12). It is parsed on save into a structure; there are no
expressions or code. An unknown variable, a missing or repeated counter, a variable the document type cannot supply, or an
over-long result (proposed limit 40) is rejected with the reason.

### 3. Counter scope is an explicit setting, validated against the template

A series declares its **counter scope** as a *period granularity* (`NONE`, `YEAR`, `MONTH`, `DAY`, `FISCAL_YEAR`,
`FISCAL_PERIOD`) plus a list of **scope dimensions** the document type offers (`WAREHOUSE`, `BRANCH`, … — the company is always
in scope). The scope decides when a counter restarts and which counters exist. It is **independent of decoration**: a template
may contain `{REGION}` or `{SUPPLIER}` that do not affect which counter is used. Three validations tie scope to the template:

1. **Availability** — every dimension in the scope must be resolvable from the document at numbering time (the document type
   declares which it can supply). A scope on `WAREHOUSE` for a document with no warehouse is refused.
2. **Uniqueness** — two different counters must never produce the same text. So every period and dimension in the scope must be
   *visible* in the template through a variable that distinguishes it (a monthly scope needs a year and a month; a warehouse
   scope needs `{WAREHOUSE}`). Otherwise `0001` would repeat in the next period. This is a hard rejection, not a warning.
3. **Honesty** — a template that shows more than the scope (a month in the text with a yearly counter) is allowed and the
   tester says so ("the number carries the month, the counter continues through the year").

A scope or format change is a new format version; it starts new counters and never rewrites issued numbers.

### 4. Numbering date: the issue date, in the company's time zone

Date variables (`{YYYY}`, `{YY}`, `{MM}`, `{DD}`) and fiscal variables (`{FY}`, `{FP}`) use the **numbering date** the document
type registers (its issue/post moment) — **never the order or document date**, which stays a separate field. Dates are
evaluated in the **company's time zone, not UTC** — the application's time zone is UTC today, so an order issued at 00:30
local on the 1st would otherwise be numbered in the previous month; **a company time-zone setting is therefore a prerequisite**.
`{FY}`/`{FP}` come from the fiscal calendar by that date; if no fiscal year covers it, issuing is refused with a clear message
rather than numbering silently from the calendar. The default template uses the calendar year; the fiscal year is one
variable away.

### 5. Continuity is a per-series policy, with two allocation methods

| Policy | Method | Use |
|---|---|---|
| `GAPLESS` | The counter row is locked **inside the document's own transaction**, as today, so a rollback returns the number. Issues of one counter are serialised. | Statutory documents (invoices, in some regimes) and any series the owner chooses. |
| `ALLOW_GAPS` | The counter is advanced in its **own short transaction**, reserving a **block** of numbers (cached), so the document transaction never waits on a lock and a rollback or crash leaves a gap. Numbers still increase and never repeat. | Operational documents and high-volume series. |

- **PostgreSQL sequences are not used for gapless** (their own documentation says they cannot be); they or a block reservation
  serve the gaps-allowed method.
- **Each document type registers its default and whether it is locked**: a statutory type is `GAPLESS` and locked; an
  operational type defaults to `ALLOW_GAPS`, changeable. Stock movements, if they are ever numbered, take the cheap policy by
  default.
- **Existing series keep today's behaviour.** The migration creates purchase orders and goods receipts as `GAPLESS` so that
  nothing observable changes; the owner may switch them. Switching is a governed change (reason, warning, future numbers only);
  Oracle advises avoiding mode changes after use, so the editor warns.
- An optional later flag on gapless series — **numbers must follow document dates** (Oracle's transaction-date validation) —
  is reserved, not built.

### 6. Series from day one

Structure (all company-scoped, all additions to the model, none a redesign later):

```
numbering_series            id, company_id, doc_type, code, name, is_default, status (ACTIVE|RETIRED),
                            selector (nullable, reserved)                    UNIQUE(company, doc_type, code); one default per type
numbering_series_versions   id, series_id, template, counter_scope (period + dimensions), continuity,
                            effective_from, status (ACTIVE|SUPERSEDED), reason, changed_by      one ACTIVE per series; governed history
numbering_counters          id, company_id, series_id, counter_key, next_number, reserved_until      UNIQUE(series_id, counter_key)
numbering_allocations       id, company_id, doc_type, series_id, version_id, document_id, number, counter_key,
                            numbering_date, variables (frozen JSON), allocated_by, allocated_at   UNIQUE(company, doc_type, number)
numbering_constants         id, company_id, code, value, status, reason, changed_by, effective_from   governed history
```

- A document records the **series it was numbered from** (`number_series_id`, set at issue). A series is chosen by the user at
  creation or by a **selector rule** later (a warehouse, a supplier group, domestic vs import — Dynamics' groups, Oracle's
  assignments); the column is reserved now, the rule engine is not built.
- **First release UI exposes only the default series**, with an "Add a series" action under advanced options. Nothing in the
  model changes when the second series appears.
- **Cross-series uniqueness** is enforced by `UNIQUE(company, doc_type, number)` on the allocation log, written in the same
  transaction as the number — the backstop — and by a **save-time conflict check**: two active series of one type cannot have an
  identical pattern, and the tester lists any other series whose literal text could produce the same numbers (shown as a
  warning where overlap cannot be ruled out, a rejection where it is certain).

### 7. Variables

**System variables** are registered classes, owned by the module that owns the data (as `PermissionRegistry` and
`ApprovalSubjectRegistry` are). Each declares a key, label and description, a **resolver**, its **options**, whether it can
define a **scope dimension / period**, and a **sample** for the preview. A document type registers which variables it can supply
and **whether each is frozen at numbering** (below). Adding one later is adding one class; no schema change.

| Group | Variables |
|---|---|
| Date (numbering date) | `{YYYY}` `{YY}` `{MM}` `{DD}` (later `{DOY}`, `{WK}`) |
| Fiscal | `{FY}`, `{FP}` |
| Organisation | `{COMPANY}` (needs a company code), `{WAREHOUSE}` (goods receipts now; purchase orders after a ship-to field exists), later `{BRANCH}`, `{DEPARTMENT}`, `{ENTITY}` |
| Document | `{DOCTYPE}`, and per type where frozen: `{SUPPLIER}`, `{CURRENCY}`, `{CATEGORY}` |
| Counter | `{SEQ:n}` — exactly one |

**Company constants** ship in the first release: a name (`REGION`, upper-case letters, digits and `_`, ≤ 20 characters) and a
value (≤ 20 characters in the literal character set), e.g. `{REGION}` = `NORTH`, `{BRAND}` = `ABC`. They are a governed history
(reason, actor, future-only). A constant in use cannot be deleted, only retired.

### 8. Business-derived values are frozen at issue

A variable that comes from the document or its master data (`{SUPPLIER}`, `{CURRENCY}`, `{WAREHOUSE}`, `{CATEGORY}`) may only be
offered where the **document type declares the source frozen at numbering** — a purchase order's supplier and currency are
frozen when it is issued, a goods receipt's location when it is posted. Two protections make a later edit unable to change the
meaning of an issued number:

- the engine **snapshots every resolved value into the allocation log** (the text *and* the record it came from — `sup-punjab`
  and supplier id 7), so the meaning of `PUN` in a 2026 order stays explainable even if the supplier's code is later changed;
- the number is stored as plain text on the document and is never recomputed.

Each registered supply point carries a test that the source cannot be edited after the number is taken. A value that can change
after numbering is not offered.

### 9. The settings screen and its tester

One shared `NumberFormatPanel`, embedded under **Document numbers** in each owning module's settings (Purchasing Settings for
purchase orders and receipts; Inventory, Sales and so on as their documents appear). Each document type registers its screen
and manage permission (`<module>.numbering.manage`, proposed). API: `GET numbering/document-types`, `GET/POST
numbering/series`, `POST numbering/series/{series}/versions`, `GET/POST numbering/constants`, `POST numbering/preview`.

The **tester** is read-only — it never takes a number — and shows, for the template being edited and a sample the user can
change (a simulated issue date; sample values for each variable the type offers):

- the **result**: `PO-{YYYY}-{MM}-{SEQ:5}` → `PO-2026-10-00042`, with each part highlighted and named;
- **which date is used**: "Issue date (when the order is issued), in your company time zone (UTC+5): 2 Oct 2026", and that
  the order date is not used;
- **the counter scope**: "one counter per company, per calendar month";
- **when and how it resets**: "restarts at 00001 on the first issue of each month, by issue date";
- **the next example numbers**: the next three numbers, and the first number of the next period (`PO-2026-11-00001`), read from
  the live counter (current value shown) without consuming anything;
- **uniqueness and conflicts**: "OK — year and month are in the number", or the exact reason it is rejected, or a warning listing
  another series that could produce the same text;
- **continuity**: "gaps possible after a failure" or "no gaps; issues of this series are processed one at a time";
- **length** (`15 / 40`), and a plain-language **change impact** ("applies to orders issued from now on; issued numbers do not
  change").

A table beside it shows the **current counters** (per scope key) and lets a manager raise one with a reason; lowering is
refused.

### 10. Migration and phasing

- `sequences` rows become the first series version for each document type: `prefix + {SEQ:padding}`, scope `NONE`, continuity
  `GAPLESS`, `next_number` preserved — **no number is repeated or skipped by the change.** Existing numbers are not logged
  retroactively; the log starts at the migration.
- `NumberingScheme` remains the extension point (a plugin can rebind it); its call gains a **numbering context** (the document and
  its numbering date).
- Legacy or imported documents carry their own numbers; the counter start is raised above them.
- **Phases:** (1) engine — registries, parser and validator, series and versions, counters, both allocators, allocation log,
  preview, constants; purchase orders and goods receipts migrated with no visible change; (2) the shared settings component in
  Purchasing Settings, company code and company time zone, `{WAREHOUSE}` for goods receipts; (3) new document types register as
  they arrive; (4) later: selector rules for series, purchase-order `{WAREHOUSE}` after ship-to, numbers-follow-dates validation,
  field-backed variables, organisational scope.

## Consequences

- **Positive:** one place to maintain numbering; a new document type is a registration, a new variable is a class; each series
  chooses the guarantee it needs; safe by construction (parsed not evaluated, scope validated, unique, future-only, auditable,
  frozen).
- **Positive:** nothing changes for a company that never opens the screen.
- **Negative:** real infrastructure (five tables, two registries, a parser, two allocators, an editor); worth it only because many
  documents will use it. The gaps-allowed allocator is genuinely different code from today's and must be tested under
  concurrency.
- **Negative:** two policies mean documents in one company can behave differently; the screen must always say which.
- **Requires:** a company code; a **company time-zone setting**; a ship-to field on purchase orders before `{WAREHOUSE}` applies to
  them; a fiscal calendar that covers any date a `{FY}` template is used for.

## Open questions

1. **Continuity of the migrated purchase-order and goods-receipt series** — kept `GAPLESS` to avoid any change (proposed); the owner
   may prefer `ALLOW_GAPS` from the start.
2. **Where the company time zone is set** — a new company setting is needed; the application's own zone is UTC.
3. **Block size for the gaps-allowed method** and whether blocks are held per process or per counter — an implementation
   decision to settle by load test.
4. **How a series is chosen** the first time two exist — a picker on the document, or a selector rule — and which attribute
   (warehouse, supplier group, domestic/import) drives it.
5. **Statutory numbering for invoices** (sequential-series requirements in some tax regimes) was **not** researched and must be
   verified against current official guidance when invoice numbering is designed. This ADR keeps the gapless, locked policy
   available for it.

## Alternatives considered

**Derive the counter scope from the template (my first draft)** — rejected on the owner's point and the evidence: every vendor
declares scope explicitly, and a derived scope lets an edit silently start a new counter.

**Gapless for everything** — rejected: the vendors with the most mature guidance say not to, and PostgreSQL cannot provide it
without a row lock inside the document's transaction.

**Gaps allowed for everything** — rejected: statutory documents need the strict policy.

**One series per document type, add more later** — rejected by the owner and the vendors' history: retrofitting a second series
changes the counter key, the allocation log and the document's columns.

**Free-text pattern strings (ERPNext)** — a typo can create duplicates; a validated registry with a tester is safer.

**Editable or manual numbers after issue** — rejected: it defeats uniqueness and audit.

## Research basis

*Question:* how do mature ERPs declare numbering scope, handle several sequences per document, and offer gapless versus
gap-allowed numbering — and what do they say about concurrency cost? *Accessed:* 2026-10-02. Pages read in full are marked ✔;
the others were read as search summaries.

- Dynamics 365 ✔ [Number sequences overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/number-sequence-overview)
  — segments, declared scope combinable with fiscal calendar period, restart per company without a company segment, sequence
  groups, continuous vs non-continuous, preallocation, "use non-continuous unless regulation requires continuous".
- SAP ✔ [How the number range buffer works](https://help.sap.com/doc/saphelp_nw73ehp1/7.31.19/en-US/47/d5659d167e3c84e10000000a42189c/content.htm?no_cache=true)
  (buffering trades chronological continuity for speed; do not buffer where gaps are not allowed) and
  [Maintaining a number range object](https://help.sap.com/docs/SAP_NETWEAVER_702/fe143c646c5510148906c2564726e947/48d58f92982b424be10000000a421937.html)
  (intervals, internal/external, fiscal-year dependence).
- Oracle Fusion ✔ [Document sequences](https://docs.oracle.com/en/cloud/saas/financials/26b/fafcf/document-sequences.html)
  (automatic / gapless / manual, avoid switching modes, performance caution, transaction-date validation) and
  [Guidelines for managing document sequences](https://docs.oracle.com/en/cloud/saas/procurement/25c/oapro/guidelines-for-managing-document-sequences.html).
- Sage Intacct ✔ [Document sequences for auto-numbering IDs](https://www.intacct.com/ia/docs/en_US/help_action/Company/Document_numbering/document-sequences-for-ids.htm).
- PostgreSQL ✔ [Sequence manipulation functions](https://www.postgresql.org/docs/current/functions-sequence.html) — values are
  not returned on abort, so native sequences "cannot be used to obtain gapless sequences".
- ERPNext [Document naming settings](https://docs.frappe.io/erpnext/document-naming-settings) (partial page) and
  [Naming](https://frappeframework.com/docs/v14/user/en/basics/doctypes/naming) — series, date parts, per-fiscal-year series,
  several series per document type.
- Odoo — community discussions of `ir.sequence` placeholders, date-range sub-sequences and no-gap (secondary sources; an
  official page should be read before implementation).

*Remaining uncertainty:* vendor behaviour was taken from documentation, not tested; the Odoo and ERPNext placeholder lists are
not authoritative; the conflict check between two series' patterns is a design intention whose exactness (certain overlap versus
possible overlap) needs a prototype; legal gapless requirements are out of scope (open question 5).

## Related

- [Phase 0 — sequences](../phases/phase-0-foundation.md) · [invariants.md](../architecture/invariants.md#8) ·
  [purchasing.md](../domain/purchasing.md) · [extensibility.md](../architecture/extensibility.md)
- [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md) (governed history),
  [ADR 0070](0070-shared-configurable-approval-engine.md) (a registry in the same style)
