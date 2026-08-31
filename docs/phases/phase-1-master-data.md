[← Roadmap](README.md)

# Phase 1 — Master data

- **Status:** complete — schema, models, full CRUD API, acceptance criteria and domain-document
  reconciliation are done. Expanding the Phase 2 plan is the next phase-start task, not remaining
  Phase 1 implementation work
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

- [x] ~~`categories` table + hierarchical GL-default resolution (`items.md`)~~ — done
      2026-08-20. `App\Modules\Inventory\Models\Category` (`BelongsToCompany`, self-referencing
      `parent_id`), `resolveIncomeAccountId()`/`resolveExpenseAccountId()`/
      `resolveInventoryAccountId()` walk `parent()` to the nearest ancestor with that column
      set, returning null rather than a fallback default when nothing in the chain sets it, per
      the doc. The account columns began as forward references and now have company-aware
      foreign keys to `chart_of_accounts`. Also closed the cycle-guard
      landmine flagged in items.md: a `saving` hook rejects both a reparent that would create a
      cycle and a `parent_id` that doesn't resolve to a category in the current company (the
      same class of gap `BelongsToCompanyThroughParent` closes for child tables, but `Category`
      carries its own `company_id` so a lighter existence+scope check was enough — no new trait
      needed). First feature in the previously-empty `Inventory` module, so it got its
      `README.md` scaffold too. Full toolchain green (Pest 101/101, Larastan, Pint)
- [x] ~~`items` table — migration, model, `BelongsToCompany`, `HasCustomFields` wired in~~ —
      done 2026-08-20. `App\Modules\Inventory\Models\Item`, following `Category`/`Party`
      conventions: `BelongsToCompany`, `HasCustomFields`, `SoftDeletes`, `code` normalised to
      lowercase on write (`items.md` "Codes and case sensitivity"). `type`/`tracking` got their
      own enums (`App\Modules\Inventory\Support\ItemType`/`ItemTracking`) rather than living in
      `App\Support`, since they're Inventory-only vocabulary, not cross-cutting like
      `Status`/`RateType`/`UomDimension`. `default_supplier_id` and the three account columns
      began as forward references; all now have company-aware foreign keys because their target
      tables exist. `resolveIncomeAccountId()`/etc.
      prefer the item's own column and fall through to `category`'s resolution otherwise — the
      acceptance criterion below is proven in `ItemTest`. Full toolchain green (Pest 112/112,
      Larastan, Pint). **Revised same day:** `valuation_method` initially shipped as a plain
      `'AVCO'`-default string, on the reasoning that ADR 0007 named no second method — superseded
      hours later by [ADR 0032](../decisions/0032-per-item-valuation-method.md) once a real
      second business shape (formulation *and* resale within one company) showed that reasoning
      didn't hold. Now `App\Modules\Inventory\Support\ValuationMethod` (`AVCO`/`FIFO`/`SPECIFIC`),
      defaulted per `categories.default_valuation_method` (same walk-up-parent resolution as the
      GL columns) and overridable per item, resolved once at creation and stored — not
      re-resolved on read, unlike GL accounts, since a later category change must not silently
      move an already-created item's cost basis. Locking the method after an item's first
      inventory transaction is documented but deliberately not implemented — no `stock_moves`
      table exists yet to check against; that's Phase 2. Full toolchain re-verified green
      (Pest 120/120, Larastan, Pint)
