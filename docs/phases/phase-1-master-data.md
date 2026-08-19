[← Roadmap](README.md)

# Phase 1 — Master data

- **Status:** in progress — design decided 2026-08-19 (unified party model, hierarchical
  categories, manual FX entry); build not started
- **Depends on:** [Phase 0 — Foundation](phase-0-foundation.md) (company scoping, custom fields,
  UoM, sequences, currencies schema)
- **Blocked by:** nothing

## Goal

The master data every later phase reads from: items and categories, trading partners in both
roles, real currency rates, and a chart of accounts. At the end of this phase a company can be
fully set up — real items, real customers and suppliers, a real chart of accounts — with no
transactions yet. This is backend-only; the first substantial frontend work is
[Phase 4](README.md#phase-4--sales-pipeline).

## Scope

### In

- `items` + `categories` (hierarchical, GL-default inheritance) → [items.md](../domain/items.md)
- `parties`, `party_sites`, `customer_accounts`, `supplier_accounts`, `customer_sites`,
  `supplier_sites` → [parties.md](../domain/parties.md), [ADR 0030](../decisions/0030-unified-party-model.md)
- Exchange rate population — manual entry only → [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)
- `chart_of_accounts` + `accounting_periods` (schema only — posting logic is Phase 6) →
  [general-ledger.md](../domain/general-ledger.md)
- Wiring `HasCustomFields` onto `items` and `parties` — the first real use since Phase 0 built
  the mechanism unused
- API endpoints for all of the above, per [api-conventions.md](../architecture/api-conventions.md)
- Orval regeneration, `codegen-check` green in CI

### Out

- Frontend CRUD screens — deferred to Phase 4, per this phase's own scope decision
- GL posting logic — accounts exist and are referenced, but nothing posts until
  [Phase 6](README.md#phase-6--finance)
- Purchase orders, stock moves → [Phase 2](README.md#phase-2--purchasing--stock)
- FX-rate API integration — deferred per [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md);
  the schema already supports adding it later without a migration
- `ORGANIZATION | INDIVIDUAL` party discriminator — deferred per
  [ADR 0030](../decisions/0030-unified-party-model.md), no current requirement
- Sharing a party across a company's own multiple legal entities — open, see
  [Q10](../OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities), not
  solved here

## Tasks

- [ ] `categories` table + hierarchical GL-default resolution (`items.md`)
- [ ] `items` table — migration, model, `BelongsToCompany`, `HasCustomFields` wired in
- [x] ~~`parties`, `party_contacts`, `party_sites` — migrations, models, `BelongsToCompany`,
      `HasCustomFields` on `parties`~~ — done 2026-08-19. Also required building
      `App\Support\Concerns\BelongsToCompanyThroughParent` — no mechanism existed for scoping a
      table with no `company_id` of its own, even though every domain doc already designed child
      tables that way (`invoice_lines`, `journal_lines`, `job_lines`, `assembly_components`,
      `locations`). Proven with 4 tests (read-side, write-side, and a 2-hop chain), plus
      model-specific coverage for `Party`/`PartyContact`/`PartySite` themselves. See
      [multi-tenancy.md](../architecture/multi-tenancy.md#child-tables-scope-through-their-parent)
- [ ] `customer_accounts`, `customer_sites` — migrations, models, `BelongsToCompany`
- [ ] `supplier_accounts`, `supplier_sites` — migrations, models, `BelongsToCompany`
- [ ] `chart_of_accounts`, `accounting_periods` — migrations, models
- [ ] Manual exchange-rate entry endpoint(s) for `exchange_rates`
- [ ] Architecture test: `categories.parent_id` has no cycle
- [ ] API endpoints (`/api/v1/items`, `/categories`, `/parties`, `/customer-accounts`,
      `/supplier-accounts`, `/exchange-rates`, `/chart-of-accounts`) per api-conventions.md
- [ ] Orval regeneration verified, `codegen-check` green on real CI
- [ ] Pest coverage: company scoping on every new table, composite uniqueness, the GL-mapping
      inheritance walk, the category cycle guard

Tick these in the same commit as the work.

## Acceptance criteria

- [ ] An item's resolved GL accounts correctly walk up the category tree when the item itself
      has none set
- [ ] A party can hold a `customer_account`, a `supplier_account`, or both simultaneously, and
      each account's fields are independent
- [ ] A `party_site` can be referenced by both a `customer_sites` row and a `supplier_sites` row
- [ ] Cross-company scoping holds for every new table (architecture test extended beyond items)
- [ ] A custom field defined for `items`/`parties` appears on create/read with no code change —
      the first real exercise of `HasCustomFields`
- [ ] `docker compose up` + migrate gives all Phase 1 tables with no errors; full test suite
      green on Postgres
- [ ] Manually entering an exchange rate through the API is reflected correctly — no rate is
      fabricated or silently defaulted

## Docs to update on completion

- [ ] [STATUS.md](../STATUS.md) — mark Phase 1 done, move Next to Phase 2
- [ ] [items.md](../domain/items.md), [parties.md](../domain/parties.md) — reconcile any schema
      drift found during implementation
- [ ] [general-ledger.md](../domain/general-ledger.md) — confirm `chart_of_accounts` matches what
      was actually built
- [ ] Expand [phase-2](README.md#phase-2--purchasing--stock) into its own doc

## Related

- [domain/items.md](../domain/items.md) · [domain/parties.md](../domain/parties.md) ·
  [domain/general-ledger.md](../domain/general-ledger.md) ·
  [domain/multi-currency.md](../domain/multi-currency.md)
- [ADR 0030](../decisions/0030-unified-party-model.md) ·
  [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)
- [architecture/api-conventions.md](../architecture/api-conventions.md) ·
  [architecture/extensibility.md](../architecture/extensibility.md) — `HasCustomFields`
