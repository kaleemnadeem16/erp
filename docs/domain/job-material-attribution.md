[← Domain index](README.md)

# Job material attribution

**ACCEPTED 0148 / Q53 resolved, 2026-10-16; bounded contract SPECIFIED, not implemented.**
Job material demand expresses need; Inventory owns physical custody, posting and authoritative value.

## Where it sits

R7 baseline/Job demand → advisory availability → Inventory custody/actual issue → Job attribution.
Inventory correction → 0040 participant → linked Job valuation adjustment. R7/R9/0145 remain governing.

## Logical evidence — no migration schema

Demand retains company/Job/activity, authorised requirement/baseline revision, Item/UoM/quantity and
remaining-obligation disposition. Custody references actual Inventory locations/movements, never
another stock balance. Consumption links one stable source/component to actual issue/allocation,
quantity/UoM, occurrence/recording actor/date and original Inventory valuation/currency/FX basis.
Corrections append original/effective lineage, signed quantity OR value effects, reason/authority,
economic ownership and durable coordinated receipt. Job/activity projections are not extra costs.

## Supported-path boundary

| Mode | Initial candidate | Required gate |
|---|---|---|
| Fixed-content QUANTITY_ONLY, compatible AVCO/FIFO | Direct actual-use issue; supported custody transfer/back | Job source/authority/attribution and 0040 adapters |
| Fixed-content PACKAGE_BALANCE, compatible AVCO/FIFO | Authoritative grouped issue/transfer evidence | Fresh reconciled predecessor, exact draw/opening duties, allocation vectors and all Job/0040 adapters |
| Container identity/exact remnants/variable content | Deferred | Genuine identity/dual-quantity/preview/variance capability; Q23 preserved |
| Serial/SPECIFIC/unsupported valuation contexts | Deferred initial Job exposure | Explicit path verification and correction support |

Full [operation/mode matrix and CM01–18](../phases/phase-5-coverage-material-resolution.md)
remain required acceptance evidence. Advanced roadmap obligations remain path-specific gates.
Grouped tracking is not container identity. No scalar fallback, negative override or downgrade.
Manual supported draw must retain existing source/variance controls; no unsupported preview claims.

## Behaviour

Direct use of 2 fittings posts one issue and Inventory-valued cost, without site setup/transfer.
Transfer 12 for demand 10, consume 9, return unused 3: consumption is 9, remaining demand 1; transfers
are custody, not cost. Reallocation may retain stock at another supported company location.
Already-consumed return needs a separate condition/quantity/valuation contract; no automatic credit.
No manufacturing Work Order or parallel Job balance. Demand and availability are NOT RESERVED.
Revalidate live stock at posting; later exclusive reservations require Inventory-owned research.

Only ordinary company-owned stock is in the initial scope. Customer/free-issue, substitutions,
recoverable scrap, identified reusable remnants and returnable containers retain Q24 gates.
Refuse affected unsupported operations, not unrelated ordinary stock. No zero-cost ownership fiction.

## Valuation and corrections

Actual cost is authoritative Inventory valuation, never PO/quotation/preview estimates. Before ANY
consumed path activates, deliver required 0040 Job correction participant, economic ownership routing
and reconciliation. A 90.0000 value adjustment to 9 units originally valued 900.0000 makes 990.0000,
with quantity still 9. Preserve original allocation/package evidence and pinned historical money.
No duplicate movement, allocation or cost on retry. Physical correction is a separate governed event.
Required local participants commit atomically. Unsupported required downstream impacts fail closed
under accepted refusal/pending coordination; never partially apply required local effects then add a note.

## Authorisation and data scope

Job planning/demand, Inventory posting, both-end warehouse scope, package issue/opening and sensitive
cost reads are independent. Assignment confers none. Guard in Actions/Requests and on replay, history,
aggregates/export/errors. Final duty registration/holders remain delivery work; no grants introduced.

## Integrity, lifecycle and refusals

Prove same-connection transactions and compatible R7/Job/source/physical pool/package/correction
locks. Recheck current company/authority/scope, lifecycle, readiness, evidence and generations under
locks. All conflicting writers participate; unique tenant-safe stable business identities protect
absent anchors and fresh-key duplicates. Exact intent receipts reject changed keyed payloads.
Scalar source dedup alone lacks the full Job intent/authority guard. Grouped downstream hashes include
actor/predecessor: replay resolves original receipt without constructing a changed downstream request.

0145 hold/termination block ordinary new consumption. Govern legitimate historical evidence/corrections
separately. Closeout reconciles or explicitly disposes custody, demand, supported reservations and
required corrections through owning effects; unknown obligations cannot be waived by a note.
Closeout neither proves coverage nor settles Finance. Later corrections do not ordinarily reopen Jobs.

## Acceptance scenarios — not executed tests

- Direct use posts once without mandatory site; custody example never double-counts transfers.
- Concurrent Jobs see NOT RESERVED stock; locking prevents overconsumption.
- Stale grouped state/unsupported mode refuses without scalar fallback.
- Required 0040 participant absent: activation refused; coordinated failure rolls all local effects back.
- Repeated correction changes value once, no physical issue; changed intent conflicts.
- Held/cancelled/closed or permission-denied request cannot bypass through retry/internal poster.
- Custody/remaining demand/correction obligations unresolved: no unsafe closeout.
- Third-party/variable-content/consumed-return path refuses while ordinary stock remains eligible.

## Landmines

Purchasing for a Job is not proof of consumption. Stock in a vehicle remains stock. A correction
gate deferred until after first consumption leaves historical costs impossible to reconcile safely.

## Related

- [0148](../decisions/0148-bound-initial-job-material-paths-and-require-correction-delivery.md)
- [Inventory packages](inventory-packages.md) · [Stock corrections](stock-recalculation.md)
- [Lifecycle](job-execution-lifecycle.md) · [Coverage](job-cost-coverage.md)
