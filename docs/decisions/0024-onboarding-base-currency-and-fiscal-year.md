[← Decisions](README.md)

# 0024 — Base currency and fiscal year set at company onboarding, locked after first use

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** Phase 1, [domain/multi-currency.md](../domain/multi-currency.md)

## Context

[Q2](../OPEN-QUESTIONS.md) (base currency, previously assumed PKR) and Q4 (fiscal year start
month, July vs. January) were both open. They share the same shape of problem: cheap to set
before real data exists, expensive or risky to change after — base currency because
[ADR 0012](0012-multi-currency-dual-amounts.md) pins every historical `base_amount` at the rate
in force when posted and never recomputes it; fiscal year because it defines the accounting
period boundaries reports and period closes depend on.

The product is also intended to serve more than one company eventually
([ADR 0010](0010-core-plus-plugins.md)), so hardcoding either value for Pakistan specifically is
wrong on its face.

## Decision

Both are collected in a first-run company setup step (Phase 1 — company/master-data setup), not
hardcoded and not assumed:

- **Base currency** defaults to **USD**, freely editable during setup. It **locks once the first
  financial transaction posts**, per the immutability rule already established in
  [ADR 0012](0012-multi-currency-dual-amounts.md).
- **Fiscal year start month** defaults to **July**, freely editable during setup. It locks once
  the first accounting period is closed.

Multi-currency *transaction* support (invoicing or receiving in a currency other than the base)
remains in scope per ADR 0012, but does not need to exist for the initial build — it can land in
a later phase without touching this decision.

## Consequences

- **Positive:** resolves Q2 and Q4 without hardcoding either value, and matches the eventual
  whitelabel direction — different companies may need a different base currency or fiscal
  calendar.
- **Negative:** setup flow now carries state that gates later behaviour; the UI needs a clear,
  visible "locked" indicator and why, so it isn't mistaken for a bug.
- **Requires:** a setup step in Phase 1's company/master-data work, and a guard rejecting changes
  to either field once its lock condition is met.

## Alternatives considered

**Hardcode PKR / January** — rejected. Wrong for a whitelabel product serving companies outside
Pakistan or on a different fiscal calendar, and contradicts the multi-company direction already
chosen.

**Allow changing base currency at any time** — rejected. ADR 0012 already established that
historical `base_amount` is never recomputed from a current rate; an unrestricted change would
silently corrupt reporting the moment real transactions exist.

## Related

- [ADR 0012 — Multi-currency with dual amount storage](0012-multi-currency-dual-amounts.md)
- [domain/multi-currency.md](../domain/multi-currency.md)
- Q2 — Base currency, Q4 — Fiscal year start month *(both resolved, removed from open questions)*
