[← Journeys](README.md)

# Govern a quotation concession

- **Implementation status:** implemented backend; configuration and evidence screens built, gated off outside local development
- **Audience:** configuration holder, preparer, independent reviewer and issuer
- **Starting point:** the versioned configuration and quotation APIs
- **Successful end state:** a permitted quotation is issued with pinned evaluation/approval evidence
- **Last verified:** 2026-11-09

## Purpose

Screens exist for configuration (Settings > Discount authority: policy versions, activation, named assignments and revocation, read-only company mode), price references (propose, then independent approval), capturing a draft's approved references from the issue screen, and the protected history of recorded results per quotation and for the result on the quotation issue and approval screens (state, approver needed, reasons, request expiry). They follow the quotation UI gate, so they open only in local development, and the company mode is not changed from any screen. Duties remain STAGED and no real holder
is granted access by this milestone. See the [API contract](../domain/quotation-discount-authority-api.md).

## Before you start

The company needs its base currency, pinned quotation FX, approved source references and reviewed
policy/bands. Tax and mandatory financial controls must independently pass. Named holders need
explicit current duties and family scope; this document grants or activates nothing. Use only the
labelled demo fixtures for demonstration until the owner approves real configuration.

## Journey map

```text
Approve source → assign authority → prepare/bind → routine clearance or independent review → issue
```

## Steps

1. A named configuration holder proposes a governed source, with the customer/product/measure,
   effective dates and reason. Another authorised reference reviewer approves it independently.
2. The configuration holder publishes and activates a versioned policy and assigns each named
   employee a band, purpose and optional role/unit. Real values await owner approval; demo values
   are explicitly DEMO ONLY. OFF remains the default; SHADOW records findings without clearance.
3. A preparer saves the quotation and captures its governed family references. Missing/incompatible
   evidence refuses clearance; splitting or revising cannot reset an established denominator.
4. In ENFORCE, readiness compares the concession entrant's current assignment with each independent
   ceiling. Routine clearance is recorded evidence, never a self-approval. Every override or
   undeclared reduction still needs independent review; other financial/tax controls remain binding.
5. The preparer requests approval for the exact generation. Routing uses the evaluated concession
   in pinned base currency, the lowest qualified configured rank, and effective shared policies.
   Equally ranked policies refuse. No eligible independent approver means no clearance.
6. A qualified reviewer sees protected evidence only with its separate evidence duty and family
   access. Creator, concession entrant and requester cannot decide their own exception. Each
   decision repeats source, membership, duties, assignment and expiry checks under common locks.
7. An edit invalidates approval. Expiry is enforced at the deadline, including after final approval;
   resubmitting an expired pending request creates fresh evidence and a fresh deadline. Revocation
   or suspension cannot be bypassed with a cached actor or an old decision object.
8. The issuer independently passes current issue access, readiness, tax and mandatory controls.
   Issue freezes the evaluation and approval evidence with the customer original. Retrying returns
   the original receipt only after current access is checked; issued history is never recomputed.
9. Configuration and evidence readers can inspect paged history within their independent duties
   and record scope. Disabling the mode preserves that history and restores no missing permission.

## Related

- [Domain specification and screen handoff](../domain/quotation-discount-authority.md)
- [API and rollout](../domain/quotation-discount-authority-api.md)
- [Approvals journey](approvals-journey.md)
