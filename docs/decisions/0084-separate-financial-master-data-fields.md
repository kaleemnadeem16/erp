[← Decisions](README.md)

# 0084 — Separate financial master-data fields from ordinary maintenance

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Accounting, Parties, Inventory Item/Category mappings; resolves Q34 and audit A3/A4

## Context

Customer/supplier maintenance currently includes credit limits, payment terms and ledger mappings.
Item/Category mappings and account credit fields appear on shared authenticated reads. Editing a
contact or item must not automatically confer financial configuration authority. The owner accepted
the recommended field-by-purpose split on 2026-10-06 after the Q34 research brief.

## Decision

Use company-assigned capabilities, not role names:

| Fields | Read | Change (also supplies minimum field read) |
|---|---|---|
| Customer `credit_limit` | `parties.credit-limit.view` | `parties.credit-limit.manage` |
| Customer/supplier `payment_terms_days` | Anyone allowed to read the account | `parties.payment-terms.manage` |
| Item/Category income, expense, inventory account IDs; customer receivable and supplier payable account IDs | `accounting.account-mapping.view` | `accounting.account-mapping.manage` |

- Field-change authority supplements the existing resource maintenance capability; it does not
  itself permit creating/editing an item, category or trading account.
- Require field authority whenever a protected key is submitted, including null or unchanged
  values, at both HTTP and Action boundaries. Omission preserves stored values; do not silently
  discard an unauthorised write or clear an omitted field.
- Creation of a customer/supplier account still requires its mandatory terms and control account:
  those fields need their change capabilities. Party identity creation remains independent.
  Optional Item/Category mappings can be omitted by an ordinary maintainer and inherit normally.
- Omit restricted response keys everywhere their Resource appears: list/detail, lookups, nested
  includes and mutation responses. Keep allowed nulls distinct from omitted/hidden fields.
  Existing category forms, detail and quick print must honour the same boundary and omit financial
  keys from submissions when the caller cannot change them. No new Item/Party screen is required.
- Shared chart references remain account identity/structure lookups, not balances or mappings to
  particular items/customers/suppliers. This decision does not restrict that catalogue or grant
  access to journal entries, credit utilisation, stock valuation or commercial prices.
- Backfill only the previous field maintainers, via role and direct grants in each company:
  customer-account maintainers receive credit read/change; customer/supplier account maintainers
  receive terms change; those maintainers and item/category maintainers receive mapping read/change.
  Do not infer financial readership from a role name, cost capability, stock duty or ordinary login.
  Existing read-only users lose shared financial fields unless explicitly assigned their new read
  capability. Verify the intended Finance/sales readership and legacy-maintainer backfill in staging.
- The backfill is additive/idempotent and grants individual capabilities, never a broad role.
  Permission registration and designated-administrator sync remain deployment steps.

## Consequences

- **Positive:** Operational terms remain usable; credit and account configuration have separately
  assignable read/change authority and one projection across consumers.
- **Negative:** An account creator needs its mandatory financial setup permissions. Previously
  shared credit/mapping reads narrow intentionally; existing reader assignments need owner review.
- **Requires:** Field-presence, attempted-write and non-HTTP tests; tenant-safe rollout tests;
  generated optional response types and permission-aware category forms/print. The owner can give
  a small-company operator all capabilities or separate sales readership from Finance changes.

## Alternatives considered

**Shared financial reads with only restricted edits** — rejected: the owner chose confidentiality
for credit limits and actual account mappings, independently from permission to edit them.

**Hide all three field groups** — rejected: operational users need payment terms for their work.

**Reuse master-data, valuation or commercial-cost authority** — rejected: each serves a different
business duty; one does not establish credit or account-configuration readership.

**Write without minimum read, silently drop attempted changes or return zero** — rejected: writers
need to verify their settings, unauthorised changes need a clear refusal, and zero is a real value.

## Research basis

Question: how should an ERP protect financial fields inside operational master data? Reused
[ADR 0069](0069-separate-purchasing-commercial-visibility-from-receiving.md),
[ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md) and the existing
Q34 brief, under [research-and-design.md](../architecture/research-and-design.md).
Primary sources verified 2026-10-06:

- [Dynamics field/data-entity security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/security-data-entities#sensitive-data)
  supports separate privileges for nonessential sensitive fields, including API/import access.
- [Odoo 19 field security, official documentation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/developer/reference/backend/security.rst)
  uses field groups and refuses explicit unauthorised reads/writes while removing restricted fields
  from metadata/views. The rendered documentation page timed out; its official source was read.

Both distinguish field authority from access to the surrounding record. Dynamics packages separate
privileges; Odoo uses a group boundary. Neither mandates this ERP's field classification or role
names: credit/mapping confidentiality and shared terms are the owner's accepted choices. Stable
capabilities, additive company assignments and Resource omission fit this modular monolith.
Q26 organisation scope and Q35 approval evidence/ceilings/reasons remain separate decisions.

## Related

- [Parties](../domain/parties.md), [Items](../domain/items.md), [General ledger](../domain/general-ledger.md)
- [Authorisation](../architecture/authorization.md), [Access audit](../architecture/access-security-audit.md)
- [ADR 0028](0028-dynamic-namespaced-permissions.md), [ADR 0069](0069-separate-purchasing-commercial-visibility-from-receiving.md)
