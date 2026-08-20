[← Decisions](README.md)

# 0030 — Unified party model for customers and suppliers

- **Status:** superseded by [0034](0034-party-child-tables-inherit-company-scope.md)
- **Date:** 2026-08-19
- **Affects:** Phase 1, [domain/parties.md](../domain/parties.md)

## Context

Customers and suppliers had no domain doc, unlike [items.md](../domain/items.md), which already
uses a single table with a type discriminator rather than separate tables — the stated reason
being that product/service/resource rows "share too much (codes, units, costing, GL mapping) to
justify splitting." The same question applies to trading partners: a real-world business is
often both a customer and a supplier to us (e.g. a distributor we buy raw material from and also
sell finished product to), and modelling those as two unrelated rows in two unrelated tables
loses that identity.

Two shapes were considered: separate `customers`/`suppliers` tables, or a shared identity with
role-specific accounts hung off it. Resolving the shared-identity shape also forced two
sub-decisions: whether the shared identity should be scoped by `company_id` like every other
business table, and whether to support individual (non-organisation) parties from the start.

**Company scoping.** [multi-tenancy.md](../architecture/multi-tenancy.md) and
[GLOSSARY.md](../GLOSSARY.md) establish that `company_id` is the full tenant-isolation boundary —
not a legal-entity subdivision within one customer. [ADR 0004](0004-multi-tenancy-ready-schema.md)
names the column `company_id` rather than `tenant_id` specifically because "multi-entity and
multi-branch structures are useful within a single organisation," but mechanically a second legal
entity is just another row in `companies`, isolated from its sibling exactly as strictly as from
an unrelated tenant. There is no grouping construct above `company_id` today. This matters
because an unscoped, "global" `parties` table was briefly considered as a way to share a trading
partner across one customer's own legal entities — but "global" in this schema means shared
across *every* `company_id`, including unrelated tenants, which is exactly the failure class
[invariants.md](../architecture/invariants.md) calls "the worst class of bug this system can
have." That's a different problem than the one under discussion, and it has no answer in the
current architecture — see [Q10](../OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities).

**Individual vs. organisation parties.** No domain doc — `sales-pipeline.md`, `receivables.md`,
`job-costing.md`, `assemblies.md`, `GLOSSARY.md` — mentions a retail, walk-in, or individual
trading partner anywhere. Every documented workflow (opportunities, quotations,
retention/retainage, job costing) reads as B2B. This is absence of counter-evidence, not a
stated requirement either way, so it's treated as a default to revisit, not a closed question.

## Decision

Model trading partners as a shared identity plus role-specific accounts:

```
parties            -- identity: code, name, registration/tax id, contacts
party_sites        -- physical locations, shared across roles
customer_accounts  -- role-specific: credit_limit, payment_terms, income_account_id...
supplier_accounts  -- role-specific: payment_terms, lead_time, payable_account_id...
customer_sites / supplier_sites   -- role-specific config for a shared party_site
```

Full schema in [domain/parties.md](../domain/parties.md).

- All five tables are `company_id`-scoped, with no exception to the multi-tenancy invariant.
- Organisations only for now — no `ORGANIZATION | INDIVIDUAL` discriminator — but the model is
  kept generic enough (no organisation-only columns baked into `parties` itself) that the
  discriminator can be added later without replacing the table.

## Consequences

- **Positive:** One identity per real-world trading partner within a company. A party that is
  both customer and supplier gets one `parties` row and two role accounts, not two disconnected
  records with duplicated name/contact/site data.
- **Positive:** Role-specific fields live where they apply (`customer_accounts.credit_limit`
  doesn't leak onto a pure-supplier row as a meaningless null), following the same discipline
  `items.md` uses for GL mapping.
- **Negative:** More tables and joins than two flat tables would have needed. Reading "a
  customer's full profile" means joining `parties` + `customer_accounts` + `party_sites` +
  `customer_sites`. More upfront schema work in Phase 1 than the naive shape.
- **Requires:** Composite unique constraints on all five tables per
  [multi-tenancy.md](../architecture/multi-tenancy.md) rule 2 (`company_id` + `code` on `parties`,
  `company_id` + `party_id` on the role accounts). `BelongsToCompany` on all five.

## Alternatives considered

**Separate `customers`/`suppliers` tables, no shared identity** — rejected. Duplicates
name/contact/address logic, and a dual-role trading partner has no way to be recognised as one
entity — exactly the problem this decision exists to solve.

**Global, unscoped `parties` table** — rejected. `company_id` is the full tenant-isolation
boundary, not a legal-entity subdivision (see Context). A global table would leak trading-partner
data across unrelated tenants to solve a different, currently-hypothetical problem (sharing a
party across one customer's own multiple legal entities), which has no clean answer in the
current architecture and is logged as [Q10](../OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities)
rather than solved here.

**`ORGANIZATION | INDIVIDUAL` discriminator built now** — rejected for Phase 1. No domain doc
describes an individual/retail/consumer trading partner; the system is B2B throughout. Deferred
because it's cheap to add later — unlike company scoping, it doesn't touch unique constraints or
reshape historical data.

## Related

- [domain/parties.md](../domain/parties.md) — the schema this decision produces
- [domain/items.md](../domain/items.md) — the same shared-table-with-discriminator precedent
- [architecture/multi-tenancy.md](../architecture/multi-tenancy.md)
- [ADR 0004](0004-multi-tenancy-ready-schema.md)
- [Q10](../OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities)
