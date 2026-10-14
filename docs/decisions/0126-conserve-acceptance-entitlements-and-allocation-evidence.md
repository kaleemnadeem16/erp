# 0126 — Conserve acceptance entitlements and allocation evidence

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** Phase 4 Sales customer decisions; R5/R7–R9 integration

## Context

Owner approval of the final C1–C8 review under planning Q46 settles customer acceptance architecture.
Issued quotation, customer agreement and operational targets must retain distinct immutable evidence.
This records business architecture only; it authorises no application implementation.

## Decision

C4 is accepted. Preserve four separate facts: original quotation allocation; original entitled
consumption; newly agreed commercial amounts; later statutory determination. Original issued and
customer-agreed evidence is immutable. Never silently change agreed price, tax estimate or conditions.

Supported partials consume applicable original benefits/components cumulatively, with original
scope anchors, explicit eligibility, caps, stable residual policy and serial claim sequence. For a
proportionate entitled budget B, eligible quantity Q and cumulative consumption q, compute E(q)
under the pinned rounding policy; this acceptance consumes E(q_new) minus E(q_old), never independent
rounded fractions. Conserve each component and original budget; receipts do not advance consumption.
The consented amount must match the exact allocated quantum before authoritative recording.

Do not presume every fixed discount or tax is proratable. Fixed commercial entitlement needs an
explicit offered rule. Fixed tax needs supported assessment scope; historical attribution is not a
new legal assessment. New benefit or newly assessed tax needs separate appropriate authority and
evidence; new revision IDs do not reset shared benefit budgets or create statutory duties.
Preserve R4 component dependencies, separate withholding, quote/base FX and computed-versus-allocated
base evidence. Quotation tax is an agreed estimate, not a Finance statutory determination.
No new document-level discount input is authorised. Unsupported calculations fail closed.

## Consequences

- **Positive:** real customer commitments remain attributable without invented terms or duplicate sales.
- **Negative:** supported acceptance requires explicit evidence and coordinated claims; ambiguous or
  unsupported cases stay pending/refused rather than being guessed into a successful transaction.
- **Requires:** the [domain contract](../domain/sales-customer-decisions.md), staged
  [delivery specification](../phases/phase-4-r6-implementation-slices.md), separately approved R7/R9
  dependencies and code authorisation before delivery. Documentation is not implementation verification.

## Alternatives considered

Round each slice independently — may spend0.04 from an original0.03 benefit.
Prorate every fixed charge/tax — invents commercial or statutory entitlement.
Recalculate accepted evidence using current rates — destroys historical agreement.
Force new assessments into original budgets — confuses consumption with a separately authorised fact.

## Research basis

Reuse the [final C1–C8 comparison and accepted foundation](../phases/phase-4-r6-customer-decisions.md),
[allocation examples](../phases/phase-4-r6-allocation-and-journey.md) and
[evidence/claims research](../phases/phase-4-r6-evidence-and-claims.md), completed2026-10-14.
The linked R5 primary comparison (Dynamics frozen quote/order conversion and ERPNext remaining-quantity/
alternative mapping, accessed2026-10-12) supports separate offer, decision and target identities.
It does not supply our consent, entitlement or statutory rules; those follow the explicit owner approval.
No settled R1–R5 choice is reopened.

## Related

- [Decision index](README.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
- [Quotation lifecycle](../domain/quotation-lifecycle.md)
