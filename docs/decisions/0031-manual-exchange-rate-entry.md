[← Decisions](README.md)

# 0031 — Exchange rates populated by manual entry only

- **Status:** accepted
- **Date:** 2026-08-19
- **Affects:** Phase 1, [domain/multi-currency.md](../domain/multi-currency.md)

## Context

This was open question Q9. The `exchange_rates` table (built in Phase 0, schema only) already
carries a `source` column that accommodates either manual entry or an API-fetched rate, but no
ADR had picked one. Phase 1 is the phase that actually needs to populate rates, so the question
can no longer stay open.

## Decision

Manual entry only, for now. No `FetchExchangeRates` job or console command, no third-party FX API
integration, no scheduled job. Rates are entered directly into `exchange_rates` by a user, or via
the existing generic column-mapping import mechanism
([ADR 0027](0027-generic-column-mapping-import.md)).

## Consequences

- **Positive:** No third-party dependency, no API key to manage, no rate limit to respect, no
  scheduled job competing for cycles on dbvm's single vCPU. Nothing to build beyond the schema
  (already done) and a basic entry point.
- **Negative:** Ongoing manual operational burden — someone has to remember to enter rates, and a
  missed update silently leaves the last-entered rate in effect with no staleness warning.
- **Requires:** Every row created this way must record `source = 'manual'` consistently, so a
  later API integration can distinguish manually-entered historical rates from API-fetched ones
  without ambiguity.

## Alternatives considered

**API-driven (scheduled fetch from a free-tier FX provider)** — rejected for now. Adds an
external dependency and operational surface for a system that is currently single-organisation
with no live transaction volume. Can be added later without a schema change, since `source`
already accommodates it.

**Hybrid (manual entry plus optional API fetch with override)** — rejected for now as more than
Phase 1 needs. Revisit once there is real multi-currency transaction volume to justify it.

## Related

- [domain/multi-currency.md](../domain/multi-currency.md)
- [ADR 0027](0027-generic-column-mapping-import.md) — the import mechanism manual rate entry can
  reuse
- [ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md) — base currency decision this
  builds on
