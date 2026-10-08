[← Roadmap](README.md)

# Physical package balances — design and delivery

Started 2026-10-03 under accepted ADR 0052. **Status (2026-10-08): grouped package tracking v1 is complete** — see the [handoff](package-balance-handoff.md) and the
[v1 sign-off](package-balance-ui.md#v1-sign-off-2026-10-08). The operational semantics were decided and built by accepted ADRs 0090–0094;
[ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md) (the early proposed refinement whose principles they adopted) was never accepted or superseded —
an owner decision, see [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md). The owner confirmed counts of unopened packages plus combined open/loose quantity are sufficient. This plan extends
the delivered purchasing package masters; it is not Phase 5 job execution. The scope table below is the original acceptance set and is kept as design history.

## Scope and examples

| Case | Required outcome |
|---|---|
| Receive ten sealed 2 L boxes | 10 boxes, 0 L loose, 20 L ledger |
| Unpack one box of 20 sealed 100 mL bottles | 9 boxes + 20 bottles; still 20 L |
| Open one of those bottles | 9 boxes + 19 bottles + 0.1 L open/loose; still 20 L |
| Draw 0.05 L through an authorised stock issue | Same sealed counts; 0.05 L loose; 19.95 L ledger; normal valuation |
| Transfer two sealed boxes and 0.05 L loose | Both locations update the exact composition atomically; total unchanged |
| Same-content alternative box | Separate count, even if both boxes contain 2 L |
| Delivery contains opened/damaged packages | Record observed composition independently of the commercial price basis |
| QA holds a receipt | Held sealed/open evidence is physical stock but not usable; partial release needs attribution |
| Enable tracking for existing 20 L | Counted decomposition must total exactly 20 L; no inferred sealed count |
| Count finds 19.8 L | Existing authorised count adjustment first; activation against the reconciled result |
| Retry or two simultaneous opens | Exactly one effect per request; cannot open the same last package twice |
| Reverse or correct after a later open/transfer | Validate dependencies; preserve both stock and package history; no scalar-only reversal |
| Definition archived after receipt | Historical contents and on-hand identity remain readable/operable |
| Finer-than-ledger content or unsupported tracking identity | Explain and refuse unsupported physical tracking; do not round/fabricate evidence |

## Delivery sequence

**Where to resume:** [package-balance-handoff.md](package-balance-handoff.md) — it lists what v1 includes, the known limitations and the future enhancements. This section records how
the original ten steps ended.

1. [x] Review ADRs 0052/0053/0080 and the current posting/read paths; compare primary ERP sources.
2. [x] Confirm grouped leftovers versus individually tracked vessels with the owner (grouped; vessels are the future container-level mode).
3. [x] Record the physical semantics, acceptance cases, integration obligations and exclusions (ADRs 0090–0094).
4. [x] Compatibility spike and executable contribution contract — [evidence](../domain/package-balance-integration.md).
5. [x] Inactive checkpoint kernel and creation-time policy foundation — [policy](../domain/package-tracking-policy.md).
6. [x] **Operational integration**: observed receipt, saved open/unpack, grouped issue and transfer (ADR 0090), activation with fail-closed enforcement and package-aware receipt
   (ADR 0091), physical count (ADR 0092), receipt reversal (ADR 0093) and supplier QA (ADR 0094) — [operations](../domain/package-issue-operations.md). Every direct writer is
   integrated or refused, and the database re-proves it.
7. [x] **Permissions and workflows**: `inventory.package-state.*` capabilities enforced at request and Action, rollout migration, role recipes, reason codes, screens
   ([package-balance-ui.md](package-balance-ui.md)).
8. [x] **Activation for existing stock**: counted opening state, stale detection, atomic validated transition (ADR 0091); no deactivation (future).
9. [ ] **Advisory proposals** — extending the ADR 0053 allocator with exact sealed/open evidence for the Material Preview. **Not built;** a future enhancement that is not in the
   handoff's v1 scope. Owner to prioritise.
10. [x] **Demo and journey in each enforcing slice**: create-once demo seeders and Playwright specs exist; the [user journey](../journeys/grouped-package-tracking-journey.md) was added in the 2026-10-08 reconciliation.

No slice may advertise exact operational package balances while an enabled writer can bypass them.
A reversible test spike may precede accepted detail; it must not mutate production semantics.

## Existing integration boundaries

| Boundary | Responsibility for this feature |
|---|---|
| Inventory `PostInventoryMovement` and receipt/issue/transfer collaborators | Sole quantity/valuation posting boundary; package changes share its transaction |
| Purchasing receipt/post/reverse and quality disposition Actions | Frozen agreement versus observed form; QA and reversal provenance |
| Inventory physical count and cutover Actions | Counted decomposition, authorised quantity variance and opening checkpoint |
| Inventory recalculation/backdating Actions | Dependency and replay compatibility; no unsupported one-sided correction |
| Inventory `QueryStockAvailability` | Consistent package/quantity snapshot, usability and held-stock attribution |
| Estimating material preview/allocator | Read-only choice and cost evidence; no stock post or reservation |
| Item/category/company policy | Persist resolved mode and govern transitions under ADR 0052 |

## First-release limits

No identity for each opened vessel; no mixing across traceability/availability scopes. No automatic
resealing/repacking, mixed-item handling units, refill manufacturing, catch weight, reusable-vessel
ownership, expiry-policy engine or package reservation. Phase 5 still owns job issue/return/scrap
workflows; this feature only makes their eventual stock boundary package-consistent.

## Related

- [Screens and API design](package-balance-ui.md)
- [Inventory packages](../domain/inventory-packages.md)
- [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md)
- [ADR 0082 (still proposed — see note above)](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
- [Package feature handoff](package-balance-handoff.md)
- [Purchasing delivery](item-purchasing-improvements.md)
- [Stock](../domain/stock.md)
- [Research rules](../architecture/research-and-design.md)
- [Demo rules](../architecture/environments.md#development-demo-data)
