import type { CustomerAccountResource } from '@erp/core-api';

import type { DataColumn } from '../../components/data-table/types';
import { RecordLink } from '../../components/ui/record-link';
import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { formatDate } from '../../lib/format-date';

/** Customer-account status. The label is always shown — colour alone is not meaning. */
export function CustomerStatusBadge({ status }: { status: string }) {
  const tone: StatusTone = status === 'ACTIVE' ? 'active' : 'draft';

  return <StatusBadge tone={tone}>{status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>;
}

export interface CustomerColumnOptions {
  /** `currency id → "USD — US Dollar"`, resolved by the screen from the currency list. */
  currencyLabels?: Map<number, string>;
  /** `account id → "1100 — Accounts Receivable"`, resolved by id (a chart can be larger than one page). */
  accountLabels?: Map<number, string>;
  /** `parties.credit-limit.view` or `.manage` — without it the API omits the key, so the column does not exist (ADR 0084). */
  mayViewCredit?: boolean;
  /** `accounting.account-mapping.view` or `.manage` — without it the receivable account is omitted by the API. */
  mayViewMapping?: boolean;
}

/**
 * The customer list's columns, one definition for the table, the phone cards and Quick Print. Protected columns (credit limit, receivable account) are not
 * built at all for someone who may not read them, so they cannot appear in a card, a column menu or a printout.
 */
export function getCustomerColumns({ currencyLabels, accountLabels, mayViewCredit, mayViewMapping }: CustomerColumnOptions = {}): DataColumn<CustomerAccountResource>[] {
  const columns: DataColumn<CustomerAccountResource>[] = [
    {
      key: 'code',
      header: 'Customer code',
      value: (customer) => customer.code,
      sortable: true,
      filterable: true,
      cardRole: 'subtitle',
      cell: (customer) => <RecordLink className="font-semibold tracking-tight">{customer.code}</RecordLink>,
    },
    {
      key: 'name',
      header: 'Trading partner',
      value: (customer) => customer.party?.name ?? '',
      filterable: true,
      cardRole: 'title',
      cell: (customer) =>
        customer.party?.name ? <span className="font-medium">{customer.party.name}</span> : <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'party_code',
      header: 'Partner code',
      value: (customer) => customer.party?.code ?? '',
      screenVisible: false,
      cell: (customer) => <span className="text-muted-foreground">{customer.party?.code ?? '—'}</span>,
    },
    {
      key: 'tax_id',
      header: 'Tax ID',
      value: (customer) => customer.party?.tax_id ?? '',
      screenVisible: false,
    },
    {
      key: 'status',
      header: 'Status',
      value: (customer) => (customer.status === 'ACTIVE' ? 'Active' : 'Archived'),
      sortable: true,
      filterable: true,
      cell: (customer) => <CustomerStatusBadge status={customer.status} />,
    },
    {
      key: 'payment_terms_days',
      header: 'Payment terms',
      value: (customer) => (customer.payment_terms_days != null ? `${customer.payment_terms_days} days` : '—'),
      sortable: true,
      align: 'right',
      cell: (customer) => <span className="tabular-nums">{customer.payment_terms_days} days</span>,
    },
  ];

  if (mayViewCredit) {
    columns.push({
      key: 'credit_limit',
      header: 'Credit limit',
      // Money stays the server's decimal string — never parsed into a float. No limit set means "no limit enforced", not zero.
      value: (customer) => customer.credit_limit ?? 'No limit',
      align: 'right',
      cell: (customer) =>
        customer.credit_limit != null ? <span className="tabular-nums">{customer.credit_limit}</span> : <span className="text-muted-foreground">No limit</span>,
    });
  }

  columns.push({
    key: 'default_currency',
    header: 'Default currency',
    value: (customer) => (customer.default_currency_id ? (currencyLabels?.get(customer.default_currency_id) ?? `Currency #${customer.default_currency_id}`) : 'Company default'),
    screenVisible: false,
  });

  if (mayViewMapping) {
    columns.push({
      key: 'receivable_account',
      header: 'Receivable account',
      value: (customer) =>
        customer.receivable_account_id ? (accountLabels?.get(customer.receivable_account_id) ?? `Account #${customer.receivable_account_id}`) : '—',
      screenVisible: false,
    });
  }

  columns.push({
    key: 'updated_at',
    header: 'Updated',
    value: (customer) => formatDate(customer.updated_at),
    sortable: true,
    screenVisible: false,
  });

  return columns;
}
