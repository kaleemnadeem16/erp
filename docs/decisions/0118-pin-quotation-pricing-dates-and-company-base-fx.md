[← Decisions](README.md)

# 0118 — Pin quotation pricing dates and company-base FX

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R4 F1/F2, Sales pricing and quotation financial evidence.

## Context

The owner approved F1–F8 after reviewing local `5d43bea`. R3 deliberately reserved date,
conversion and precision seams. Existing manual rates can be edited or deleted; today's lookup
cannot explain a historical offer. General latest-prior FX wording has no quotation age bound.

## Decision

Use an explicit pricing date, defaulted to the current company-local date on creation or deliberate
reprice. Keep pricing, issue, expiry and tax-determination dates distinct. Commercial effective
periods are date-only half-open `[start, end_exclusive)` under the captured central company time
zone; observations retain UTC timestamps. A UI end date inclusive through 31 October stores
1 November exclusive. Relevant date passage or changed dependencies require deliberate draft
review, never silent repricing. R5 defines exact issue/revision checks; backdating cannot evade
expired agreement or policy eligibility. Issued evidence remains immutable.

Use one quotation currency, a separate source-price currency, and company-base dual amounts.
Cross-currency source prices must explicitly permit conversion. Pin a company-base bridge:
source→base and quotation→base SPOT legs at the same requested date; source/quotation conversion
is their ratio. Identity legs are exact one with explicit identity provenance. Calculate with
higher precision without prematurely rounding the bridge or ratio. No arbitrary direct/inverse/
cheapest route, provider fallback or implicit fixed-agreement currency conversion.

Reuse manual exchange-rate infrastructure. Exact-date is the default (maximum age zero).
Governed company configuration may permit latest-on-or-before rates within a bounded age;
retain each leg's actual date, age, value, source, pair/type, policy and route. Missing, over-age,
non-positive or unsupported conversion refuses; it is never one or zero by substitution.
Snapshots remain meaningful if master rates change or disappear. Relevant draft rates changing
require review. A Party's preferred currency is a default, not an account-creation requirement.

This refines quotation rate selection under 0012/0031/0081; it does **not** alter Purchasing,
accounting or forecast FX selection. ADR 0102's forecast valuation remains a separate fact.
Maximum prior age is configuration, not a universal business constant.

## Consequences

- **Positive:** Reproducible converted prices and unambiguous effective periods.
- **Negative:** Missing dated rates can block a quotation; carry-forward needs deliberate setup.
- **Requires:** Scoped Sales resolver, immutable provenance, current eligibility checks and R5/R9
  lifecycle/authority integration. Architecture acceptance gives no implementation authority.

## Alternatives considered

**Live today / unlimited prior / arbitrary route** — silently changes eligibility and value.
**Same-currency only forever** — unnecessarily excludes expressly convertible commercial terms.
**Global FX behaviour rewrite** — affects unrelated accepted Purchasing/accounting workflows.

## Research basis

Reuse [R4 evidence](../phases/phase-4-r4-evidence-and-readiness.md), accessed 2026-10-12:
ERPNext dated/stale exchange configuration and Business Central currency settings, reconciled with
manual rate source inspection. Shared pattern is explicit documentary currency/rate context;
provider fallback and vendor defaults are not imported. The pinned base bridge fits dual amounts.

## Related

- [R4 review](../phases/phase-4-r4-financial-review.md) · [Financial contract](../domain/quotation-financial-calculation.md)
- [0012](0012-multi-currency-dual-amounts.md) · [0031](0031-manual-exchange-rate-entry.md)
- [0081](0081-central-company-profile-and-company-wide-settings.md) · [R4 slices](../phases/phase-4-r4-implementation-slices.md)
