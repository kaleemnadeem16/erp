import type { AssemblyGroupResource } from '@erp/core-api';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';

export const assemblyGroupColumns: DataColumn<AssemblyGroupResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, cell: (row) => <RecordLink>{row.code}</RecordLink>, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
];
