[← Product journeys](README.md)

# Try packaged purchasing with the demo dataset

**Audience:** buyer and receiver. **Implementation status:** local/testing demo, quantity-only stock.
**Last verified:** 2026-10-03 through the real purchasing/stock Actions and demo rerun tests.

The goal is to compare package identities and pricing, complete a partial delivery, and inspect
what happens when a supplier changes the agreement. Run the opt-in `demo:seed` command described
in [environment rules](../architecture/environments.md#development-demo-data); it now includes
`PackagedPurchasingDemoSeeder`. The examples are already loaded in the development database.
Sign in with the configured demo login, which receives the registered demo permissions.

## Start with the item and offers

Open Items and search **demo-pack-thinner**. Its name is **Thinner — named packages and
price-basis demo**. Item detail shows seven package definitions: 100 mL and 500 mL inner
bottles, two different 2 L boxes (20 small bottles versus four large bottles), a 5 L can,
a 200 L drum containing 40 cans, and an archived old box. The factors all refer directly to litres.

Nine offers demonstrate litre, package and per-100-litre pricing; loose buying; a preferred
offer with minimum/multiple five; a changed price; an alternative supplier; and expired,
future and inactive offers. Offer SKU labels start with **DEMO-** so they are recognisable.
PakChem supplies most examples; Industrial Coatings has the alternative supplier offer.

The **Supplier relationships** panel above the packages lists these two active suppliers and an
archived Punjab trial supplier. Use **Restore** to practise resuming supply, or **Edit** to change
its sourcing terms. **Add supplier relationship** fixes the current item and lets you select the
supplier, supplier item code, priority, lead time, minimum and default purchase unit. Existing
relationship identities cannot be repointed. Archiving a relationship prevents new offer-based
orders using it; issued agreement snapshots remain unchanged. A demo rerun preserves your
restoration or later edits.

## Compare the agreements

Purchase-order notes start with **[Pack demo:scenario]** and explain the expected result.
Search the Purchase Orders list for **price-basis demo**, then open an order and read its notes
to identify the scenario below. Number formats depend on the company's numbering settings, so numbers are
deliberately not hard-coded in this guide.

| Scenario in the notes | What to inspect or do | Expected result |
|---|---|---|
| `live-draft` | Edit the line; inspect the preferred offer, package and price-basis preview | 10 boxes × 2 L, 850/L: 20 L and 17,000 |
| `equal-content` | Compare the other named box, priced per box | Different package identity; same 20 L and 17,000 |
| `per-100-litres` | Inspect price denominator 100 | 20 L at 85,000 per 100 L: 17,000 |
| `loose-6-5-litres` | Inspect a loose line | 6.5 L at 850/L: 5,525; no package count |
| `can-and-drum` | Inspect the 200 L package on the draft and its inner 5 L cans on Item detail | One drum: 200 L and 170,000; left draft because normal approval rules still apply |
| `minimum-exception` | Read the saved exception reason | Three boxes remain three boxes; 6 L and 5,100 |
| `multiple-exception` | Read the reason for ordering seven boxes | Seven boxes remain seven boxes; 14 L and 11,900 |
| `partial-6-plus-4` | Six boxes are posted; find its draft Goods Receipt and post the remaining four | Before: 12 L received, 8 L outstanding. After: 20 L and order closed |
| `complete-6-plus-4` | Inspect both posted receipts | 12 + 8 L; frozen costs total 17,000 |
| `loose-receipt` | Inspect a loose receipt against a packaged agreement | 1.5 L received for 1,275; 2.5 L remains |
| `old-price-issued` / `future-price` | Compare the old issued line and the later draft | Old price 850/L and 8,500; future price 900/L and 9,000 |
| `archived-package-issued` | Compare issued evidence with the archived master on Item detail | Existing agreement still identifies the old 2 L package; future buying uses another definition |
| `unavailable-cancelled` / `unavailable-replacement` | Inspect cancellation reason and replacement reference | Old order cancelled before receipt; replacement chooses another 2 L box |
| `partial-short-closed` / `remainder-replacement` | Inspect the original receipt, short-closed quantity and replacement | Original 12 L received; only the remaining 8 L is replaced |

The draft receipt is created after the other seeded stock events so it can be posted directly.
If you later post newer stock events first, ordinary backdating controls apply; the seed command
preserves your progress rather than moving historical dates to hide the conflict.

## Try the rejected cases on the draft

On `live-draft`, try **0.5 box**: whole-box increments reject it. Try **seven boxes without a
reason**: the supplier multiple requires one. A reason permits seven; it does not round your
quantity. Inactive offers are hidden from new order entry; selecting an expired or future offer
fails the server preview/save for today's date. An offer for another supplier cannot be applied. A kilogram price basis for this litre-only item fails
because no fixed conversion exists. The tests exercise these rejected cases through the real
line builder; invalid documents are never inserted as demo fixtures.

## Reruns and limits

Rerunning `demo:seed` recognises these packages/offers/documents and skips them. It preserves
manual prices, posted receipts, closed/cancelled orders, immutable snapshots and unrelated local
records. This capability creates its definitions once because package content is immutable.

This demo shows named **purchasing** packages and receipt evidence. Inventory still records litre
balances; it does not claim to know how many sealed boxes or opened bottles physically remain.
Physical package balances, containers, catch weight, price tiers and configurable constraint
escalation remain later scope. Supplier relationships and offers can now be maintained on Item detail; the standalone Item
Suppliers screen remains available. Percentage discounts, tax-engine work and split
delivery scheduling are separate older roadmap items, not completed by this slice.

## Internal counted checkpoint example

demo:seed also creates demo-package-counted-checkpoint through RecordPackageCheckpoint.
This records a fictional physical count of 5 L cans plus loose contents on the demo thinner.
It is historical counted evidence, independent of receipt provenance; it does not activate
tracking or change ledger quantity/value. Its immutable stable key is skipped on reruns.

There is no checkpoint screen yet. Developer inspection can call ReplayPackageCheckpoint with
the operation ID and a user granted inventory.package-state.view. It returns counted composition,
exact totals and freshness reasons without cost fields or tracking activation. Older local
checkpoints show UNKNOWN_MOVEMENT_WATERMARK; newly seeded checkpoints record the watermark.
The module rerun grants demo view access while preserving the existing checkpoint. Scoped
PackageOperation, PackageState and PackageContribution models remain available for inspection. Do not present this checkpoint as current stock
after another receipt, issue or transfer. The [writer contract](../domain/package-balance-integration.md#authorised-counted-checkpoint-writer--2026-10-03)
records permissions, unsupported QA/serial/shortage cases and the remaining operational work.

## Creation-policy examples

Search the Items list for “Package policy” to find three non-stock examples: company choice,
nearest category choice and explicit item choice. They all remain quantity-only.
PackageTrackingPolicyDemoSeeder creates them through the normal creation Actions and preserves
manual edits/archives on rerun. Developer model inspection shows package_tracking_mode,
package_tracking_source and its selected ancestor reference; there is no advanced-mode control.
See [policy rules](../domain/package-tracking-policy.md) for precedence and activation gates.

## Observed receipt examples

Search Items or purchase-order lines for “observed receipt composition demo”.
The dedicated thinner has three posted orders, each two agreed 2 L boxes/4 L, priced at 850/L
(total 3,400): one sealed box plus 2 L loose, two actual sealed cans, and two QA-held boxes.
Orders carry the explanation. Actual arrival evidence is stored separately from the commercial
receipt package fields; there is no observation capture/history screen yet.

Developer inspection uses GoodsReceiptPackageObservation and source-linked PackageOperation /
PackageState / PackageContribution models. The [receipt contract](../domain/receipt-package-evidence.md)
explains authority and unsupported serial/debt cases. Reruns preserve immutable receipts;
this example does not enable physical tracking or change the earlier live draft's stock history.

## Related

- [Saved opening/unpacking history demo](../domain/package-opening-operations.md#demo-and-verification) —
  dedicated item ends with one carton, eighteen sealed bottles and 0.2 L open; still 4 L.
  Backend inspection only; tracking remains inactive.

- [Opening/unpacking backend demo](../domain/package-opening-preview.md#demo-and-verification) — dedicated
  two-carton fixture; inspect OPEN versus UNPACK proposals internally. No posting control or screen yet.

- [Phase 2 operational journey](phase-2-operational-journey.md)
- [Item and Purchasing delivery plan](../phases/item-purchasing-improvements.md)
- [Inventory packages](../domain/inventory-packages.md)
- [ADR 0080](../decisions/0080-packaged-purchasing-and-independent-price-basis.md)
- [Demo environment rules](../architecture/environments.md#development-demo-data)
