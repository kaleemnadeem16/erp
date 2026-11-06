[← Domain index](README.md) · [Parties](parties.md)

# Parties — the Customers and Trading partners screens

The interaction specification and coverage matrix for the screens that expose [parties](parties.md) (built 2026-11-05 on `cloud/screens-complete`),
written to the [screen delivery contract](../architecture/screen-delivery-contract.md). The data model, access rules and API are owned by
[parties.md](parties.md); this file only says how they are presented.

## Users and decisions

- **Sales and account staff** look a customer up by name, tax id or code, read its terms and contacts, and (with `parties.customer-account.manage`)
  register, edit and archive customers. A frequent task, hence the Sales navigation group and the phone cards.
- **Master-data maintainers** (`parties.party.manage`) register trading partners, keep their identity and contacts, and are warned about probable
  duplicates before creating one (ADR 0105).
- **Finance** reads credit limits and receivable mapping only with `parties.credit-limit.view|manage` / `accounting.account-mapping.view|manage`
  (ADR 0084); without them those fields do not exist on any surface.

## Screen inventory

| Screen | Route | Presentation | Gate (screen only; the API is authoritative) |
|---|---|---|---|
| Customers list | `/customers` | page; child sheets below | `parties.customer-account.manage`, `parties.party.manage` or a Sales read duty |
| Customer detail | `/customers/:id` | sheet over the list | same as the list |
| Customer create / edit | `/customers/new`, `/customers/:id/edit` | sheet | create: account + payment-terms + receivable-mapping manage; edit: account manage |
| Trading partners list | `/parties` | page; child sheets below | `parties.party.manage`, `parties.customer-account.manage` or `parties.supplier-account.manage` |
| Trading partner detail | `/parties/:id` | sheet | same as the list |
| Trading partner create / edit | `/parties/new`, `/parties/:id/edit` | sheet | `parties.party.manage` |

Every record screen also exists as a page (`presentation.customer*`, `presentation.party*` in `apps/main/src/presentation.ts`).
A missing record and one the person may not see read the same ("isn't available"): the screen never confirms an id exists.

## Coverage matrix

| Contract element | UI destination | Availability | Acceptance / omission |
|---|---|---|---|
| Customer account create / update / archive-reactivate (`customer-accounts`) | Customer form; row menu, detail header and bulk bar | create needs the three capabilities above; edit needs account manage | browser: register → detail; archive → activate; edit terms |
| Customer fields `code`, `payment_terms_days`, `credit_limit`, `receivable_account_id`, `default_currency_id`, `custom_fields`, `status` | Form: account section (code, terms, credit, receivable account) and "More options" (currency, status, custom fields) | credit / receivable only with their capability; terms editable only with terms manage | unit: request body carries a protected key only with authority; emptied credit → `null` |
| `tax_code_id` | not offered | the API rejects any non-null value until Tax exists | deliberate omission (parties.md) |
| Customer list filters `party_id`, `status`, `search`, `default_currency_id`, `receivable_account_id` and sorts `code`, `payment_terms_days`, `status`, `created_at`, `updated_at` | Search + status beside it; partner / currency / receivable account in the advanced panel (phone: Filters sheet); header sorts | receivable filter only with mapping read | browser: filter → request + chip; unit: columns |
| `credit_limit`, `receivable_account_id` in the list | columns that exist only for a reader with the capability | ADR 0084 | unit: columns absent without capability |
| Party create / update / restore / candidates (`parties`) | Trading partner form with the duplicate panel (use / restore); Customers form "Register new" | `parties.party.manage` | browser: duplicate offered and used |
| Party archive (`DELETE /parties/{id}`) | Detail header, confirmation dialog | only when the partner holds no role (the Action refuses otherwise); disabled with that reason | browser: disabled with reason for a customer |
| Party kind, tax id, registration, website, custom fields | Identity fields; More options | — | unit: payloads |
| Contacts list / create / update (`party-contacts`) | Contacts panel in both detail sheets | read: partner manage or a Sales duty; write: partner manage | browser: add (primary) / edit |
| Contact removal | not offered | backend has no endpoint (an archive rule is undecided) | deliberate omission |
| Customer sites / addresses | not offered | **no `customer-sites` / `party-sites` endpoint exists** | blocked: backend incomplete |
| Credit control (what happens on exceeding a limit) | not offered | Receivables, Phase 6 | not applicable here |

Bulk actions: Customers offers *Print selected*, *Archive selected* and *Activate selected* (one request per customer through `BulkRunDialog`, partial failures
listed with the server's reason and retried). Eligibility is stated: a selection with nothing active disables Archive with a reason. Trading partners offers
only *Print selected*: archiving a partner needs both role accounts gone, so a mixed selection has no safe common action. Selection is the current page only
and is cleared on any page, sort or filter change.

These endpoints take no idempotency key and no expected generation (only the quotation API does), so a double submit is prevented by the pending state and by
the unique constraints (`party_id` once per customer account, codes per company); a refusal is shown beside the field it concerns or as a message above the
actions, and typed input is kept.

## Decisions taken here

- Customers sit in the Sales navigation group (mirroring Suppliers in Purchasing); Trading partners sit beside them because the identity is shared with
  Suppliers. Moving either is one entry in `navGroups` (`layouts/app-shell.tsx`).
- The "Make a customer" shortcut on a partner opens the Customer form for that partner (`/customers/new?party_id=`); the one-shot parameter is dropped when
  the form closes so it never pollutes the list's query string.
- The receivable account picker lists active accounts without restricting the type: the type check is a deliberate Phase 6 deferral (parties.md).

## Related

- [parties.md](parties.md) — model, access rules, API
- [Screen delivery contract](../architecture/screen-delivery-contract.md) · [Screen design standard](../architecture/erp-blue-design-standard.md) · [UI building blocks](../architecture/ui-building-blocks.md)
- [ADR 0030](../decisions/0030-unified-party-model.md) · [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md) · [ADR 0105](../decisions/0105-support-generic-party-identity-and-conversion.md)
- [Screens completion log](../phases/screens-complete-log.md)
