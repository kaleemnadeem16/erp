import type { RateDimensionResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

export const rateDimensionColumns: DataColumn<RateDimensionResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
  { key: 'sort_order', header: 'Sort order', value: (row) => row.sort_order, sortable: true, align: 'right' },
  {
    key: 'is_required',
    header: 'Required',
    value: (row) => (row.is_required ? 'Required' : 'Optional'),
    cell: (row) => (
      <Badge variant={row.is_required ? 'default' : 'secondary'}>{row.is_required ? 'Required' : 'Optional'}</Badge>
    ),
  },
];
