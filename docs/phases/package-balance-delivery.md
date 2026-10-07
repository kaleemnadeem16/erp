[← Roadmap](README.md)

# Physical package balances — design and delivery

Started 2026-10-03 under accepted ADR 0052. [ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
is a **proposed** operational refinement. The owner confirmed counts of unopened packages plus
combined open/loose quantity are sufficient. Inactive evidence storage and enforcing read/open
capabilities have shipped; physical-tracking activation remains blocked. This plan extends the
delivered purchasing package masters; it is not Phase 5 job execution.

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

**Continuation checkpoint:** [package-balance-handoff.md](package-balance-handoff.md) records the
completed implementation, live demo/test baseline, shared-checkout boundaries and the exact next
issue/transfer work. Consult it before resuming this workstream; it does not replace project STATUS.

1. [x] Review ADRs 0052/0053/0080 and the current posting/read paths; compare primary ERP sources.
2. [x] Confirm grouped leftovers versus individually tracked vessels with the owner.
3. [x] Record proposed physical semantics, acceptance cases, integration obligations and exclusions.
4. [x] **Compatibility spike and executable contribution contract.** Existing writer audit,
   five real-Action cases and twelve allocation contract cases complete; combined 38 tests /
   180 assertions pass. [Evidence, contract and exclusions](../domain/package-balance-integration.md).
   NONE/BATCH AVCO/FIFO remain activation candidates; SERIAL/SPECIFIC, negative debt and
   backdated replay need enforcing adapters or guards. This completes the reversible spike,
   not operational package integration. ADR 0082 remains proposed; kernel work must preserve
   its disabled-by-default boundary and explicit activation gates.
5. [x] **Inactive checkpoint kernel and policy foundation.** Company-safe immutable evidence,
   exact contribution constraints, counted-evidence posting, equal-payload retries, request/
   location/layer locks and on-demand history reconstruction are implemented.
   [Creation-time policy resolution](../domain/package-tracking-policy.md) pins sources and
   enforces QUANTITY_ONLY through model/database gates. No activation or live package balance
   is advertised. Operational event replay/posting belongs to step 6; transitions and relaxation
   of the gates require the later enforcing/reconciliation slices.
6. [ ] **Operational integration.** [Observed receipt backend](../domain/receipt-package-evidence.md)
   is implemented with source-layer/QA evidence and posting rollback/retry tests. It has no
   authoring/history UI yet and cannot activate tracking. [Opening/unpacking preview](../domain/package-opening-preview.md)
   now calculates exact layer-preserving proposals and has a dedicated counted demo.
   [Saved open/unpack successors](../domain/package-opening-operations.md) now persist complete
   composition with lineage, locking, retries and a separate history demo. Tracking stays inactive.
   [Grouped package-aware issue](../domain/package-issue-operations.md) (ADR 0090, 2026-10-07) now posts the stock issue with its allocation
   vector and writes `ISSUE` evidence atomically. Grouped package-aware **transfer** (two evidence legs) followed the same day. **Activation and fail-closed enforcement** (ADR 0091) and the package-aware **receipt** followed on 2026-10-08. Remaining: composition-only count correction; QA, reversal, cutover
   and recalculation adapters or explicit first-release guards; refusing scalar issues once a mode is authoritative (activation slice). Cover every direct Action writer,
   not only public HTTP controllers. Preserve QUANTITY_ONLY behaviour.
7. [ ] **Permissions and workflows.** Register only enforcing capabilities; grant demo personas
   and include administrator assignment instructions. Requests and Actions both check authority.
   Add Inventory package-state/history views and actionable errors; hide cost on the server.
8. [ ] **Activation for existing stock.** Counted opening-state document, stale-stock detection,
   governed reason/actor, atomic reconciliation checkpoint and supported mode changes.
9. [ ] **Advisory proposals.** Extend the existing read snapshot and ADR 0053 allocator with exact
   sealed/open evidence, stable tie-breaks and freshness. Preserve no-reservation semantics and
   explain unavailable criteria; never claim expiry/FEFO without recorded expiry.
10. [ ] **Demo and user journey in each enforcing slice.** Small named examples, real posting
    Actions, create-once immutable fixtures and rerun preservation tests. Validate the live user
    step without a hidden backdating/approval prerequisite. Update STATUS and commit each slice.

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

- [Inventory packages](../domain/inventory-packages.md)
- [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md)
- [Proposed ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
- [Purchasing delivery](item-purchasing-improvements.md)
- [Stock](../domain/stock.md)
- [Research rules](../architecture/research-and-design.md)
- [Demo rules](../architecture/environments.md#development-demo-data)
