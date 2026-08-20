[← Decisions](README.md)

# 0034 — Scope party child tables through their owning parent

- **Status:** accepted
- **Date:** 2026-08-21
- **Affects:** Phase 1, Parties module, [parties.md](../domain/parties.md)
- **Supersedes:** [ADR 0030](0030-unified-party-model.md)

## Context

ADR 0030 correctly chose a unified party identity but described every party-related table as
carrying `company_id` and using `BelongsToCompany`. Implementation established the more precise
rule already documented in the multi-tenancy architecture: independently queried records carry
`company_id`; ownership-only child and join rows inherit it through their parent. It also named
the customer control account `income_account_id`, which incorrectly conflates receivables with
revenue.

## Decision

Keep ADR 0030's unified identity and role-account design, with these corrections:

- `parties`, `customer_accounts`, and `supplier_accounts` carry `company_id` directly.
- `party_contacts` and `party_sites` scope through `party_id`.
- `customer_sites` and `supplier_sites` scope through their role account, and their referenced
  `party_site` must belong to that same account's party.
- The customer control account is `receivable_account_id`; item/category
  `income_account_id` remains the source of revenue mapping.

## Consequences

- **Positive:** Child rows cannot drift across tenants or attach a role to another party's site.
- **Positive:** The schema avoids duplicating `company_id` on rows that have no meaning outside
  their owner while retaining structural read/write isolation.
- **Negative:** Child ownership needs scoped model guards because a plain single-column foreign
  key proves existence but not tenant or same-party ownership.
- **Requires:** Parent reassignment is checked on both create and update; direct-company tables
  use composite tenant foreign keys where both sides carry `company_id`.

## Alternatives considered

**Put `company_id` on every child and join row** — rejected. It duplicates ownership state and
allows the duplicated company value to disagree with the parent unless every relationship is
also constrained compositely.

**Rely on Actions to validate site ownership** — rejected. Direct Eloquent assignment, imports,
and future write paths would be able to bypass a convention.

## Related

- [ADR 0030](0030-unified-party-model.md)
- [Multi-tenancy](../architecture/multi-tenancy.md)
- [Parties](../domain/parties.md)
