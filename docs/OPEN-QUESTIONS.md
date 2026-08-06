# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

### Q1 — Retention / retainage
**Blocks:** Phase 6 (Finance) · **Raised:** 2026-07-29

Do customers withhold a percentage (typically 5–10%) of each invoice until a defect liability
period ends? Standard practice in contracting.

If yes, retention needs its own control account, a release schedule, and must be **excluded
from normal aging** — retained amounts are invoiced but not yet due. Schema columns are already
present in [receivables.md](domain/receivables.md) and cost nothing if unused, but the posting
logic and reporting are a painful retrofit.

**Needed by:** start of Phase 6.

### Q2 — Base currency
**Blocks:** Phase 1 (Master data) · **Raised:** 2026-07-29

Assumed **PKR** with foreign-currency customers. Needs confirmation.

The base currency is the GL's reporting currency and is effectively permanent — every
historical `base_amount` is computed against it. Changing it later means restating all history.

**Needed by:** first migration that touches money.

### Q3 — Tax model
**Blocks:** Phase 6 (Finance), partially Phase 4 · **Raised:** 2026-07-29

Not yet discussed. Needs answers on:

- Sales tax / GST / VAT — rates, and whether they vary by item or customer
- Withholding tax on customer payments (common in Pakistan for services)
- Tax on services vs goods — often different regimes, and this business sells services
- Input tax recovery on purchases
- Whether prices are entered tax-inclusive or tax-exclusive

Tax is invasive: it touches quotations, invoices, purchases and the GL. Getting the model
wrong is expensive.

**Needed by:** start of Phase 4.

---

## Non-blocking

### Q4 — Fiscal year start month
**Blocks:** Phase 6 reporting

July in Pakistan, January in many other places. Needed for accounting periods and reporting,
not for schema.

### Q5 — Localisation and RTL
**Blocks:** Phase 7 (or earlier if required)

Is Urdu (or any RTL language) needed in the UI? Affects the frontend layout approach — RTL is
much cheaper designed in than retrofitted, so worth answering before heavy UI work in Phase 4.

### Q6 — Data migration from an existing system
**Blocks:** Go-live, not development

Is there current data — customers, items, historical jobs, open balances — to import? Opening
balances in particular need a defined cutover approach.

### Q7 — User roles and permissions
**Blocks:** Phase 0 can proceed with a basic set; full definition needed by Phase 4

Who uses the system, and what may each role see and do? Estimator, site supervisor, storekeeper,
accountant, management are the likely set. Approval thresholds (e.g. quotes above X need
sign-off) fall out of this.

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
