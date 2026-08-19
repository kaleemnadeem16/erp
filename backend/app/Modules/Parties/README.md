# Parties

Owns everyone the company trades with: a shared trading-partner identity plus independent
customer and supplier accounts hung off it.

**Governed by:** [docs/domain/parties.md](../../../../docs/domain/parties.md) — the domain rules
this implements. See [ADR 0030](../../../../docs/decisions/0030-unified-party-model.md) for why
customers and suppliers are one module rather than split across Sales/Purchasing.

## Owns

- `Party`, `PartyContact`, `PartySite` — the shared identity and its contacts/locations
- `CustomerAccount`, `CustomerSite` — the customer role, independent lifecycle from the supplier role
- `SupplierAccount`, `SupplierSite` — the supplier role, independent lifecycle from the customer role

## Does not own

- Quotations, purchase orders, invoices — Sales/Purchasing/Receivables reference
  `customer_accounts`/`supplier_accounts` by id, they don't live here
- Credit control, collections, or any transactional workflow around a customer — Receivables
  (Phase 6); this module owns the `credit_limit` field, not what happens when it's exceeded
- GL posting — `income_account_id`/`payable_account_id` are references only, Accounting owns
  what posts against them

## Key actions

| Action | Purpose |
|---|---|
| `CreateParty` | Creates the shared identity. Does not imply either role. |
| `ArchiveParty` | Only valid once both roles (if any) are archived. |
| `CreateCustomerAccount` | Attaches the customer role to an existing party. |
| `ArchiveCustomerAccount` | Independent of the supplier role and the party itself. |
| `CreateSupplierAccount` | Attaches the supplier role to an existing party. |
| `ArchiveSupplierAccount` | Independent of the customer role and the party itself. |

## Events

No events emitted yet — Phase 1 has no other module reacting to a state transition here. Add to
this table when one does, rather than pre-emitting speculatively.

## Non-obvious

Other modules read `CustomerAccount`/`SupplierAccount` directly via Eloquent — that's the normal
foreign-key path, not a boundary violation (see
[code-structure.md](../../../../docs/architecture/code-structure.md#reads-versus-writes-across-modules)).
Writes to these tables from outside this module are the thing to avoid — go through the Actions
above, not a direct `->update()` on the model.

## Related

- [domain/parties.md](../../../../docs/domain/parties.md)
- [ADR 0030](../../../../docs/decisions/0030-unified-party-model.md)
