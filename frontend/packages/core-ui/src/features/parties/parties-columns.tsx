import type { PartyResource } from '@erp/core-api';

import type { DataColumn } from '../../components/data-table/types';
import { Badge } from '../../components/ui/badge';
import { RecordLink } from '../../components/ui/record-link';
import { StatusBadge } from '../../components/ui/status-badge';
import { formatDate } from '../../lib/format-date';

/** `Customer`, `Supplier`, both or `No role yet` — the roles are independent (ADR 0030), so neither implies the other. */
export function partyRoles(party: Pick<PartyResource, 'customer_account' | 'supplier_account'>): string[] {
  const roles: string[] = [];
  if (party.customer_account) roles.push('Customer');
  if (party.supplier_account) roles.push('Supplier');

  return roles;
}

export const kindLabel = (kind: string | undefined) => (kind === 'INDIVIDUAL' ? 'Individual' : kind === 'ORGANISATION' ? 'Organisation' : '');

export const partyColumns: DataColumn<PartyResource>[] = [
  {
    key: 'code',
    header: 'Partner code',
    value: (party) => party.code,
    sortable: true,
    filterable: true,
    cardRole: 'subtitle',
    cell: (party) => <RecordLink className="font-semibold tracking-tight">{party.code}</RecordLink>,
  },
  {
    key: 'name',
    header: 'Business name',
    value: (party) => party.name,
    sortable: true,
    filterable: true,
    cardRole: 'title',
    cell: (party) => <span className="font-medium">{party.name}</span>,
  },
  {
    key: 'roles',
    header: 'Roles',
    value: (party) => (partyRoles(party).join(', ') || 'No role yet'),
    cell: (party) => {
      const roles = partyRoles(party);

      return roles.length > 0 ? (
        <span className="flex flex-wrap gap-1">
          {roles.map((role) => (
            <Badge key={role} variant="secondary" className="font-normal">
              {role}
            </Badge>
          ))}
        </span>
      ) : (
        <span className="text-muted-foreground">No role yet</span>
      );
    },
  },
  { key: 'kind', header: 'Kind', value: (party) => kindLabel(party.kind), screenVisible: false },
  { key: 'tax_id', header: 'Tax ID', value: (party) => party.tax_id ?? '', screenVisible: false },
  { key: 'registration_no', header: 'Registration no.', value: (party) => party.registration_no ?? '', screenVisible: false },
  {
    key: 'status',
    header: 'Status',
    value: (party) => (party.status === 'ACTIVE' ? 'Active' : 'Archived'),
    cell: (party) => <StatusBadge tone={party.status === 'ACTIVE' ? 'active' : 'draft'}>{party.status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>,
  },
  { key: 'created_at', header: 'Created', value: (party) => formatDate(party.created_at), sortable: true, screenVisible: false },
];
