import type { AssemblyResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'success'> = {
  DRAFT: 'secondary',
  ACTIVE: 'success',
  ARCHIVED: 'secondary',
};

export const assemblyColumns: DataColumn<AssemblyResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
  {
    key: 'assembly_group',
    header: 'Group',
    value: (row) => row.assembly_group?.code ?? 'Ungrouped',
  },
  {
    key: 'status',
    header: 'Status',
    value: (row) => row.status,
    cell: (row) => <Badge variant={STATUS_VARIANT[row.status] ?? 'default'}>{row.status}</Badge>,
    filterable: true,
  },
];
