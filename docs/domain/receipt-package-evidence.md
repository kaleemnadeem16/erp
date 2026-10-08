[← Domain index](README.md)

# Observed receipt package evidence

> **Historical foundation (marked 2026-10-08).** This is the original observed-receipt slice; the authoritative receipt leg for grouped items (usable or QA-held) is described in the operations doc. Grouped package tracking v1 has since shipped: current behaviour is in [package-issue-operations.md](package-issue-operations.md); the state of the workstream is in the [handoff](../phases/package-balance-handoff.md). Statements below that say tracking is disabled, inactive or not implemented describe this slice's own boundary at the time it was written.

Implemented backend slice 2026-10-03. Physical arrival is recorded independently of the
immutable purchasing agreement under [ADR 0080](../decisions/0080-packaged-purchasing-and-independent-price-basis.md)
and proposed [ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md).
This is historical inbound evidence, not enabled package tracking or a live balance.

## Capture and posting

RecordReceiptComposition uses existing purchasing.goods-receipt.create authority, requires an
actor in the current company, and locks the receipt header then line. It records ordered rows of
whole sealed package counts or actual open/loose quantity with observer, reason and timestamp.
The total must equal receipt stock quantity exactly at four decimals; no silent rounding.
Definitions must belong to the same item/company. Archived definitions may describe a real
delivery using that historic package identity.

The normalised goods_receipt_package_observations table carries line, optional definition,
form, count, content, stock quantity and observation audit fields. It scopes through the receipt
line and header. Database triggers enforce actor/package ownership and block mutation once the
receipt is posted or reversed. A draft observation can be replaced through the Action; changing
the draft line quantity afterward requires a new matching observation before posting.

PostGoodsReceipt now locks receipt lines and calls Inventory's RecordReceiptPackageEvidence
inside the same transaction as movement/valuation posting. The adapter separately checks
purchasing.goods-receipt.post authority and current company. It captures receipt-source layer
contributions, a movement watermark and source_receipt_line_id on an immutable RECEIPT operation.
A company/source-line unique index prevents duplicate evidence. Deferred source checks require
the referenced receipt to finish posted/reversed; a draft cannot commit posted package evidence.

Observed rows must equal received stock, the source movement and full remaining source layer.
QA-required lines produce initially HELD evidence; other lines produce USABLE evidence.
These describe availability at receipt, not current availability after future dispositions.
Package code/name are snapshotted from the definition at posting; the existing commercial
package name/factor and price remain frozen from the agreement.

No observations means no physical package evidence is invented. Existing QUANTITY_ONLY
receipts continue unchanged. Sealed alternatives can describe actual physical form without
rewriting the agreed package/price basis or authorising a commercial supplier change.
Changing the commercial agreement still requires the existing replacement-order workflow.

## Limits and correction

Serial receipt observations and incoming layers consumed by negative-debt settlement are
refused until supported adapters exist. A stale or inconsistent observation aborts the complete
post, including stock moves, layers, AVCO pool changes and package evidence.
A normal post retry returns the original posted receipt without adding package rows.

Receipt reversal retains immutable inbound composition as historical evidence; it does not
create sealed stock or a live package balance. The existing dependency/QA/cost reversal guards
remain authoritative. Checkpoint replay excludes RECEIPT operations when choosing the latest
COUNTED checkpoint and never treats inbound partial evidence as a current full-scope balance.

This slice has no composition HTTP endpoint or screen. Internal authoring and demo use real
Actions; the existing receipt post endpoint performs the transactional integration automatically.
No new permission or API schema is exposed. Requests for create/post keep their existing gates,
and both new Actions enforce their own authority. Costs are not stored in observation/package
tables. The later workflow slice must expose observation capture and receipt history safely.

PACKAGE_BALANCE/CONTAINER_TRACKED activation remains blocked by model/database gates.
[Saved open/unpack evidence](package-opening-operations.md) is implemented for fresh full-scope
counted snapshots. Issue/transfer, QA splitting, cutover and recalculation integration remain unfinished.

## Verification and demo

Twelve additional receipt cases cover mixed delivery, agreement/value preservation, absence of
inference, draft corrections, authority, mismatch/precision refusal, stock/pool rollback,
initial QA hold, retries, raw SQL immutability, debt-settlement rollback and reversal history.
Together with receipt posting/reversal, QA and checkpoint regression suites:
**151 tests / 861 assertions pass**, including inherited baseline tests.

The broader architecture run initially caught a concurrent permission-ratchet update for three
other routes. After that agent updated its exception list, architecture rerun passed
**41 tests / 81 assertions**. Together the two disjoint checked sets pass **192 tests /
942 assertions**. No other agent's permission/test changes are included in this commit.

ReceiptPackageEvidenceDemoSeeder creates a dedicated thinner item and three posted examples:
one sealed 2 L box plus 2 L loose, two observed 2 L cans against the same frozen box price,
and two sealed QA-held boxes. Each order agrees two boxes/4 L at 850 per L, total 3,400.
Dedicated stock avoids backdating prerequisites for the earlier live Purchasing draft.
Create-once receipt keys/order markers and module local/testing guards preserve manual progress.
The additive migration and module seeder ran successfully in local development.
Existing receipt tables have no notes column; explanations live on orders and observation reasons.

## Related

- [Inventory packages](inventory-packages.md)
- [Package kernel](package-balance-integration.md)
- [Tracking policy gates](package-tracking-policy.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Demo journey](../journeys/packaged-purchasing-demo.md)
- [Purchasing](purchasing.md)