- [x] ~~`parties`, `party_contacts`, `party_sites` — migrations, models, `BelongsToCompany`,
      `HasCustomFields` on `parties`~~ — done 2026-08-19. Also required building
      `App\Support\Concerns\BelongsToCompanyThroughParent` — no mechanism existed for scoping a
      table with no `company_id` of its own, even though every domain doc already designed child
      tables that way (`invoice_lines`, `journal_lines`, `job_lines`, `assembly_components`,
      `locations`). Proven with 4 tests (read-side, write-side, and a 2-hop chain), plus
      model-specific coverage for `Party`/`PartyContact`/`PartySite` themselves. See
      [multi-tenancy.md](../architecture/multi-tenancy.md#child-tables-scope-through-their-parent)
- [x] ~~`chart_of_accounts`, `accounting_periods` — migrations, models~~ — done 2026-08-20, with
      `fiscal_years` added ahead of a plan review (created explicitly, not auto-generated —
      same manual-entry philosophy as exchange rates). `App\Modules\Accounting` is a new module.
      `ChartOfAccount`'s hierarchy guard is `Category`'s, extracted into
      `App\Support\Concerns\GuardsCompanyScopedHierarchy` rather than duplicated — identical
      rules (no self-parent, no cycles, same company), reused on both create and update.
      `fiscal_years`/`accounting_periods` overlap is a Postgres exclusion constraint
      (`EXCLUDE USING gist`, needing `btree_gist` — installed here, the first migration that
      needed it); a period falling entirely inside its fiscal year's dates is an
      application-level guard (`AccountingPeriod::booted()`) since that cross-table containment
      can't be a same-table `CHECK`. Also closed a real gap surfaced during review: ADR 0024
      (accepted 2026-08-10) required `companies.base_currency_id`/`fiscal_year_start_month`,
      which were never actually added — added now, `base_currency_id` nullable (no currency is
      seeded by default) and `fiscal_year_start_month` defaulting to July per the ADR. Also fixed
      a naming bug caught before `customer_accounts` was built: `income_account_id` renamed to
      `receivable_account_id` (a control account, not income — see
      [parties.md](../domain/parties.md)). `categories`/`items`' `*_account_id` columns got their
      real FK constraints in follow-up migrations, now that `chart_of_accounts` exists. Full
      toolchain green (Pest 172/172, Larastan, Pint)
- [x] ~~`customer_accounts`, `customer_sites` — migrations, models, `BelongsToCompany`~~ — done
      2026-08-20. `party_id` is guarded against pointing at another company's `Party` via a new
      `App\Support\Concerns\GuardsRelatedCompanyScope` trait (re-resolves through the related
      model's own `BelongsToCompany` scope, same spirit as `Category`'s parent guard, reused by
      `SupplierAccount` too). `customer_sites` scoped through `customerAccount` via
      `BelongsToCompanyThroughParent`, same as `PartySite`. `tax_code_id` is an unconstrained
      forward reference — `tax_codes` doesn't exist yet, same treatment as `default_supplier_id`
- [x] ~~`supplier_accounts`, `supplier_sites` — migrations, models, `BelongsToCompany`~~ — done
      2026-08-20, mirroring `customer_accounts`/`customer_sites` above; `payable_account_id`
      needed no rename (already correctly named)
- [x] ~~Manual exchange-rate entry endpoint(s) for `exchange_rates`~~ — done 2026-08-21.
      `App\Http\Controllers\Api\V1\ExchangeRateController` (index/store/show/update/destroy),
      registered from a new `App\Providers\CurrencyServiceProvider` — `Currency`/`ExchangeRate`
      are cross-cutting (Phase 0), not owned by an `app/Modules` module, so they get their own
      provider the same way `AuthServiceProvider` does, per code-structure.md's self-registering
      routes pattern. The controller always stamps `source = 'manual'` rather than trusting
      client input, per ADR 0031's consistency requirement, and the store request rejects a
      from/to-currency pair that's already recorded for that date and rate_type with a 422 (the
      DB's composite unique constraint would already reject it, but as a raw `QueryException`).
      `UpdateExchangeRateRequest` deliberately only accepts `rate` — the pair/date/type identify
      *which* rate this is, so changing any of them is a new rate, not a correction of this one.
      Found and fixed a real Scramble inference gap in the process: `whenLoaded()` must wrap the
      nested resource (`$this->whenLoaded('fromCurrency', fn () => new CurrencyResource(...))`),
      not the reverse (`CurrencyResource::make($this->whenLoaded(...))`) — only the first form
      lets Scramble see the field is conditional and mark it optional in the generated OpenAPI
      schema instead of always-present; confirmed by inspecting the exported schema directly.
      List filters and pagination are validated before reaching PostgreSQL; rate input is bounded
      to the schema's `decimal(19,9)` precision; and the named unique-constraint violation is
      translated to a 422 to close the concurrent-insert race left by validation alone. Orval was
      regenerated in the same change. Full toolchain green (Pest 212/212, Larastan, Pint)
- [x] ~~Architecture test: `categories.parent_id` has no cycle~~ — done 2026-08-20, folded into
      `CategoryTest` rather than `tests/Architecture/` since it's data-dependent (a
      `Category`-specific `saving` guard, not a schema-level property `information_schema` can
      prove on an empty table the way `CompanyScopedUniqueIndexTest` does)
