[← Architecture](README.md)

# Invariants

**Read this every session.** These rules are cheap to hold and expensive-to-impossible to
recover once broken. Each one has been chosen deliberately; the reasoning is in the linked ADR.

Ordered by how painful violation is.

---

## 1. Snapshot on issue

**When a document is issued, copy the fully resolved figures into it.**

A quotation stores its complete cost breakdown — every component, quantity, unit cost, labour
rate and FX rate as at that moment. Not a pointer to the assembly version. Not a reference to
current stock cost.

**Never recompute a historical document from current master data.**

> *Failure mode:* someone updates a pigment price. Every quotation ever issued silently changes
> value. Nobody notices for months, and by then the correct historical figures are gone.

→ [domain/assemblies.md](../domain/assemblies.md)

## 2. Composite unique constraints

**`unique(['company_id', 'code'])` — never `unique('code')`.**

Applies to every unique index on every business table, including where it feels unnecessary
today.

> *Failure mode:* enabling multi-tenancy later requires rebuilding every unique index against
> live data that already contains legitimate cross-company duplicates. The migration cannot be
> written safely, so it gets postponed forever.

→ [multi-tenancy.md](multi-tenancy.md) · [ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md)

## 3. Financial documents are immutable

Posted invoices and journal entries are **never** `UPDATE`d. Corrections are posted as
reversals.

Audit and legal requirement in most jurisdictions, and the basis of any trustworthy financial
report.

→ [domain/general-ledger.md](../domain/general-ledger.md) · [ADR 0011](../decisions/0011-double-entry-open-item-ar.md)

## 4. Versioned records are immutable once used

Formulation versions and assembly versions are frozen the moment any document references them.
Changes create version N+1 with a new `effective_from`.

> *Failure mode:* editing a recipe in place silently rewrites the cost basis of every historical
> job that used it.

→ [domain/formulations.md](../domain/formulations.md)

## 5. Both currency amounts, always

Every monetary row stores the transaction amount **with its currency and pinned FX rate**, and
the base-currency amount computed at that rate.

Never recompute a historical `base_amount` from a current rate.

→ [domain/multi-currency.md](../domain/multi-currency.md) · [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md)

## 6. Money is `decimal(19,4)`, never float

Binary floating point cannot represent `0.10` exactly. Accumulated over a cost roll-up, the
error is visible in a total that should be exact.

Applies to fixtures and examples too — a float in a test is a float that gets copied into
production code.

→ [database.md](database.md) · [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md)

## 7. Company scoping is not optional

`company_id` on every business table. Cache keys and file paths namespaced by company. No ID
enumeration outside the scoped model layer.

> *Failure mode:* cross-tenant data leakage — the worst class of bug this system can have.

→ [multi-tenancy.md](multi-tenancy.md)

## 8. Gapless document numbering

Invoice and document numbers come from a `sequences` table using `lockForUpdate()`.

**`MAX(id) + 1` produces duplicates under concurrent load.** This is not theoretical.

→ [domain/receivables.md](../domain/receivables.md)

## 9. Margin is not markup

`cost × (1 + 0.20)` = 120. `cost ÷ (1 − 0.20)` = 125.

`margin_type` is stored explicitly on every assembly and shown in the UI. Never assume which one
a user means.

→ [domain/assemblies.md](../domain/assemblies.md)

## 10. Consumption basis is not pricing basis

250 sqm of wall at 2 coats consumes 500 sqm of material but is priced per sqm of wall.

The component `multiplier` handles consumption; the document line quantity handles pricing.
Conflating them makes every estimate wrong by the coat count.

→ [domain/assemblies.md](../domain/assemblies.md)

---

## Shared infrastructure constraints

Softer, but they shape design. Production runs alongside three other applications on
[shared VMs](environments.md):

- **Namespace every Redis key.** The instance is shared with invoiceflow and crm. A collision
  would be very hard to diagnose.
- **dbvm has 1 vCPU.** Index deliberately; `EXPLAIN` anything touching a table expected to
  exceed ~100k rows. A slow query degrades other products, not just ours.
- **Never point tests or migrations at production databases.** CI uses service containers.
- **Images are `linux/amd64` + `linux/arm64`.** Dev is x86, production is ARM — never hardcode
  an architecture ([ADR 0021](../decisions/0021-multi-architecture-images.md)).

## Related

- [database.md](database.md)
- [multi-tenancy.md](multi-tenancy.md)
- [Decision index](../decisions/README.md)
