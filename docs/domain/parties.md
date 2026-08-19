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
  custom_fields     json
  status            ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

party_contacts
  id, party_id
  name, email, phone
  title             NULLABLE   -- job title at the party
  is_primary        bool

party_sites
  id, party_id
  code, name
  address_line1, address_line2
  city, state, postal_code, country_id
  status            ACTIVE | ARCHIVED

  unique(['party_id', 'code'])

customer_accounts
  id, company_id, party_id
  code                     -- role-specific; may differ from parties.code
  credit_limit             decimal(19,4)   NULLABLE   -- null = no limit enforced
  payment_terms_days
  tax_code_id              NULLABLE → tax codes (tax.md)
  income_account_id        → chart_of_accounts
  default_currency_id      NULLABLE → currencies
  custom_fields            json
  status                   ACTIVE | ARCHIVED

  unique(['company_id', 'party_id'])   -- one customer account per party per company
  unique(['company_id', 'code'])

customer_sites
  id, customer_account_id, party_site_id
  role              BILL_TO | SHIP_TO
  is_default        bool

  unique(['customer_account_id', 'party_site_id', 'role'])

supplier_accounts
  id, company_id, party_id
  code
  payment_terms_days
  lead_time_days           NULLABLE
  payable_account_id       → chart_of_accounts
  default_currency_id      NULLABLE → currencies
  custom_fields            json
  status                   ACTIVE | ARCHIVED

  unique(['company_id', 'party_id'])
  unique(['company_id', 'code'])

supplier_sites
  id, supplier_account_id, party_site_id
  role              ORDER_FROM | SHIP_FROM
  is_default        bool

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

### Shared sites

`party_sites` represents a real physical location once. The same site can be referenced by both
a `customer_sites` row and a `supplier_sites` row — e.g. goods are both delivered to and shipped
from the same factory address. Role-specific meaning (is this a bill-to, a ship-from) lives on
the join row, not on the site itself.

### Codes

`parties.code` and each role account's `code` are deliberately separate. A business may already
have its own external customer numbering scheme that doesn't match how we'd code the same entity
as a supplier — forcing them to be one value would mean picking a side.

### Archiving, not deleting

Same as [items.md](items.md): soft status throughout, never hard-deleted, since documents from
Phase 4 onward will reference these and must remain readable.

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

- [items.md](items.md) — the same shared-table-with-discriminator precedent, and
  `default_supplier_id` references a `supplier_accounts` row
- [multi-currency.md](multi-currency.md) — `default_currency_id`
- [tax.md](tax.md) — `tax_code_id`
- [general-ledger.md](general-ledger.md) — `income_account_id` / `payable_account_id`
- [ADR 0030](../decisions/0030-unified-party-model.md) — the decision this doc implements
