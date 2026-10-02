[← Decisions](README.md)

# 0079 — One document-numbering system: series of templates over a registry of variables

- **Status:** accepted (2026-10-02, after the owner's final adjustments below). Phase 1 — the engine and the migration of
  purchase orders and goods receipts — is being built; the settings screens are the next step.
- **Affects:** a new central `Numbering` module (the framework), `sequences` / `NumberingScheme` ([phase 0](../phases/phase-0-foundation.md))
  which it replaces, purchase orders and goods receipts first and then every numbered document, the existing company time zone (`accounting_timezone`), **Settings → Document Numbering**, [extensibility.md](../architecture/extensibility.md) rung 1

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

**Final framing (owner, 2026-10-02):** this is a **central, reusable Document Numbering framework** for the whole ERP — purchase
orders, goods receipts, sales orders, invoices, credit notes, stock transfers, work orders and every future internal document
— not something built inside Purchasing. A new document should need only to register itself and supply a numbering context.

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

## Decision

### 1. The concepts, kept separate

| Concept | What it is | Owned by |
|---|---|---|
| **Document type** | A numberable kind of document (`PURCHASE_ORDER`). Registered in code. Declares its variables, defaults, permission, and when it becomes official. | The module that owns the document |
| **Number series** | A named numbering line of one document type (`DEFAULT`, `IMPORT`, `LOCAL`). Has its own counters. A type structurally supports many; the first screen shows only the default. | Numbering (data) |
| **Series version** | The governed configuration of a series at a time: **template + counter scope + continuity policy**. A change adds a version; it never edits history. | Numbering (data) |
| **Template** | The format string, e.g. `PO-{BRANCH}-{YYYY}-{SEQ:5}`. | Inside a version |
| **Counter scope** | Which counter a number is drawn from: a **period** plus **dimensions** (e.g. *Company + Branch + Year*). Explicit. | Inside a version |
| **Series routing** | How a document is assigned a series: a manual choice, an automatic rule, or the default. | Numbering (data) |
| **Counter** | The running number for one *(series, counter key)*, where the key is the scope's resolved values. | Numbering (data) |
| **Allocation log** | The permanent, frozen record of every number issued. | Numbering (data) |
| **Company constants** | Company-defined named strings usable in templates (`{REGION}`=`NORTH`). | Numbering (data) |

The engine knows none of the documents. **Document-specific knowledge stays with the document**, which hands the engine a
*numbering context* (section 3).

### 2. The central document-type registry and the extension contract

Numbering lives in its own module and exposes one contract. A numberable document implements it and registers it in its own
service provider — no numbering tables, services or screens of its own:

```php
interface NumberableDocumentType
{
    public function key(): string;                    // 'PURCHASE_ORDER'
    public function label(): string;                  // 'Purchase order'
    public function module(): string;                 // 'purchasing' — which module's settings link here
    public function becomesOfficial(): string;        // 'when it is issued' — shown beside the date explanation
    /** @return list<VariableSupply> variables this document can supply, and whether each is frozen at numbering */
    public function variables(): array;
    /** @return list<string> keys of supplied variables that may be used as counter-scope dimensions (e.g. BRANCH) */
    public function dimensions(): array;
    /** @return list<string> attributes the document exposes to routing rules (e.g. ORDER_TYPE) */
    public function routingAttributes(): array;
    public function defaultTemplate(): string;        // 'PO-{YYYY}-{SEQ:5}'
    public function defaultScope(): CounterScope;     // Company + Year
    public function defaultContinuity(): Continuity;  // ALLOW_GAPS
    public function continuityLocked(): bool;         // true for a statutory type: GAPLESS and not changeable
    public function managePermission(): string;       // who may change this type's series
    public function sampleContext(CarbonImmutable $at): NumberingContext;  // used by the tester
    /** @return array<string, list<string>> dimension => values that exist today, for conflict checks and the tester */
    public function knownDimensionValues(): array;
}
```

Adding a document is: implement this once, bind it in the module's provider (`Numbering::register(...)`), and call
`NumberingService::allocate($context)` at the moment the document becomes official. Everything else — series, versions,
counters, the log, the screens, the tester, permissions' wiring — is common.

### 3. The numbering context: what the document supplies

```
NumberingContext
  documentType      'PURCHASE_ORDER'
  documentId        the document's id (set once it exists)
  issuedAt          the instant the document became official (UTC)
  values            { SUPPLIER_CODE: SUP012 (source: supplier_accounts#12), CURRENCY: USD (currencies#3), BRANCH: LHR (branches#2) }
  routing           { ORDER_TYPE: IMPORT }
  requestedSeries   optional — a manual choice, only for a series marked selectable
```

Each value is a **frozen text plus the reference it came from**. The engine only resolves registered variables, picks a series,
allocates the counter and returns/stores the number; it never queries suppliers, customers, warehouses or sales channels.

### 4. Variables

A **variable** is registered once (key, label, description, kind, options, sample) and is one of: **engine variables** the
engine computes itself — `{YYYY} {YY} {MM} {DD}`, `{FY} {FP}` (from the fiscal calendar through a port), `{SEQ:n}`,
constants (a `{DOCTYPE}` variable was dropped: fixed text does the same job) — or **document variables** (`{SUPPLIER_CODE}`, `{CURRENCY}`, `{BRANCH}`, `{WAREHOUSE}`, `{CATEGORY}`, …) whose value
the document's context supplies. A document type lists the variables it supplies; the template validator refuses any other.
A new variable is a registration, not a schema change. `{COMPANY}` needs a company code and arrives with it.

### 5. Templates are parsed, never evaluated

Literal text (letters, digits, `- _ / .`; no spaces; ≤ 30 characters per run), `{NAME}`/`{NAME:option}`, and exactly one
`{SEQ:n}` (1–12 digits). Parsed on save into parts; no expressions or code. Unknown or unsupplied variables, a missing or
repeated counter, or a result over 40 characters are rejected with the reason.

### 6. Explicit counter scope

A version declares its **counter scope**: a **period** — `NONE, YEAR, MONTH, DAY, FISCAL_YEAR, FISCAL_PERIOD` — plus **dimensions**
chosen from those the document type offers. Company is always in scope. The scope alone decides which counter a number comes
from and when it restarts. A variable in the template that is not in the scope is **decorative**: `PO-{BRANCH}-{YYYY}-{SEQ:5}`
with scope *Company + Year* is one counter for the company, with the branch shown but not counted. Every dimension in a scope
must be supplied by the document type (availability check).

### 7. Uniqueness is the real invariant — the boundary and how it is checked

**Boundary (settled): a number is unique within *(company, document type)*, across all that type's series.** The database
enforces it (`UNIQUE (company, doc type, number)` on the allocation log, written in the same transaction as the number, and the
document's own unique index). The framework does not require every scope dimension to appear in the template. It validates the
invariant itself on every save of a version:

- it **enumerates the numbers the configuration can produce** — for each series, a few periods (the current and next, and the
  previous year boundary), every combination of the dimension values that exist today (the document type's
  `knownDimensionValues()`, plus counters already in use) with two stand-in values where none exist, and a spread of counter
  values (1, 9, 10, 99, 100, … up to the width);
- two *different* counters — in one series or in different active series — producing **the same text** is a **certain collision**
  and the version is **rejected**, naming the two counters and the number
  (*"Lahore and Karachi both reach `PO-2026-00001`"*);
- two configurations that cannot be shown disjoint by sampling (for example two series whose templates begin with a variable
  and so may overlap in a way the sample missed) are a **warning**, never silent;
- numbers that stay distinguishable by any decorative variable (a branch in the text, a series literal `IMPORT`) pass.

Sampling is a guard, not a proof; the unique constraint is the proof, and a collision at issue time fails the issuing
transaction with a clear message rather than ever storing a duplicate. A prototype check on real series is part of phase 1.

### 8. Numbering date and the company time zone (settled)

Date variables and fiscal variables use the **issue/officialisation instant** (`issuedAt`), **never** an editable business date
(the order date stays separate), **rendered in the company's time zone**. The company time zone is a **general company-level
setting, not a numbering one** — and it already exists: **`companies.accounting_timezone`** (an IANA name, default `UTC`, set
through the Inventory settings API, and already used for the backdating floors). The framework **reuses it rather than adding a
second time-zone column**; the column keeps its name so the existing settings API and policies are untouched, and the numbering
screens call it "the company time zone". (A first draft of this ADR proposed a new nullable `companies.timezone`; it was dropped on
discovering `accounting_timezone`.) Consequences, settled:

- it is never null, so it defaults to `UTC` until a company sets its own; **the tester and the series editor therefore warn
  when a date-based format is about to use `UTC`** ("your company time zone is UTC, the default; an order issued after midnight
  local time may be numbered into the previous day or month — set the company time zone first"), rather than blocking, because
  UTC may genuinely be the right answer;
- series without date parts (today's PO/GR) are unaffected either way;
- `{FY}`/`{FP}` are looked up from the fiscal calendar for the **local date**; if none covers it, issuing is refused with a clear
  message;
- a company-profile screen to set the time zone is still wanted (today it lives in Inventory settings).

### 9. Continuity: two policies, two allocators (settled)

| Policy | Allocator | Behaviour |
|---|---|---|
| `GAPLESS` | **Locked counter**: the counter row is locked inside the document's own transaction. | A rollback returns the number; issues of one counter are serialised. |
| `ALLOW_GAPS` | **Sequence-backed counter**: each counter owns a PostgreSQL sequence; `nextval` is not transactional. | No waiting on another issue; a rollback or crash can leave a gap; numbers still rise and never repeat. |

The choice is `AllocationStrategy`, an interface with these two implementations, picked by the version's continuity — a third can
be added without touching callers. PostgreSQL's own documentation states its sequences cannot give gapless numbers, which is why
the strict policy uses the row lock. **Defaults:** each document type registers its default and whether it is locked —
purchase orders, goods receipts, stock documents and other operational documents default to **`ALLOW_GAPS`**; a **statutory
type registers `GAPLESS` and `continuityLocked() = true`**, and the engine refuses to change it. Switching a series' policy is a
new governed version with a reason, a warning (vendors advise against switching after use) and applies to numbers issued from
then on.

**Existing purchase orders and goods receipts (settled).** Their registered default is `ALLOW_GAPS`. But the numbers already
issued — in the live database too — were produced gaplessly, so **migrating each existing series preserves what it was: version 1
is `GAPLESS`, same prefix, same padding, same next number.** Nothing about them changes on deploy; the owner moves them to
`ALLOW_GAPS` with one governed change when and if they want. A fresh installation (no existing series) gets the registered
default. **Concurrency is tested** for both allocators with real separate connections: simultaneous allocations never repeat a
number; the locked allocator serialises and a rollback leaves no gap; the sequence allocator does not block and a rollback leaves
a gap; first-use races on a new counter resolve to one counter.

### 10. Series and routing (settled)

A document type has any number of series; exactly one is the **default**. The series for a document is chosen in this order:

1. **Manual selection** — the caller passes `requestedSeries`, accepted only if that series is active, belongs to the document type
   and is marked **selectable**; the *caller* has already checked the actor's permission to choose (the framework supplies a
   permission, `numbering.series.select`, for documents to use).
2. **Routing rules** — each series may have rules, evaluated by priority; a rule is a list of conditions **over the routing
   attributes the document type exposes** (`ORDER_TYPE = IMPORT`; `BRANCH in (LHR, KHI)`), all of which must hold. First match wins.
3. **The default series.**

Example (invoice): *Domestic* → `INV-DOM-{BRANCH}-{FY}-{SEQ:6}` for `SALE_TYPE = DOMESTIC`; *Export* →
`INV-EXP-{BRANCH}-{FY}-{SEQ:6}` for `SALE_TYPE = EXPORT`; a rule picks the series automatically. The rules table has defined
semantics from the start — (series, priority, ordered conditions of *attribute, EQ/IN, values*) — and there is **no vague
reserved column**. Rules and series are engine data; the first screen exposes neither until a second series exists.

### 11. Series versions — governed and immutable

A version is *(template, counter scope, continuity)* with an effective-from instant, a reason and an actor; one is active per
series. Changing any of the three adds a version and supersedes the previous one; old versions are never edited, and **every
allocation records the version that produced it**. Example — *Default PO*: v1 `PO-{YYYY}-{SEQ:5}`, then v2
`PO-{BRANCH}-{YYYY}-{SEQ:5}`: v1's issued numbers stay tied to v1; v2 starts its own counters if its scope differs. The
same governed pattern as [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md).

### 12. The allocation log freezes everything

At allocation the engine writes, in the document's transaction: the **final number** (also stored as plain text on the
document), the **series** and **version**, the **resolved value of every variable with the reference it came from**, the
**issue instant and the local date used**, the **counter scope and key**, and the **counter value**. A number is never
recomputed; a later change to a supplier, customer, branch or warehouse cannot alter an issued number or the record of why it reads as
it does. Document types may offer only business-derived variables whose source the document **freezes at that moment**, and each
supply point carries a test that it cannot be edited afterwards.

### 13. Company constants (first release)

A name (`REGION`, upper-case letters, digits and `_`, ≤ 20 characters) and a value (≤ 20 characters in the literal character
set): `{REGION}=NORTH`, `{BRAND}=ABC`, `{DIVISION}=INDUSTRIAL`. A governed history (reason, actor, from-now-on only); a constant in
use cannot be removed, only retired; each allocation freezes the value it used.

### 14. One central management area, with progressive complexity

**Settings → Document Numbering** is the one place. It lists **every registered document type** with its current default series
and a live example (`Purchase order → PO-2026-00042`, `Goods receipt → GR-2026-00128`, …). Modules may link into it filtered to
their documents from their own settings, but have no numbering screens or logic of their own. Permissions: reading and the
tester need `numbering.view`; changing a type's series needs the permission **that type registers**
(`purchasing.numbering.manage` for purchasing documents); raising a counter needs `numbering.counter.raise`; constants
`numbering.constant.manage`; choosing a series on a document `numbering.series.select`.

The default view is deliberately small: **template, reset (*never / yearly / monthly* in plain words, mapped to the scope's
period), continuity, preview.** Series, routing rules, dimensions, fiscal periods, constants, counter management and detailed
history appear only when needed — under *Advanced*, or once a second series exists. One implementation underneath; no "simple
mode" code path.

### 15. The tester (never consumes a number)

For the template and scope being edited, with a simulated issue instant and editable sample values, it shows: the **rendered
example** with each part named; **which instant is used** and the **company time zone**; the **series**; the **counter scope**
and **when it resets**; the **continuity policy**; the **next few numbers** read from the live counters and the **first number of the
next period**; **collisions** (section 7); **format and length** problems; and **the effect of the proposed change** ("applies to
numbers issued from now; issued numbers do not change; a new counter starts for each branch"). It reads sequence-backed counters
without `nextval`.

### 16. Counter management

A manager with `numbering.counter.raise` may **raise** a counter, with a **reason**; it is recorded in a counter-adjustment
history (who, when, from, to, why). **Lowering is refused** — by the action and by a database check.

### 17. Data model

All tables are company-scoped (composite uniques and foreign keys carry `company_id`, as the architecture tests require):

```
numbering_series            id, company_id, doc_type, code, name, is_default, is_selectable, status (ACTIVE|RETIRED)
                            UNIQUE(company,id); UNIQUE(company,doc_type,code); one default per (company,doc_type)
numbering_series_versions   id, company_id, series_id, template, scope_period, scope_dimensions (text[]), continuity,
                            effective_from, status (ACTIVE|SUPERSEDED), reason, changed_by
                            one ACTIVE per series; rows are append-only
numbering_series_rules      id, company_id, series_id, priority, conditions (jsonb: [{attribute, operator, values}]), status
numbering_counters          id, company_id, series_id, counter_key, allocator (LOCKED|SEQUENCE), next_number (LOCKED) / sequence_name (SEQUENCE)
                            UNIQUE(series_id, counter_key)
numbering_counter_adjustments  id, company_id, counter_id, from_value, to_value, reason, changed_by, created_at
numbering_allocations       id, company_id, doc_type, series_id, version_id, document_id, number, counter_key, counter_value,
                            issued_at, local_date, variables (jsonb), allocated_by, allocated_at
                            UNIQUE(company,doc_type,number)
numbering_constants         id, company_id, code, value, status, reason, changed_by, effective_from
(the company time zone is the existing companies.accounting_timezone — no new column)
```

### 18. Migration of purchase orders and goods receipts (no visible change)

Each existing `sequences` row becomes a series `DEFAULT` for its type with version 1 `prefix + {SEQ:padding}`, scope
`COMPANY` (period `NONE`), continuity `GAPLESS`, and a `LOCKED` counter whose next number is carried over — so the next number
issued is exactly the one that would have been. Types with no row yet get the registered default on first use. `NumberingScheme`
(the interface and `DefaultNumberingScheme`) stays as a thin facade onto the engine for any caller that has no context, so the
plugin extension point is kept. Purchase orders and goods receipts call `NumberingService::allocate()` with their context at
issue/post; the number text, the unique index and the API are unchanged.

### 19. Phasing

*Status 2026-10-02: steps 1 and 2 are implemented and tested — the settings API and Settings → Document Numbering (list, editor with tester, series and routing, counters, history, constants). Step 1 is implemented and tested (engine; PO and GR migrated with no visible change; both allocators proven with two real database sessions). The sequence-backed counter creates one PostgreSQL sequence per counter, named `numbering_seq_<random>`.*

1. **Engine (this ADR's implementation)**: the module, registries, parser/validator, scope and counter-key logic, both
   allocators, the allocation log, versions, rules, constants, counter raising, the uniqueness check, the preview service;
   `companies.timezone`; PO and GR migrated and registered.
2. **Settings → Document Numbering**: the API and the screens (list, default-series editor with tester, advanced areas).
3. **As documents arrive**: each registers its type and context.
4. **Later**: a company profile screen for the time zone; `{COMPANY}` with a company code; purchase-order `{WAREHOUSE}` after
   ship-to; numbers-follow-dates validation for gapless series; field-backed variables.

## Consequences

- **Positive:** one framework for every document; a new document is a registration plus a context; each series chooses the
  guarantee it needs; safe by construction (parsed not evaluated, explicit scope, uniqueness validated and enforced, governed,
  frozen, auditable).
- **Positive:** nothing visible changes for purchase orders and goods receipts, and nothing changes at all for a company that
  never opens the screen.
- **Negative:** real infrastructure (a module, eight tables, two allocators, a parser, a checker, screens); worth it because every
  future document uses it. The sequence-backed allocator creates one PostgreSQL sequence per counter (DDL at first use), which
  needs schema privileges the application role already has.
- **Negative:** two policies mean documents in one company can behave differently; the screens always say which.
- **Requires:** the company time zone set correctly for date-based formats (`accounting_timezone`, default `UTC`); a fiscal calendar covering any
  date a fiscal variable is used for; later, a company code (`{COMPANY}`) and a ship-to on purchase orders (`{WAREHOUSE}`).

## Open questions

1. **Block-reserved allocation** for very high-volume series is not needed by `nextval` and is not built; revisit if a series ever
   needs cross-process caching.
2. **The company time-zone screen** — a company profile page does not exist yet; today the value is set in Inventory settings.
   Date-based formats warn while it is still the `UTC` default.
3. **Routing attributes** for the first real second series (domestic/import, branch) are defined when a document type needs them.
4. **Statutory numbering for invoices** (sequential-series requirements in some tax regimes) was not researched and must be
   verified against current official guidance when invoice numbering is designed; the locked `GAPLESS` policy is ready for it.

## Alternatives considered

**Derive the counter scope from the template (my first draft)** — rejected on the owner's point and the evidence: every vendor
declares scope explicitly, and a derived scope lets an edit silently start a new counter.

**Gapless for everything** — rejected: the vendors with the most mature guidance say not to, and PostgreSQL cannot provide it
without a row lock inside the document's transaction.

**Gaps allowed for everything** — rejected: statutory documents need the strict policy.

**Numbering built inside Purchasing** — rejected by the owner: every module would repeat it.

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
