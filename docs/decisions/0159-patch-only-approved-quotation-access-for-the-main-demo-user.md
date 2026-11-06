[← Decisions](README.md)

# 0159 — Patch only approved quotation access for the main demo user

- **Status:** accepted
- **Date:** 2026-11-06
- **Affects:** Administration SyncMemberAccess; quotation demo presets; supplements 0156

## Context

The approved synthetic operator holds only access administration and the approved quotation
rights. The main demo user already holds unrelated ERP access. SyncMemberAccess replaces the
entire assignment set and correctly refuses retained permissions the operator does not hold.
Preserving unrelated access therefore needs an explicit patch operation, not broader operator
rights or a weaker full-replacement guard.

## Decision

Add a narrowly guarded quotation-only patch method to the real SyncMemberAccess Action. Allow
only the approved demo environment (local/testing or existing DEMO_MODE), designated Company 1,
exact synthetic operator and exact main demo login. All identities and authority are checked from
current database state inside the security transaction. The existing full-replacement method stays
unchanged.

- Validate every added quotation role/direct right against the operator's actual rights through
  the existing grant guard; require independent ALL scope for each duty the synthetic setup
  operator administers, so a limited operator cannot widen a target. Preserve self-management
  refusal and tenant isolation.
- Manage only view, prepare, assign, delegate and revoke; never issue, repair or unrelated duties.
- Preserve unrelated role memberships, direct grants and scope rows. Refuse mixed conferring roles
  that cannot be changed without affecting unrelated access; do not silently leave a limited/off
  preset defeated by an unrestricted path.
- Record before/after quotation access and actor provenance. Repeating an unchanged patch adds
  nothing. Invalid scope or a later failure rolls back the whole patch and its audit.
- No route, UI, new deployment setting, permission registration/activation or ordinary grant bypass.
  The separately approved bootstrap retains its own exact-SHA review and CI gate.

## Consequences

- **Positive:** limited demo presets can preserve existing unrelated ERP access.
- **Negative:** the explicit subset operation needs dedicated security/atomicity/idempotence tests.
- **Requires:** environment/company/identity refusals, grant ceiling, self-change refusal, exact scope,
  mixed-role refusal and proof that unrelated access rows remain unchanged.

## Alternatives considered

**Give the operator every unrelated ERP permission** — rejected: exceeds its approved rights.

**Strip the main user's unrelated rights** — rejected: violates preservation and demo scope.

**Relax full-replacement checks or mutate raw pivots from a command** — rejected: ordinary guards
remain and the real access Action owns the authorised subset change.

## Research basis

This is a local technical access-management decision, reusing the existing authorisation and
quotation demo contracts; no ERP business authority or external product behaviour is redesigned.

## Related

- [Demo decision](0156-quotation-demo-access-and-activation-boundaries.md)
- [Bootstrap decision](0155-bootstrap-only-the-synthetic-demo-access-operator.md)
- [Persona/preset contract](../domain/quotation-access-demo.md)
- [Authorisation](../architecture/authorization.md)
