[← Journeys](README.md)

# Record full customer acceptance

- **Status:** backend implemented, all duties STAGED; no acceptance screen or operational activation.
- **Audience:** separately reviewed sales acceptance approver; future UI implementer.
- **Starting point:** available issued quotation, matching customer decision component and current verified written observation.
- **End state:** immutable full-scope consent and conserved claims; operational readiness stays pending.
- **Verified:** 2026-11-11, focused backend/API and two-session tests; no production use.

## Sequence

1. Record the message through the [intake journey](customer-messages.md). Recording it does not protect the offer.
2. Select one full independent issued scope and its matching component/evidence. Send their identities and current case/claim generations to acceptance-preview.
3. Review exact description, quantity, UoM and issued commercial terms; acknowledge unchanged terms. A missing delivery record is a warning, not a consent blocker.
4. Record the same intent with the returned actor-bound ten-minute token and a fresh Idempotency-Key. Keep that key/intent on a network retry.
5. Read the acceptance and its readiness. Consent is recorded; no Job, stock or finance operation has happened. Service handover needs its separately delivered adapter; goods have no Order target.

## Refusals and retries

A changed case or review needs reload/review. A claimed scope/group cannot be accepted again;
no competing identity is disclosed. An expired/withdrawn/replaced offer needs a new lawful offer,
not a smaller quantity or edited acceptance price. Late recognition is deferred.
An exact recorded request replays even after its review expires, but only for a person who still
has current authority and independent source read. A suspended or duty-stripped actor is refused.
A different request under the same key conflicts. Another authorised person using matching
original consent returns the same business identity, never a second acceptance.

## Related

- [Backend contract and gates](../domain/sales-full-acceptance.md)
- [Design brief and provisional defaults](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
