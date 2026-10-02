[← Decisions](README.md)

# 0081 — A central Company screen: profile, company-wide settings, a stable code, and lifecycle locks

- **Status:** accepted (2026-10-02, after the owner approved the audit and the points below).
- **Affects:** `companies` (new columns, `accounting_timezone` renamed to `timezone`), a new `Company` module (API, field
  registry, history), Inventory settings (loses the time zone), the Numbering module (`{COMPANY}`, `CompanyClock`),
  **Settings → Company**, Printing (company name), permissions `company.*`. Builds on [ADR 0079](0079-document-numbering-system-templates-over-a-variable-registry.md)
  and [ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md).

## Context

Company facts were scattered. The name lived in two places that could disagree (the `companies` row and a browser-only
print setting); the time zone existed as `companies.accounting_timezone` but could only be changed from *Inventory* settings,
although numbering, backdating and accounting days all depend on it; base currency and the fiscal-year start month had no
screen at all; and nothing identified the company in a document number except an editable name.

## Decision

1. **One place, one source.** `GET /api/v1/company` and `PATCH /api/v1/company` serve the profile (name, legal name, short code,
   company registration number, tax number, sales-tax/VAT/GST number, country, address, email, phone, website) and the company-wide settings (time zone, base currency, fiscal-year start month).
   Other modules read them through `CurrentCompany`, never by reaching into the table.
2. **Ownership rule.** A setting belongs on the Company screen only if more than one module reads it and it is not another
   module's domain policy. Over-receipt, negative stock, backdating, approval and numbering policies stay with their modules.
3. **Typed fields, not key/value.** Every company field is a real column. `CompanyFields` is an explicit registry (section, label,
   widget type, validation, the permission that may change it, whether a change is *governed*); the request validates from it, the
   API describes the form from it, and the screen renders from that description. Adding a setting is a column + a definition + a
   consumer — there is no generic bag of settings.
4. **Time zone is central.** `accounting_timezone` is renamed `timezone` and removed from Inventory settings; Inventory's
   backdating evaluator and numbering read it through the company. Old Inventory audit rows keep their original key.
5. **Stable code.** `companies.code` (2–10 uppercase letters/digits, unique across companies) feeds the numbering variable
   `{COMPANY}`. It is never derived from the editable name, and a number already issued keeps the code it was issued with
   (the allocation log freezes it). A format that uses `{COMPANY}` before a code exists fails with a plain message.
6. **Lifecycle locks, enforced structurally.** The *base currency* can be changed only while the company has no financial record
   (anything registered with `FinancialActivity` — today purchase orders, goods receipts, stock movements, inventory cutovers; each
   module registers its own probe, so new money-carrying records join automatically). The *fiscal-year start month* can be
   changed until the first accounting period is closed. Both rules live in the `Company` model's `updating` hook (so API, import
   and console callers are all bound) and in `CompanyLocks`, which also supplies the explanation the screen shows beside the
   read-only field — the guard and the screen cannot disagree.
7. **Governed history.** Each change writes an immutable `company_changes` row per section (old/new values, who, when, reason).
   Changing a governed field (code, time zone, base currency, fiscal month) requires a reason. Reading the history needs
   `company.history.view`.
8. **Permissions.** `company.profile.manage` (identity, contact), `company.settings.manage` (time zone, currency, fiscal month),
   `company.history.view`. Any signed-in user may read the company; each field enforces its own permission.
9. **Printing.** Printed documents take the company name from the profile; the browser-only name stays only as a fallback while
   the profile loads.

## Deferred

Branding/logo and per-company print defaults — **deferred by decision** until a file-storage and branding architecture exists.
Not built, not decided: a company switcher (the system is single-company per login today; every query is already company-scoped).

## Consequences

The numbering framework no longer falls back to UTC for companies that set a zone, and a dated number cannot be wrong by a day
because someone found the setting under the wrong module. Locking base currency by *probe registration* means a module that
starts carrying money must register a probe — recorded as a rule in [invariants.md](../architecture/invariants.md) §10.
