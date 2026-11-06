[← Domain index](README.md)

# Reference-data screens — Exchange rates and Chart of accounts

The interaction specification and coverage matrix for two screens that expose already-complete backends (built 2026-11-05 on `cloud/screens-complete`):
manually entered [exchange rates](multi-currency.md) (ADR 0031) and the [chart of accounts](general-ledger.md). Rules, access and the API stay owned by those
documents; this file records how they are presented.

## Where they live

Both sit under **Settings** — Exchange rates in *Numbers, money and rounding*, Chart of accounts in *Company* — in the top-bar Settings menu, the mobile
"More" sheet and the Settings index, each shown only to a person who holds its maintenance duty. They are reference data the other documents pick from, not a
workflow module, so no new sidebar group was created (decision D3 in the [log](../phases/screens-complete-log.md), awaiting owner confirmation; moving them is
one entry in `layouts/app-shell.tsx` and `settings-catalogue.ts`).

| Screen | Route | Gate (screen only; the API is authoritative) |
|---|---|---|
| Exchange rates | `/settings/exchange-rates` (+ `/new`, `/:id` as sheets) | `currency.exchange-rate.manage` |
| Chart of accounts | `/settings/chart-of-accounts` (+ `/new`, `/:id` as sheets) | `accounting.chart-of-account.manage` |

Reading rates and accounts remains a shared lookup for the documents that use them (pickers); these screens are for those who maintain them, so a person
without the duty gets the access message and the screen makes no request. Each record opens as a right-hand sheet over its list (`presentation.exchangeRate*`,
`presentation.chartOfAccount*`; page alternatives kept). There is no separate read-only detail screen: the record sheet shows every fact the record has
(five fields), and is the edit form for a person who may change it — a deliberate simplification, recorded as decision D5.

## Exchange rates — coverage matrix

| Contract element | UI destination | Availability | Acceptance / omission |
|---|---|---|---|
| Create (`POST /exchange-rates`) | *New rate* sheet: from, to, date, type, rate | `…exchange-rate.manage` | browser: problems beside fields; saved |
| Update (`PATCH`) — the **rate only** | the record sheet: pair, date, type and source read-only, the rate editable | manage | browser: new value shown with all decimals |
| Delete (`DELETE`, a hard delete) | *Delete* in the sheet header, behind a confirmation that states the consequence | manage | browser: consequence stated; row disappears |
| Duplicate (pair, date, type) | 422 shown beside the date; typed input kept | — | browser |
| List filters `from_currency_id`, `to_currency_id`, `rate_type`, `rate_date` | currency pickers, type select, date field; phone Filters sheet; chips + Clear all | — | browser: request + chip |
| List sorts `rate_date` (default newest first), `rate`, `created_at` | column headers | — | unit: columns sortable |
| `source` | optional column (the API stamps `manual`; ADR 0031) | — | not editable by design |
| Currencies (`GET /currencies`) | the pickers; no maintenance endpoint exists | — | read-only reference |

Rates are decimal **strings** (up to 10 digits before the point and 9 after, above zero); the screen never parses them into numbers. Documents keep the rate
they were made with and no historical amount is recomputed, which is what the delete confirmation says. Bulk: only *Print selected*; deleting is per rate.

## Chart of accounts — coverage matrix

| Contract element | UI destination | Availability | Acceptance / omission |
|---|---|---|---|
| Create / update (`code`, `name`, `type`, `parent_id`, `is_postable`, `currency_id`, `status`) | the record sheet; currency under *More options* (its summary shows a set value while closed) | `…chart-of-account.manage` | browser: create with problems beside fields |
| Archive / activate | sheet header, row menu, and a bulk bar (*Archive selected* / *Activate selected* through `BulkRunDialog`) | manage | browser: bulk result honest |
| Delete (`DELETE`) | not offered | postings may reference an account, so it is archived instead | deliberate omission |
| Parent self-reference | the parent picker excludes the account itself; validated before sending | — | unit |
| List filters `search`, `type`, `status`, `parent_id` (0 = top level) and sorts `code`, `name`, `created_at` | search + type + status beside it; *Level* and *Under account* in the advanced panel | — | browser |
| Account type check against its use (receivable = asset, …) | not enforced | deferred to Phase 6 (general-ledger.md) | unchanged |

## Related

- [multi-currency.md](multi-currency.md) · [general-ledger.md](general-ledger.md) · [parties-screens.md](parties-screens.md) (the receivable-account picker is shared)
- [Screen delivery contract](../architecture/screen-delivery-contract.md) · [Screen design standard](../architecture/erp-blue-design-standard.md) · [UI building blocks](../architecture/ui-building-blocks.md)
- [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md) · [Screens completion log](../phases/screens-complete-log.md)
