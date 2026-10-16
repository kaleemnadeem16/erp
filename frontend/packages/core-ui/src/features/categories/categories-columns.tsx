import type { CategoryResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';
import { RecordLink } from '../../components/ui/record-link';
import { formatDate } from '../../lib/format-date';

export const categoryColumns: DataColumn<CategoryResource>[] = [
  {
    key: 'code',
    header: 'Code',
    value: (row) => row.code,
    cell: (row) => <RecordLink>{row.code}</RecordLink>,
    sortable: true,
    filterable: true,
    cardRole: 'subtitle',
  },
  {
    key: 'name',
    header: 'Name',
    value: (row) => row.name,
    cell: (row) => <span className="font-medium text-foreground">{row.name}</span>,
    sortable: true,
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'parent',
    header: 'Parent',
    value: (row) => row.parent?.name ?? null,
    cell: (row) =>
      row.parent?.name ? <span>{row.parent.name}</span> : <span className="text-muted-foreground">—</span>,
    filterable: true,
  },
  {
    key: 'default_valuation_method',
    header: 'Default valuation',
    value: (row) => row.default_valuation_method,
    cell: (row) =>
      row.default_valuation_method ? (
        <span>{row.default_valuation_method}</span>
      ) : (
        <span className="text-muted-foreground italic text-xs">Inherited</span>
      ),
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (row) => formatDate(row.created_at),
    cell: (row) => formatDate(row.created_at) ?? <span className="text-muted-foreground">—</span>,
    sortable: true,
    screenVisible: false,
  },
];

