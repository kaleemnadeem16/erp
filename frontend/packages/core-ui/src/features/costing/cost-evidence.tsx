import { useCurrenciesShow, type CostResolutionResource } from '@erp/core-api';
import { formatDateTime } from '../../lib/format-date';

export function CostEvidence({ cost, label }: { cost: CostResolutionResource; label: string }) {
  return (
    <div className="min-w-0">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="font-medium tabular-nums">{cost.total_cost ?? 'Unavailable'}</div>
      {cost.status !== 'COSTED' && (
        <div className="text-xs text-muted-foreground">
          {cost.status === 'PARTIAL' ? `Incomplete · known contribution ${cost.known_cost}` : 'Cost evidence missing'}
        </div>
      )}
    </div>
  );
}

export function CostReadiness({ cost }: { cost: CostResolutionResource }) {
  const currencyQuery = useCurrenciesShow(cost.currency_id ?? 0, { query: { enabled: cost.currency_id !== null } });
  const currency = currencyQuery.data?.status === 200 ? currencyQuery.data.data.data.code : 'Currency unavailable';
  return (
    <div className="space-y-1 text-xs text-muted-foreground">
      <p>{cost.status === 'COSTED' ? 'Complete cost evidence' : 'Complete cost is unavailable'} · quantity {cost.valued_quantity} · {cost.amount_basis.replaceAll('_', ' ').toLowerCase()}</p>
      <p>Current estimate · observed {formatDateTime(cost.observed_at) ?? cost.observed_at} · {currency}</p>
    </div>
  );
}

/**
 * The cost of a drawn quantity only (SELECTED_DRAW) — never the cost of the whole requirement, whose shortage is
 * reported separately. Incomplete evidence shows the known contribution, not a total.
 */
export function drawCostText(cost: CostResolutionResource | null, subject: string): string {
  if (cost === null) return `The cost of ${subject} is unavailable.`;
  if (cost.status === 'COSTED') return `Internal cost of ${subject} (drawn quantity only): ${cost.total_cost}`;
  if (cost.status === 'PARTIAL') return `The cost of ${subject} is incomplete: some stock has no complete cost evidence. Known contribution ${cost.known_cost}.`;
  return `The cost of ${subject} is unavailable: its stock has no complete cost evidence.`;
}
