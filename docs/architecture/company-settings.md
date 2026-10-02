# Company settings

*Settings → Company*, `GET/PATCH /api/v1/company`, `GET /api/v1/company-changes`. Why and the rules: [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md).

## What is here

| Section | Fields | Changed with |
|---|---|---|
| General | name, legal name, short **code**\* | `company.profile.manage` |
| Localization and finance | **time zone**\*, **base currency**\*, **fiscal year starts in**\* | `company.settings.manage` |
| Contact and address | country, address lines, city, state, postal code, email, phone, website | `company.profile.manage` |

\* *governed*: a change needs a reason and is recorded in the history. Reading the history needs `company.history.view`.
Everyone signed in may read the company. Module policies (inventory, purchasing, approvals, numbering) are **not** here.

## Reading it from another module

```php
app(\App\Support\CurrentCompany::class)->timezone();   // IANA name, 'UTC' until set
app(\App\Support\CurrentCompany::class)->code();       // ?string, the {COMPANY} numbering variable
app(\App\Support\CurrentCompany::class)->name();
```

It reads the current row each call and caches nothing. Do not read `companies.timezone` directly.

## Locks

- **Base currency** — changeable only while no financial record exists. Each module that stores money registers a probe in its
  service provider: `$activity->register(new ExistsProbe('purchase orders', fn () => PurchaseOrder::query()->exists()))`
  (`FinancialActivity` is a singleton; today: purchase orders, goods receipts, stock movements, inventory cutovers). **Register a
  probe whenever you add a table that holds money amounts.**
- **Fiscal-year start month** — changeable until an accounting period is closed.

Both live in the `Company` model's `updating` hook, so the API, imports and console commands are all bound; `CompanyLocks`
returns the same sentence the screen shows beside the read-only field (`locked_reason` in the API).

## Adding a company-wide field

1. A migration adding a real, typed, constrained column to `companies`.
2. A `CompanyField` in `CompanyFields::all()` — section, label, widget `type`, validation rules, permission, governed or not.
3. If it can be locked, a rule in `CompanyLocks` and the model hook.
4. A consumer through `CurrentCompany` (a setting nobody reads does not belong here).

The request, the API's form description, the audit and the screen follow from the registry. If another module's own policy is
the real owner, put it in that module instead.

## History

`company_changes` is append-only (model guards and a database trigger), one row per changed section with old/new values, who,
when and the reason. Rows written before the rename keep their original key where they were Inventory audit rows
(`accounting_timezone`).

## Not built yet

Branding/logo and company print defaults (need a file-storage and branding architecture), tax and registration numbers,
a company switcher.
