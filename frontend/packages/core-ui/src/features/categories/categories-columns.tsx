import type { CategoryResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';
import { formatDate } from '../../lib/format-date';

export const categoryColumns: DataColumn<CategoryResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, sortable: true, filterable: true, cardRole: 'subtitle' },
  { key: 'name', header: 'Name', value: (row) => row.name, sortable: true, filterable: true, cardRole: 'title' },
  { key: 'parent', header: 'Parent', value: (row) => row.parent?.name ?? null, filterable: true },
  { key: 'default_valuation_method', header: 'Default valuation', value: (row) => row.default_valuation_method },
  {
    key: 'created_at',
    header: 'Created',
    value: (row) => formatDate(row.created_at),
    sortable: true,
    screenVisible: false,
  },
];
