import { expect, test } from '@playwright/test';
import type { StockMoveResource } from '@erp/core-api';

import { stockMoveColumns } from '../../../packages/core-ui/src/features/stock-ledger/stock-moves-columns';
import { describeFilters } from '../../../packages/core-ui/src/features/stock-ledger/stock-ledger-screen';
import { formatColumnValue } from '../../../packages/core-ui/src/components/data-table/types';

test.describe('stock moves columns and formatting', () => {
  const sampleMove: StockMoveResource = {
    id: 101,
    item_id: 42,
    item: {
      id: 42,
      code: 'RAW-001',
      name: 'Titanium Powder',
      type: 'RAW_MATERIAL',
      status: 'ACTIVE',
      stock_uom_id: 1,
      uom: { id: 1, code: 'KG', name: 'Kilograms' },
    } as any,
    qty: '15.5000',
    uom_id: 1,
    moved_at: '2026-10-20T14:30:00Z',
    source_type: 'GOODS_RECEIPT',
    source_id: 7,
    from_location_id: 1,
    from_location: {
      id: 1,
      code: 'DOCK-1',
      warehouse_id: 10,
      warehouse: { id: 10, code: 'WH-MAIN', name: 'Main Warehouse' },
    } as any,
    to_location_id: 2,
    to_location: {
      id: 2,
      code: 'BIN-44',
      warehouse_id: 10,
      warehouse: { id: 10, code: 'WH-MAIN', name: 'Main Warehouse' },
    } as any,
    unit_cost: '25.0000',
    currency_id: 1,
    fx_rate: '1.000000',
    batch_id: 5,
    batch: { id: 5, code: 'BATCH-2026-A' } as any,
    serial_no: 'SN-9988',
  };

  test('moved_at column formats timestamp, has cardRole subtitle and is sortable', () => {
    const col = stockMoveColumns.find((c) => c.key === 'moved_at');
    expect(col).toBeDefined();
    expect(col?.sortable).toBe(true);
    expect(col?.cardRole).toBe('subtitle');
    const val = col?.value(sampleMove);
    expect(val).toBeDefined();
    expect(String(val)).toContain('10/20/26');
  });

  test('item column has cardRole title and formats code and name', () => {
    const col = stockMoveColumns.find((c) => c.key === 'item');
    expect(col).toBeDefined();
    expect(col?.cardRole).toBe('title');
    expect(col?.value(sampleMove)).toBe('RAW-001 — Titanium Powder');

    // Falls back to item_id when item object is missing
    const moveWithoutItem = { ...sampleMove, item: undefined };
    expect(col?.value(moveWithoutItem)).toBe(42);
  });

  test('location columns format as warehouse / location and handle null', () => {
    const fromCol = stockMoveColumns.find((c) => c.key === 'from_location');
    const toCol = stockMoveColumns.find((c) => c.key === 'to_location');
    expect(fromCol?.value(sampleMove)).toBe('WH-MAIN / DOCK-1');
    expect(toCol?.value(sampleMove)).toBe('WH-MAIN / BIN-44');

    const moveWithoutLocs = { ...sampleMove, from_location: undefined, to_location: undefined };
    expect(fromCol?.value(moveWithoutLocs)).toBeNull();
    expect(formatColumnValue(fromCol!, moveWithoutLocs)).toBe('—');
  });

  test('quantity and unit_cost align right and format accurately', () => {
    const qtyCol = stockMoveColumns.find((c) => c.key === 'qty');
    const costCol = stockMoveColumns.find((c) => c.key === 'unit_cost');

    expect(qtyCol?.align).toBe('right');
    expect(qtyCol?.value(sampleMove)).toBe('15.5000');

    expect(costCol?.align).toBe('right');
    expect(costCol?.screenVisible).toBe(false);
    expect(costCol?.value(sampleMove)).toBe('25.0000');
  });

  test('source and batch/serial columns', () => {
    const sourceCol = stockMoveColumns.find((c) => c.key === 'source');
    const batchCol = stockMoveColumns.find((c) => c.key === 'batch_serial');

    expect(sourceCol?.value(sampleMove)).toBe('GOODS_RECEIPT #7');
    expect(batchCol?.screenVisible).toBe(false);
    expect(batchCol?.value(sampleMove)).toBe('BATCH-2026-A');

    const moveWithSerialOnly = { ...sampleMove, batch: undefined };
    expect(batchCol?.value(moveWithSerialOnly)).toBe('SN-9988');

    const moveWithNeither = { ...sampleMove, batch: undefined, serial_no: undefined };
    expect(batchCol?.value(moveWithNeither)).toBeNull();
    expect(formatColumnValue(batchCol!, moveWithNeither)).toBe('—');
  });

  test('describeFilters formats all active filter lines accurately', () => {
    const items = [{ id: 42, code: 'RAW-001', name: 'Titanium Powder' }];
    const warehouses = [{ id: 10, name: 'Main Warehouse' }];
    const locations = [
      { id: 2, code: 'BIN-44', warehouse: { code: 'WH-MAIN' } },
    ];

    const lines = describeFilters(
      {
        item_id: '42',
        warehouse_id: '10',
        location_id: '2',
        batch_id: '5',
        source_type: 'GOODS_RECEIPT',
        moved_from: '2026-10-01',
        moved_to: '2026-10-31',
        search: 'powder',
      },
      items,
      warehouses,
      locations
    );

    expect(lines).toContain('Item = RAW-001 — Titanium Powder');
    expect(lines).toContain('Warehouse = Main Warehouse');
    expect(lines).toContain('Location = WH-MAIN / BIN-44');
    expect(lines).toContain('Batch = #5');
    expect(lines).toContain('Source type = GOODS_RECEIPT');
    expect(lines).toContain('From = 2026-10-01');
    expect(lines).toContain('To = 2026-10-31');
    expect(lines).toContain('Search = "powder"');

    // Empty filters produce empty lines
    expect(describeFilters({}, items, warehouses)).toEqual([]);
  });
});
