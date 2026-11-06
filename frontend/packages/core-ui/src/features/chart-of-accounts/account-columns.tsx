import type { ChartOfAccountResource } from '@erp/core-api';

import type { DataColumn } from '../../components/data-table/types';
import { RecordLink } from '../../components/ui/record-link';
import { StatusBadge } from '../../components/ui/status-badge';
import { formatDate } from '../../lib/format-date';
import { accountTypeLabel } from './account-rules';

export function AccountStatusBadge({ status }: { status: string }) {
  return <StatusBadge tone={status === 'ACTIVE' ? 'active' : 'draft'}>{status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>;
}

export const accountColumns: DataColumn<ChartOfAccountResource>[] = [
  { key: 'code', header: 'Account code', value: (account) => account.code, sortable: true, filterable: true, cardRole: 'subtitle', cell: (account) => <RecordLink className="font-semibold tracking-tight">{account.code}</RecordLink> },
  { key: 'name', header: 'Name', value: (account) => account.name, sortable: true, filterable: true, cardRole: 'title', cell: (account) => <span className="font-medium">{account.name}</span> },
  { key: 'type', header: 'Type', value: (account) => accountTypeLabel(account.type), filterable: true },
  { key: 'parent', header: 'Parent', value: (account) => (account.parent ? `${account.parent.code} — ${account.parent.name}` : account.parent_id ? `Account #${account.parent_id}` : 'Top level'), screenVisible: false },
  { key: 'postable', header: 'Postings', value: (account) => (account.is_postable ? 'Postable' : 'Heading only') },
  { key: 'status', header: 'Status', value: (account) => (account.status === 'ACTIVE' ? 'Active' : 'Archived'), cell: (account) => <AccountStatusBadge status={account.status} />, filterable: true },
  { key: 'created_at', header: 'Created', value: (account) => formatDate(account.created_at), sortable: true, screenVisible: false },
];
