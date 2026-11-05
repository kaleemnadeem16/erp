import type { ExchangeRateResource } from '@erp/core-api';

import type { DataColumn } from '../../components/data-table/types';
import { RecordLink } from '../../components/ui/record-link';
import { formatDate } from '../../lib/format-date';
import { pairLabel, rateTypeLabel } from './exchange-rate-rules';

const pair = (rate: ExchangeRateResource) => pairLabel(rate.from_currency, rate.to_currency, rate.from_currency_id, rate.to_currency_id);

export const exchangeRateColumns: DataColumn<ExchangeRateResource>[] = [
  { key: 'pair', header: 'Currency pair', value: pair, cardRole: 'title', filterable: true, cell: (rate) => <RecordLink className="font-semibold tracking-tight">{pair(rate)}</RecordLink> },
  { key: 'rate_date', header: 'Rate date', value: (rate) => formatDate(rate.rate_date), sortable: true, cardRole: 'subtitle', filterable: true },
  // The rate stays the server's decimal string, right-aligned with tabular figures — never rounded or parsed.
  { key: 'rate', header: 'Rate', value: (rate) => rate.rate, sortable: true, align: 'right', cell: (rate) => <span className="tabular-nums">{rate.rate}</span> },
  { key: 'rate_type', header: 'Type', value: (rate) => rateTypeLabel(rate.rate_type), filterable: true },
  { key: 'source', header: 'Source', value: (rate) => (rate.source ? rate.source.charAt(0).toUpperCase() + rate.source.slice(1) : '—'), screenVisible: false },
  { key: 'created_at', header: 'Entered', value: (rate) => formatDate(rate.created_at), sortable: true, screenVisible: false },
];
