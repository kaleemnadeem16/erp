import type { FormulationResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'success'> = {
  DRAFT: 'secondary',
  ACTIVE: 'success',
  ARCHIVED: 'secondary',
};

export const formulationColumns: DataColumn<FormulationResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
  {
    key: 'output_item',
    header: 'Output item',
    value: (row) => (row.output_item ? `${row.output_item.code} — ${row.output_item.name}` : row.output_item_id),
  },
  {
    key: 'customer',
    header: 'Customer',
    value: (row) => row.customer?.code ?? 'Company default',
  },
  {
    key: 'status',
    header: 'Status',
    value: (row) => row.status,
    cell: (row) => <Badge variant={STATUS_VARIANT[row.status] ?? 'default'}>{row.status}</Badge>,
    filterable: true,
  },
];