- [x] ~~API endpoints (`/api/v1/items`, `/categories`, `/parties`, `/customer-accounts`,
      `/supplier-accounts`, `/chart-of-accounts`) per api-conventions.md~~ — done 2026-08-21. Full
      CRUD for all six, living inside their owning module's `Http/` (`app/Modules/Inventory`,
      `Parties`, `Accounting`), unlike `ExchangeRateController` which correctly lives outside
      `app/Modules` since `Currency`/`ExchangeRate` are cross-cutting — each module got its own
      `InventoryServiceProvider`/`PartiesServiceProvider`/`AccountingServiceProvider` registering
      a `routes.php` inside the module, per code-structure.md's self-registering-routes pattern
      (`routes/api/exchange-rates.php` is the right shape only for non-module cross-cutting
      concerns). A new `App\Rules\Support\CompanyScoped` (`exists()`/`unique()` factories scoped
      to the current company) is shared across every new request — extracted immediately rather
      than after the fact, since six resources needed the identical pattern from the start, the
      same reasoning `GuardsCompanyScopedHierarchy`/`GuardsRelatedCompanyScope` were extracted
      under. Closed a systemic gap surfaced in the process: model guards
      (`GuardsCompanyScopedHierarchy`, `GuardsRelatedCompanyScope`, `BelongsToCompany`, `Item`'s
      valuation_method guard) threw a bare `RuntimeException` with no global handler —
      client-triggerable (a bad `parent_id`, a cross-company FK, a category with no resolvable
      default) but previously surfaced as an uncaught 500. New `App\Exceptions\
      DomainInvariantException` (still a `RuntimeException` subclass, so every existing
      `expectException(RuntimeException::class)` test kept passing unchanged) carries the
      offending field name; `bootstrap/app.php` maps it to a 422 in the same shape as validation.
      Two more global handlers close the same class of gap at the database layer:
      `UniqueConstraintViolationException` → 422 (a generic backstop behind each request's own
      `CompanyScoped::unique()` check, for the concurrent-insert race that check alone can't
      close — same reasoning as the exchange-rate endpoint's own catch) and a foreign-key RESTRICT
      violation (SQLSTATE 23503, e.g. deleting a category items still reference) → 409, registered
      site-wide rather than pre-checked per endpoint since the database is the authoritative
      source of truth there. Also found and fixed real bugs while writing tests, not just
      anticipating them: `ItemController`/`CustomerAccountController`/`SupplierAccountController`/
      `PartyController`/`ChartOfAccountController::store()` all needed `->refresh()` after
      `create()` — several columns (`tracking`, `status`, `is_stocked`, `custom_fields`, …) only
      get their real value from a Postgres column default when omitted from the request, which
      the in-memory model from `create()` never sees, so serialising it directly returned `null`
      for fields the response schema declares non-nullable (`CategoryController` doesn't need
      this fix — it has no non-null DB-level defaults today, so it was left alone rather than
      changed defensively for a scenario that can't happen). `payment_terms_days`/`lead_time_days`
      are bounded to Postgres's real signed `smallint` range (`between:0,32767`) since Laravel's
      "unsigned" modifier is a MySQL-only no-op on Postgres; `credit_limit` is regex-bounded to
      `decimal(19,4)`'s real precision, same reasoning as the exchange-rate endpoint's rate field.
      Full toolchain green (Pest 271/271, Larastan, Pint)
- [x] ~~Orval regeneration verified, `codegen-check` green on real CI~~ — done 2026-08-21, in the
      same change. Surfaced and fixed two more Scramble inference gaps while regenerating: all six
      new `*Resource` classes needed an explicit `@mixin` PHPDoc (non-standard module namespaces
      don't match Scramble's `XResource` → `App\Models\X` naming-convention guess, unlike
      `ExchangeRateResource`/`CurrencyResource`, which already live in the namespace Scramble
      expects); every `Update*Request::rules()` needed `$this->route('x')?->id` instead of
      `$this->route('x')->id` — Scramble calls `rules()` outside a real request to infer types,
      where `route()` returns `null`, so the un-guarded version silently dropped the `code`/
      `party_id` uniqueness rule from the generated schema entirely rather than erroring loudly.
      Confirmed both by inspecting the exported schema directly (0 warnings, down from 20;
      `code`'s `maxLength: 255` present on every `Update*Request` schema) and by verifying `tsc
      --noEmit` on all three frontend packages and a real `vite build` (via the project's own
      `node` container — the host's `node_modules` has an unrelated musl/glibc rollup mismatch)
      both pass against the regenerated client
- [x] ~~Pest coverage: company scoping on every new table, composite uniqueness, the GL-mapping
      inheritance walk, the category cycle guard~~ — already satisfied by each model's own test
      (`CategoryTest`/`ItemTest`/etc., 2026-08-20) before this round of work started; reinforced
      at the HTTP layer by the new `*ApiTest` suites, which additionally prove every one of these
      rejections surfaces as a 422/409 through the API, not just inside the model layer directly

Tick these in the same commit as the work.

## Acceptance criteria

- [x] An item's resolved GL accounts correctly walk up the category tree when the item itself
      has none set — proven in `ItemTest` (2026-08-20)
- [x] A party can hold a `customer_account`, a `supplier_account`, or both simultaneously, and
      each account's fields are independent — proven in `PartyTest` (2026-08-21)
- [x] A `party_site` can be referenced by both a `customer_sites` row and a `supplier_sites` row
      — proven in `PartyTest` (2026-08-21)
- [x] Cross-company scoping holds for every new table — `CompanyScopedUniqueIndexTest` covers
      every `company_id` column via `information_schema`, automatically extended to
      `chart_of_accounts`/`fiscal_years`/`accounting_periods`/`customer_accounts`/
      `supplier_accounts` with no test changes needed (2026-08-20). Separately, every
      cross-table *account reference* (`categories`/`items`' three GL columns,
      `customer_accounts.receivable_account_id`, `supplier_accounts.payable_account_id`) is now
      guarded against pointing at another company's `chart_of_accounts` row via
      `GuardsRelatedCompanyScope`; composite PostgreSQL foreign keys also protect raw writes.
      `company_id` is context-controlled and immutable, child-parent reassignment is guarded on
      update, and item/site/fiscal-period ownership is covered (2026-08-21)
- [x] A custom field defined for `items`/`parties` appears on create/read with no code change —
      the first real exercise of `HasCustomFields` — proven in `ItemApiTest`/`PartyApiTest`
      (2026-08-21): a `CustomFieldDefinition` created directly, no code change, then round-tripped
      through a real create-then-read HTTP cycle
- [x] `docker compose up` + migrate gives all Phase 1 tables with no errors; full test suite
      green on Postgres — verified directly against the real dev stack, not just CI (2026-08-21)
- [x] Manually entering an exchange rate through the API is reflected correctly — no rate is
      fabricated or silently defaulted — proven in `ExchangeRateApiTest` (2026-08-21): `source` is
      always `'manual'` regardless of client input, and no filter/pagination/rate value is
      silently clamped or coerced — each rejects with a 422 instead

## Docs to update on completion

- [x] [STATUS.md](../STATUS.md) — mark Phase 1 done, move Next to Phase 2 — done 2026-08-21
- [x] [items.md](../domain/items.md), [parties.md](../domain/parties.md) — reconciled 2026-08-31;
      documented implemented nullability/defaults, soft-delete boundaries, parent/site ownership
      guards and composite tenant foreign keys
- [x] [general-ledger.md](../domain/general-ledger.md) — reconciled 2026-08-31; confirmed the
      implemented `chart_of_accounts` shape and made its Phase 1 physical-delete versus Phase 6
      archive-after-posting boundary explicit
- [ ] Expand [phase-2](README.md#phase-2--purchasing--stock) into its own doc

## Related

- [domain/items.md](../domain/items.md) · [domain/parties.md](../domain/parties.md) ·
  [domain/general-ledger.md](../domain/general-ledger.md) ·
  [domain/multi-currency.md](../domain/multi-currency.md)
- [ADR 0030](../decisions/0030-unified-party-model.md) ·
  [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)
- [architecture/api-conventions.md](../architecture/api-conventions.md) ·
  [architecture/extensibility.md](../architecture/extensibility.md) — `HasCustomFields`
