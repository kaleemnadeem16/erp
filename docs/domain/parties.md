[← Domain index](README.md)

# Parties

Everyone the company trades with — customers and suppliers — modelled as one shared identity
plus role-specific accounts, rather than two unrelated tables. A real-world business is often
both: a distributor might supply raw pigment and also buy finished product back. Splitting that
into disconnected `customers`/`suppliers` rows loses the fact that it's one entity
([ADR 0030](../decisions/0030-unified-party-model.md)).

## Where it sits

Master data. Referenced by purchasing (Phase 2), sales pipeline and quotations (Phase 4), and
receivables (Phase 6). Built in [Phase 1](../phases/phase-1-master-data.md).

```
party ──┬── customer_account ──── customer_sites ──┐
        │                                          ├── party_sites (shared)
        └── supplier_account ──── supplier_sites ──┘
```

## Schema

```
parties
  id, company_id
  code              -- the ERP-wide identifier for this entity
  name              -- organisation name
  registration_no   NULLABLE
  tax_id            NULLABLE   -- e.g. VAT/GST/NTN number
  website           NULLABLE
  custom_fields     jsonb NULLABLE DEFAULT '{}'
  status            ACTIVE | ARCHIVED DEFAULT ACTIVE
  deleted_at        NULLABLE

  unique(['company_id', 'code'])

party_contacts
  id, party_id
  name
  email, phone       NULLABLE
  title             NULLABLE   -- job title at the party
  is_primary        bool DEFAULT false
  deleted_at        NULLABLE

party_sites
  id, party_id
  code, name
  address_line1, address_line2   NULLABLE
  city, state, postal_code       NULLABLE
  country_code      char(2), NULLABLE   -- ISO 3166-1 alpha-2, uppercase
  status            ACTIVE | ARCHIVED DEFAULT ACTIVE
  deleted_at        NULLABLE

  unique(['party_id', 'code'])

customer_accounts
  id, company_id, party_id
  code                     -- role-specific; may differ from parties.code
  credit_limit             decimal(19,4)   NULLABLE   -- null = no limit enforced
  payment_terms_days
  tax_code_id              NULLABLE → tax_codes (tax.md, not built yet; the API rejects non-null
                                      values until that table and its company-scoped FK exist)
  receivable_account_id    → chart_of_accounts   -- the customer's control account (an ASSET);
                                                     revenue itself comes from item/category
                                                     income_account_id, not from here
  default_currency_id      NULLABLE → currencies
  custom_fields            jsonb NULLABLE DEFAULT '{}'
  status                   ACTIVE | ARCHIVED DEFAULT ACTIVE
  deleted_at               NULLABLE

  unique(['company_id', 'party_id'])   -- one customer account per party per company
  unique(['company_id', 'code'])

customer_sites
  id, customer_account_id, party_site_id
  role              BILL_TO | SHIP_TO
  is_default        bool DEFAULT false
  deleted_at        NULLABLE

  unique(['customer_account_id', 'party_site_id', 'role'])

supplier_accounts
  id, company_id, party_id
  code
  payment_terms_days
  lead_time_days           NULLABLE   -- general default; Phase 2's item_suppliers.lead_time_days
                                       -- overrides this per item, see ADR 0033
  payable_account_id       → chart_of_accounts
  default_currency_id      NULLABLE → currencies
  custom_fields            jsonb NULLABLE DEFAULT '{}'
  status                   ACTIVE | ARCHIVED DEFAULT ACTIVE
  deleted_at               NULLABLE

  unique(['company_id', 'party_id'])
  unique(['company_id', 'code'])

supplier_sites
  id, supplier_account_id, party_site_id
  role              ORDER_FROM | SHIP_FROM
  is_default        bool DEFAULT false
  deleted_at        NULLABLE

  unique(['supplier_account_id', 'party_site_id', 'role'])
```

## Behaviour

### One identity, independent roles

A party can hold a `customer_account`, a `supplier_account`, both, or neither while it's being
set up. The two accounts are fully independent — a `credit_limit` on the customer side has no
bearing on `lead_time_days` on the supplier side, and either can be archived without touching the
other or the shared `parties` row.

### Where a field belongs

The rule from [ADR 0030](../decisions/0030-unified-party-model.md): if a value differs
depending on whether we're buying from or selling to this party, it belongs on the role account,
not on `parties`. `parties` only carries what's true of the entity regardless of relationship —
name, registration, tax id, contacts. This is the same discipline `items.md` applies to GL
mapping, applied here to keep this table from regrowing into the single wide table ADR 0030
rejected.

### Receivable and payable are control accounts, not income

