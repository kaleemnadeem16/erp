import type { AssemblyGroupResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';

export const assemblyGroupColumns: DataColumn<AssemblyGroupResource>[] = [
  { key: 'code', header: 'Code', value: (row) => row.code, sortable: true, cardRole: 'title' },
  { key: 'name', header: 'Name', value: (row) => row.name, cardRole: 'subtitle' },
];
