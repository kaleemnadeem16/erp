# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

None currently. Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs.

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q9 — Exchange rate source: manual entry, an API, or both
**Blocks:** Nothing in Phase 0 — the `exchange_rates` schema already has a `source` column that
accommodates either. Blocks the actual rate-population mechanism in Phase 1+.

[multi-currency.md](domain/multi-currency.md) documents `source` as "manual, or an API name" but
no ADR picks one. Manual entry needs no third-party dependency but is an ongoing operational
burden; an API (e.g. a free-tier FX rate provider) needs an API key, a rate limit to respect, and
a scheduled job, but removes the manual step. A hybrid — API-fetched with manual override — is
also plausible. Whichever is chosen shapes whether Phase 1+ needs a `FetchExchangeRates` job/
console command at all.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
