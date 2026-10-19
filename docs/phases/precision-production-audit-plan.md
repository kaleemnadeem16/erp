[← Phase index](README.md)

# Production precision compatibility audit — plan

**2026-10-15 · APPROVED TO PREPARE and coordinate (owner); NOT EXECUTED against production.**
**2026-10-17 · Owner decision: not required for the current development/demo-data scenario (PX3a closed as not required).** The plan is kept as the template for the general compatibility checks that must run before any future migration of genuine customer data.
No production write, migration, demo seeding, enforcement change or data correction is authorised. This plan is
the prerequisite for enforcing [0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) in Purchasing.

## Purpose
Find, in aggregate, whether production holds quantities that the new representability rule would refuse, increments that break it, and open orders that depend on the live Item increment.

## Responsibility and access
| Item | Requirement |
|---|---|
| Responsible operator | The production/deployment owner, or a person they name in writing. **Not yet named.** |
| Executor | Operator on mainvm using `psql` against dbvm with a **read-only** role (the same class of role as the backup/exporter role in [backup-and-restore.md](../architecture/backup-and-restore.md)), never the application role |
| Authorisation | Owner's written go-ahead for the run date; recorded with the result |
| Output handling | Aggregate counts only; no names, codes, amounts or customer data leave the host; the result is pasted into the record below |

## Load
Read-only transaction, `statement_timeout 30s`, `lock_timeout 2s`, rolled back. On the development database (about 1,000 order lines, 900 receipt lines) the script completes in well under a second. Production holds the same demo data, so volume is expected to be similar. dbvm has one vCPU shared with other applications: run once, outside peak, and run section A first and stop if either count is above 100,000.

## Script (every query is a SELECT; none returns a row of business data)
```sql
\set ON_ERROR_STOP on
BEGIN READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '2s';
-- A  volumes
SELECT po.status, count(*) AS lines FROM purchase_order_lines l JOIN purchase_orders po ON po.id = l.purchase_order_id GROUP BY 1 ORDER BY 1;
SELECT r.status, count(*) AS lines FROM goods_receipt_lines l JOIN goods_receipts r ON r.id = l.goods_receipt_id GROUP BY 1 ORDER BY 1;
-- B  conversions whose exact stock quantity is not representable at four places
SELECT po.status, count(*) AS non_representable FROM purchase_order_lines l JOIN purchase_orders po ON po.id = l.purchase_order_id
  WHERE l.ordered_qty * l.uom_factor <> round(l.ordered_qty * l.uom_factor, 4) GROUP BY 1 ORDER BY 1;
SELECT r.status, count(*) AS non_representable FROM goods_receipt_lines l JOIN goods_receipts r ON r.id = l.goods_receipt_id
  WHERE l.received_qty * l.uom_factor <> round(l.received_qty * l.uom_factor, 4) GROUP BY 1 ORDER BY 1;
SELECT 'po_lines' AS source, count(*) FROM purchase_order_lines WHERE ordered_stock_qty <> ordered_qty * uom_factor
UNION ALL SELECT 'receipt_lines', count(*) FROM goods_receipt_lines WHERE stock_qty <> received_qty * uom_factor;
-- C  open orders that depend on the live Item increment
SELECT count(*) AS open_lines_with_live_item_increment FROM purchase_order_lines l JOIN purchase_orders po ON po.id = l.purchase_order_id
  JOIN items i ON i.id = l.item_id WHERE po.status = 'ISSUED' AND i.quantity_precision IS NOT NULL;
SELECT l.over_receipt_mode, count(*) FROM purchase_order_lines l JOIN purchase_orders po ON po.id = l.purchase_order_id
  JOIN items i ON i.id = l.item_id WHERE po.status = 'ISSUED' AND i.quantity_precision IS NOT NULL GROUP BY 1 ORDER BY 1;
-- D  increments, package content and factors
SELECT 'items' AS source, count(*) FROM items WHERE quantity_precision IS NOT NULL AND (quantity_precision < 0.0001 OR mod(quantity_precision, 0.0001) <> 0)
UNION ALL SELECT 'item_quantity_units', count(*) FROM item_quantity_units WHERE quantity_precision IS NOT NULL AND (quantity_precision < 0.0001 OR mod(quantity_precision, 0.0001) <> 0)
UNION ALL SELECT 'item_packagings', count(*) FROM item_packagings WHERE quantity_precision IS NOT NULL AND (quantity_precision < 0.0001 OR mod(quantity_precision, 0.0001) <> 0);
SELECT count(*) AS packagings_over_4_places FROM item_packagings WHERE stock_quantity <> round(stock_quantity, 4);
SELECT 'item_quantity_units' AS source, count(*) FROM item_quantity_units WHERE factor <> round(factor, 4)
UNION ALL SELECT 'uoms', count(*) FROM uoms WHERE factor_to_base <> round(factor_to_base, 4);
-- E  reference currencies present (code and minor unit only)
SELECT code, decimal_places FROM currencies ORDER BY code;
ROLLBACK;
```

## Reading the result
| Finding | Consequence |
|---|---|
| B1–B3 all zero and D all zero | Enforcement may proceed after tests; **no legacy path is built** |
| Any B or D non-zero | Report the counts; the legacy completion path in 0142 is then justified for exactly those categories, and a per-record review (by the owner, off-line) precedes any design |
| C > 0 | The pinned-rule slice is needed for those lines; C2 shows how many sit under a tolerance mode (only `ALLOW_WITHIN_TOLERANCE` uses the floor) |
| E shows a currency used with an unexpected minor unit | Report to the owner before the currency slice runs |

## Rehearsal (development database, read-only, rolled back, 2026-10-15)
Order lines: CANCELLED 2, CLOSED 53, DRAFT 127, ISSUED 779. Receipt lines: DRAFT 4, POSTED 861, REVERSED 47. B1–B3: all 0. C1: 4 open lines with a live Item increment, all in the `BLOCK` over-receipt mode (the floor does not apply). D: all 0. E: AED, PKR, USD, two places each. Dev has no exchange rates.

## Production result record
*(To be filled by the operator after the owner authorises the run: date, operator, aggregate output, decision.)*

## Related
- [0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) · [precision.md](../domain/precision.md) · [slices](precision-implementation-slices.md)
- [Backup and restore](../architecture/backup-and-restore.md) · [Deployment](../architecture/deployment.md)
