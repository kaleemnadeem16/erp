# 0143 — Maintain currency minor units as governed reference data

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Currency module, deployment workflow, demo seeding, [multi-currency.md](../domain/multi-currency.md), [precision.md](../domain/precision.md)

## Context

`currencies.decimal_places` exists but nothing outside the demo seeder creates currencies: `FoundationDemoSeeder` inserts PKR, USD and AED, the API is
read-only, and the development database holds exactly those three with no exchange rates. A currency's minor unit is a fact about the currency, not a company preference, and it
is the default settlement quantum used by the Sales kernel. Other products block reducing it once used in accounting entries. Production Company 1 holds demo data and
`demo:seed` must not run there ([0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)), so production needs its own setup path.

## Decision

1. **Reference data, not company configuration.** Currencies are a global ISO 4217 dataset kept in the repository (code, name, minor unit). No unrestricted runtime currency
   administration and **no custom currencies** in the first slice.
2. **Idempotent deployment-time synchronisation** run by the authorised deployment operator. It inserts missing currencies and may refresh descriptive fields. It
   defaults to a dry run that reports what it would do, and applying is an explicit act.
3. **A used currency's minor unit is never changed by synchronisation or any company setting.** A currency is *used* when any foreign key referencing `currencies` has a row,
   detected from database foreign-key metadata so a new module cannot forget to register. A dataset change that would alter a used currency is reported, not applied, and needs
   an explicit recorded review before any exceptional action.
4. **Audited.** Each run records operator, time, dataset version and the inserts, refreshes and refused changes in the deployment log and the activity log.
5. **Separate from demo.** Production reference setup is a different path from demo business seeding. The demo seeder may call the same reference dataset but owns no reference rules.
6. **Settlement differences are not a currency edit.** A company that settles to a different quantum uses its rounding policy ([0144](0144-version-sales-unit-price-precision-within-four-place-storage.md), FR4), never the currency record.
7. **Supported range.** Minor units from zero to four places (the storage limit). A dataset entry outside that range is refused at synchronisation, not stored.

## Consequences

- **Positive:** correct JPY/KWD-style behaviour with no per-company edit path; a used currency cannot drift.
- **Negative:** a deploy-time step and an ISO dataset to maintain.
- **Requires:** the currency reference slice; deployment documentation; production Company 1 reconciled by an operator-run dry run first.

## Alternatives considered

**Company-editable currency precision** — rejected: it would let a screen change the meaning of money already stored.
**Seed currencies only in the demo seeder** — rejected: production has no path.
**Migration-seeded currencies** — rejected: reference changes need operator review and a dry run, which a migration does not give.
**Custom currencies now** — rejected: unneeded and unsafe before an owner use case.

## Related

- [0012](0012-multi-currency-dual-amounts.md) · [0024](0024-onboarding-base-currency-and-fiscal-year.md) · [0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md) · [0141](0141-layer-precision-as-invariants-versioned-policy-and-presentation.md)
- [multi-currency.md](../domain/multi-currency.md) · [precision.md](../domain/precision.md) · [slices](../phases/precision-implementation-slices.md)
