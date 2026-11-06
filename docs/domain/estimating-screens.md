[← Domain index](README.md)

# Estimating screens — masters, rates, recipes

The interaction specification and coverage matrix for the Estimating group in the sidebar (Rate Dimensions, Application Rates, Formulations, Assembly Groups,
Assemblies, Resource Rates, Material Preview), brought to the [screen standard](../architecture/erp-blue-design-standard.md) on `cloud/screens-complete` on
2026-11-05. Rules, access and the API stay owned by [application-rates.md](application-rates.md), [formulations.md](formulations.md) and
[assemblies.md](assemblies.md); this file records how they are presented and which backend contracts each screen reaches.

## What the six lists have in common

Every list is a `ScreenTemplate kind="list"` with the shared furniture of `components/data-table/list-controls.tsx`: URL-held search and filters, applied-filter
chips with **Clear all**, selection with a bulk bar, a phone **Filters** sheet, cards by default on a phone, and an error state that says why and offers
**Try again** (distinct from the empty state). The filter, sort and page live in the URL, so a refresh or a shared link reopens the same list.

| Screen | Route | Search | Filters | Bulk |
|---|---|---|---|---|
| Rate dimensions | `/rate-dimensions` | code, name | — | Print selected |
| Application rates | `/application-rates` | **item code or name** (new, see below) | item, work unit, default status | Print selected |
| Formulations | `/formulations` | code, name | status, output item, customer | Print selected |
| Assembly groups | `/assembly-groups` | code, name | — | Print selected |
| Assemblies | `/assemblies` | code, name | status, assembly group | Print selected |
| Resource rates | `/resource-rates` | — (the endpoint has none) | item (labour and plant items) | Print selected |

**Bulk is print only, on purpose.** Everything else these screens change is a per-record decision with consequences — a delete that is refused while the record is
in use, an archive with no way back, a version activation that supersedes the live one. The screen contract says not to infer bulk safety from a single-record
endpoint, so none is offered; each consequence is stated in the confirmation on the record itself.

The item, customer, unit and assembly-group filters are the server-searched pickers in `components/lookups/entity-comboboxes.tsx` (`ItemCombobox`,
`CustomerCombobox`, `UomCombobox`, `AssemblyGroupCombobox`); the chosen record is named from its own id, so a bookmarked URL keeps its chip however many records
exist. Status values read as words (`ACTIVE` → Active) while the value stays the code.

## Forms are sheets (decision D7)

Create and edit forms of all six masters open as right-hand sheets over their list, which stays mounted behind them (`presentation.rateDimension*`,
`application…`, `formulation…`, `assemblyGroup…`, `assembly…`, `resourceRate*` in `apps/main/src/presentation.ts`; the page alternative is kept and one word
switches each). The URLs are unchanged — `/x/new` and `/x/:id/edit` — and carry the list's own query string, so closing returns to the same filtered page.
Resource rates have no update endpoint, so their record `/resource-rates/:id` is a read-only sheet.

- A form sheet is not dismissed by a click behind it (`RecordPresenter form`): typed input is not lost to a stray click. **Cancel**, **Escape** and the close
  button return to the list.
- **Save** on a header closes the sheet and refreshes the list; **Create** opens the new record's own sheet so the next step (values, versions, overhead) is
  one click away.
- Formulations and Assemblies are workspaces (header + versions + cost), so they use the wide (`xl`) sheet; the rest use `lg`.
- On a phone the sheet is the whole screen width, every action is a 44 px target and the footer with **Cancel / Save** stays in view.

## Coverage matrix — backend contract → screen

| Backend contract | Screen | Gate (screen only; the API is authoritative) | Acceptance |
|---|---|---|---|
| Rate dimension create / update | the dimension sheet | `estimating.rate-dimension.manage` | browser |
| **Rate dimension delete** | *Delete dimension*; refused while it has values or a rate is tagged — the server's reason is shown | manage | browser (demo dimension: refused, nothing changes) |
| Dimension value create | *Add value* in the dimension sheet | manage | browser |
| **Dimension value update** (name, code) | *Rename* on the value row | manage | browser |
| Dimension value delete | confirmation; refused while an application rate is tagged with it | manage | browser |
| Application rate create / update | the rate sheet; item and work unit are fixed once created (shown disabled) | `estimating.application-rate.manage` | browser |
| **Application rate delete** | *Delete rate*; refused while an assembly component points to it | manage | browser (refusal and success) |
| Application rate index `filter[search]` | the search box | recipe access | Pest + browser |
| Application rate resolve | *Test resolution* above the list (unchanged) | recipe access | browser |
| Formulation create / update, versions create / edit draft / activate / delete draft | the formulation sheet; activate and delete ask first and say what happens | `estimating.formulation.manage` | browser |
| **Formulation archive** | *Archive* with a confirmation that states there is no un-archive | manage | browser |
| **Formulation resolve** | *Test resolution* above the Formulations list: output item + customer → the recipe that applies (customer's own, else the company default) or the refusal | recipe access | browser |
| Formulation cost breakdown | in the sheet, as before | `estimating.internal_cost.view` | browser (existing) |
| Assembly group create / update | the group sheet, with its overhead policy | `estimating.assembly-group.manage` | browser |
| **Assembly group delete** | *Delete group*; refused while an assembly belongs to it | manage | browser (demo group: refused) |
| Assembly create / update, versions create / activate / delete draft | the assembly sheet | `estimating.assembly.manage` | browser |
| **Assembly version update** (notes, components of a DRAFT) | *Edit draft* opens the editor on what is stored | manage | browser (values reloaded, saved) |
| **Assembly archive** | *Archive* with a confirmation that states there is no un-archive | manage | browser |
| Resource rate create | *New rate* sheet | `estimating.resource-rate.manage` | browser |
| Resource rate show / **delete** | the read-only record, *Delete* behind a confirmation | manage (read: internal-cost view) | browser |
| **Overhead policy update / delete (a DRAFT)** | *Edit* and *Delete* on each draft in the policy panel (company, group, assembly); activate asks first | `estimating.overhead-policy.manage` | browser |
| Material Preview | on `ScreenTemplate kind="form"`; behaviour unchanged | `estimating.material_preview.run` | browser (a11y) |

Deletes of these masters are **hard deletes** (the Estimating models have no soft delete), so the foreign keys are what protect records in use; the API turns a
refusal into `409 "This record is still referenced by other records…"` and the confirmation dialog shows exactly that instead of closing as though it worked.
Archiving a formulation or an assembly only sets its status; there is no endpoint to bring one back, so the dialog says to create a new one.

## Access

Screens open to a reader (`estimating.recipe.view`) show their records; the create and edit routes additionally need the maintaining duty
(`screen-access.ts`), so a reader who types `/formulations/new` is stopped at the screen and the server would refuse anyway. A person with no duty that opens a
screen gets the access message and the screen makes no request. Resource rates are shown to someone who may read internal cost; their rate is `Restricted`
otherwise (the API leaves it out).

## Not covered here

Quotations (`features/quotations`) belong to other work and were not touched. The Settings screens for Estimating, Material selection and Material variance
remain forms on their own pages (see the [log](../phases/screens-complete-log.md), remaining work).
