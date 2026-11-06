import type { FormulationResource } from '@erp/core-api';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';
import { humanizeCode } from '../../components/data-table';
import { RecipeStatusBadge } from '../estimating/estimating-status';

export const formulationColumns: DataColumn<FormulationResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, cell: (row) => <RecordLink>{row.code}</RecordLink>, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
  {
    key: 'output_item',
    header: 'Output item',
    value: (row) => (row.output_item ? `${row.output_item.code} — ${row.output_item.name}` : `Item #${row.output_item_id}`),
  },
  {
    key: 'customer',
    header: 'Customer',
    value: (row) => row.customer?.code ?? 'Company default',
  },
  {
    key: 'status',
    header: 'Status',
    value: (row) => humanizeCode(row.status),
    cell: (row) => <RecipeStatusBadge status={row.status} />,
    filterable: true,
  },
];
