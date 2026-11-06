import type { AssemblyResource } from '@erp/core-api';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';
import { humanizeCode } from '../../components/data-table';
import { RecipeStatusBadge } from '../estimating/estimating-status';

export const assemblyColumns: DataColumn<AssemblyResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, cell: (row) => <RecordLink>{row.code}</RecordLink>, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
  {
    key: 'assembly_group',
    header: 'Group',
    value: (row) => row.assembly_group?.code ?? 'Ungrouped',
  },
  {
    key: 'status',
    header: 'Status',
    value: (row) => humanizeCode(row.status),
    cell: (row) => <RecipeStatusBadge status={row.status} />,
    filterable: true,
  },
];
