import type { StockMoveResource } from '@erp/core-api';

import type { DataColumn } from '../../components/data-table/types';

/**
 * The single source of truth for what a stock ledger row "is" on screen and
 * in Quick Print — see items-columns.tsx for the same pattern. `item`,
 * `from_location` and `to_location` are only populated when requested via
 * `include`; the list screen always asks for them.
 */
export const stockMoveColumns: DataColumn<StockMoveResource>[] = [
  {
    key: 'moved_at',
    header: 'Moved at',
    value: (move) => new Date(move.moved_at).toLocaleString(),
    sortable: true,
    cardRole: 'subtitle',
  },
  {
    key: 'item',
    header: 'Item',
    value: (move) => (move.item ? `${move.item.code} — ${move.item.name}` : move.item_id),
    cardRole: 'title',
  },
  {
    key: 'from_location',
    header: 'From',
    value: (move) => move.from_location?.code ?? null,
  },
  {
    key: 'to_location',
    header: 'To',
    value: (move) => move.to_location?.code ?? null,
  },
  {
    key: 'qty',
    header: 'Quantity',
    value: (move) => move.qty,
    align: 'right',
  },
  {
    key: 'unit_cost',
    header: 'Unit cost',
    value: (move) => move.unit_cost,
    align: 'right',
    screenVisible: false,
  },
  {
    key: 'source',
    header: 'Source',
    value: (move) => `${move.source_type} #${move.source_id}`,
  },
  {
    key: 'batch_serial',
    header: 'Batch / serial',
    value: (move) => move.batch?.code ?? move.serial_no ?? null,
    screenVisible: false,
  },
];