`customer_accounts.receivable_account_id` and `supplier_accounts.payable_account_id` are the
customer's/supplier's **balance-owed** account (ASSET / LIABILITY respectively) — what a sales
invoice debits, what a purchase invoice credits, per [general-ledger.md](general-ledger.md)'s
"What posts when" table. A customer doesn't have "income"; the item/category being sold does
(`income_account_id`, [items.md](items.md#gl-mapping)). Naming this field `income_account_id` on
`customer_accounts` (as an earlier draft of this schema did) conflated the two — fixed before
either table was built.

Neither column is type-checked against its expected type (`receivable_account_id` → `ASSET`,
`payable_account_id` → `LIABILITY`) — same deliberate Phase-6 deferral as
[items.md](items.md#gl-mapping)'s account columns, per
[general-ledger.md](general-ledger.md#account-type-and-normal-balance). Cross-company
misassignment, however, is guarded now: both columns reject an id that doesn't resolve to a
`chart_of_accounts` row in the current company (`GuardsRelatedCompanyScope`).

### Shared sites

`party_sites` represents a real physical location once. The same site can be referenced by both
a `customer_sites` row and a `supplier_sites` row — e.g. goods are both delivered to and shipped
from the same factory address. Role-specific meaning (is this a bill-to, a ship-from) lives on
the join row, not on the site itself. Each join must reference a site owned by the same `party`
as its customer or supplier account; both create and update reject a site from another party or
company. This preserves shared sites across the two roles without allowing one trading partner's
role configuration to attach another trading partner's address.

The supplier-facing half of this is exposed at `/api/v1/supplier-accounts/{id}/sites`
(index/store) and `/api/v1/supplier-sites/{id}` (update/destroy) — `SupplierSiteController`,
`App\Modules\Parties\Actions\{Create,Update,Delete}SupplierSite`. A create request only asks for
an address and a role; the `party_sites.code` a party-level uniqueness needs is derived from the
name and de-duplicated server-side, not asked of the user. Deleting a supplier site removes only
its role join, never the underlying `party_sites` row, since another role (a customer join) may
still reference it. There is no equivalent `customer-sites` endpoint yet, and no UI for `party_sites`
independent of a role — see [purchasing.md](purchasing.md#user-interface) for where the supplier
side is used.

### Country as a code, not a reference

`party_sites.country_code` is a plain ISO 3166-1 alpha-2 string (normalised to uppercase in the
application, same discipline as [items.md](items.md#landmines) uses for item codes), not a
foreign key — no `countries` table exists in this codebase, and nothing yet needs more than a
code (tax rules, address formatting, subdivisions). `multi-tenancy.md` lists `countries` as an
example of a genuinely global table *if* one is ever built; until then, validate the code at the
application layer. Migrating to a real FK later is a backfill-by-code plus a column swap, not a
redesign.

### Codes

`parties.code` and each role account's `code` are deliberately separate. A business may already
have its own external customer numbering scheme that doesn't match how we'd code the same entity
as a supplier — forcing them to be one value would mean picking a side.

### Archiving, not deleting

Same as [items.md](items.md): the party aggregate uses Laravel soft deletion throughout, so API
DELETE operations set `deleted_at` rather than physically removing rows. The `status` column is
a separate business lifecycle flag; it is not the mechanism used by the current archive Actions.
Customer and supplier roles have independent lifecycles, but the shared party is their aggregate
root: archive both role accounts before archiving the party. `ArchiveParty` locks the party row
and rejects the operation while either non-deleted role exists. This prevents an active role
remaining queryable while its required `party` relation has disappeared through the soft-delete
scope.

### Tenant boundaries

Only `parties`, `customer_accounts` and `supplier_accounts` carry `company_id`. Contacts, shared
sites and role-site joins inherit their scope through their owning parent relation. Parent ids
are re-resolved through company-scoped models on create and update; customer/supplier site joins
additionally require the role account and shared site to belong to the same party. Composite
PostgreSQL foreign keys protect the company-bearing references against raw writes as well.

## Landmines

**Putting role-specific fields on `parties`.** Nothing stops adding `credit_limit` directly to
`parties` "since most customers have one" — but that's exactly the shape ADR 0030 rejected. If a
column only makes sense for one role, it goes on that role's account table.

**Assuming a party is only ever a customer or only ever a supplier.** The unified model exists
specifically because that assumption breaks in practice. Never gate UI, validation, or a query's
`WHERE` clause on party role being exclusive.

**Duplicate `parties` rows for the same real business.** Nothing in the schema stops two rows
representing one company — deduplication is a data-entry/search-before-create problem, not
something a unique constraint can catch, since names legitimately vary (trading name vs.
registered name). Same caveat [items.md](items.md#landmines) gives for item codes.

**Sharing a party across a company's own multiple legal entities.** Not supported today —
`parties` is `company_id`-scoped like everything else, so the same real-world trading partner
needs a separate row per legal entity if that ever exists. See
[Q10](../OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities) and
the alternatives section of [ADR 0030](../decisions/0030-unified-party-model.md#alternatives-considered).

## Related

- [items.md](items.md) — the same shared-table-with-discriminator precedent; Purchasing's
  `item_suppliers` catalogue references `supplier_accounts`
- [multi-currency.md](multi-currency.md) — `default_currency_id`
- [tax.md](tax.md) — `tax_code_id`
- [general-ledger.md](general-ledger.md) — `receivable_account_id` / `payable_account_id`
- [ADR 0030](../decisions/0030-unified-party-model.md) — the decision this doc implements
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md) — Phase 2's implemented
  `item_suppliers`, overriding `lead_time_days` per item
