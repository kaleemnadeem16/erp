[← Decisions](README.md)

# 0010 — Core + plugin packages, not per-customer forks

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** all backend and frontend structure

## Context

Different companies have materially different processes, and the system must accommodate them.
The intuitive approach — copy the application per customer and modify it — was the original
proposal.

Fork-per-customer fails predictably. By the third customer there are three codebases that have
silently diverged. A bug in the quotation engine is three manual patches; a security fix is
three. After roughly eighteen months the forks cannot be merged. What remains is not a product
but several consulting engagements sharing an ancestor.

## Decision

Stable core plus per-customer Composer packages, with variation handled at the cheapest
mechanism that works:

| Rung | Mechanism | Handles |
|---|---|---|
| 1 | Configuration | Tax rules, numbering, margin type, thresholds, terminology |
| 2 | Company-defined master data | Rate dimensions, UoMs, assemblies, workflow states |
| 3 | Custom fields | Extra fields — no migration, no deploy |
| 4 | Plugin package | Real behavioural change |
| 5 | Fork | Last resort. Should be rare enough to be notable. |

Core provides fixed extension points: events on every state transition, container-bound
contracts (`PricingEngine`, `CostingStrategy`, `NumberingScheme`, `ApprovalPolicy`,
`TaxResolver`), pipelines, runtime custom fields, and config-driven workflow states.

Frontend variation uses monorepo composition — `packages/core-ui` plus per-customer apps — not
a runtime plugin registry.

## Consequences

- **Positive:** Bug fixes and security patches propagate via `composer update`.
- **Positive:** "What is running on this customer's server" has an exact answer.
- **Positive:** The event-based module boundaries are exercised constantly by core itself, so
  the extension mechanism is not untested plugin-only scaffolding.
- **Negative:** Roughly **15–25% added cost to the initial build**. Events, interfaces and
  custom fields all cost time before returning anything. Repaid at the second customer.
- **Negative:** Real risk of over-abstraction — indirection added before any evidence of where
  variation actually occurs. **Mitigation: build seams only at the listed extension points.**
  Everything else stays concrete until a real second customer provides evidence.

## Alternatives considered

**Fork per customer** — fastest for customer one, and the original proposal. Rejected for the
divergence reasons above.

**Configuration only, no plugins** — attractive, but some variation is genuinely behavioural
(a different pricing algorithm) and cannot be expressed as settings without building a
configuration language, which is worse.

**Full plugin marketplace architecture** (WordPress-style hooks everywhere) — rejected as
premature. Extension points are deliberately limited to identified seams.

## Related

- [architecture/extensibility.md](../architecture/extensibility.md) — implementation detail
- [0009 — Company-defined rate dimensions](0009-company-defined-rate-dimensions.md)
- [Q8 — Second customer profile](../OPEN-QUESTIONS.md#q8--second-customer-profile)
