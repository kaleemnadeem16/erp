import { useAssemblyVersionsCostBreakdown } from '@erp/core-api';
import { Skeleton } from '../../components/ui/skeleton';
import { getApiErrorMessage } from '../../lib/api-errors';

import { CostEvidence, CostReadiness } from '../costing/cost-evidence';

const OVERHEAD_METHOD_LABELS: Record<string, string> = {
  ASSEMBLY: 'this assembly',
  ASSEMBLY_GROUP: 'its assembly group',
  COMPANY: 'the company default',
  NONE: 'no policy — $0',
};

/**
 * The explainable five-part breakdown Step 3 requires: which version,
 * which overhead tier/driver resolved, what base each component used, and
 * the final cost/sell per unit — see AssemblyVersionController::costBreakdown.
 */
export function AssemblyCostBreakdown({ versionId }: { versionId: number }) {
  const query = useAssemblyVersionsCostBreakdown(versionId);

  if (query.isLoading) {
    return (
      <div className="space-y-2">
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (query.data?.status !== 200) {
    return <p className="text-sm text-destructive">{getApiErrorMessage(query.error) ?? 'Could not load the cost breakdown.'}</p>;
  }

  const data = query.data.data.data;

  return (
    <div className="space-y-4 rounded-md border p-4 text-sm">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <CostEvidence label="Material" cost={data.cost_by_kind.material} />
        <CostEvidence label="Labour" cost={data.cost_by_kind.labour} />
        <CostEvidence label="Plant" cost={data.cost_by_kind.plant} />
        <CostEvidence label="Subcontract" cost={data.cost_by_kind.subcontract} />
        <CostEvidence label="Overhead" cost={data.overhead.cost} />
      </div>

      <div className="rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
        Overhead resolved from {OVERHEAD_METHOD_LABELS[data.overhead.method] ?? data.overhead.method}
        {data.overhead.driver_type && ` — ${data.overhead.rate} (${data.overhead.driver_type})`}
        {data.labour_hours !== '0.000000000' && ` · total labour hours: ${data.labour_hours}`}
      </div>

      <div className="flex flex-wrap items-center gap-4 border-t pt-3">
        <CostEvidence label="Direct cost" cost={data.direct_cost} />
        <div>
          <div className="text-xs text-muted-foreground">Cost / unit</div>
          <div className="font-medium">{data.cost.unit_cost ?? 'Unavailable'}</div>
        </div>
        <div>
          <div className="text-xs text-muted-foreground">Sell / unit</div>
          <div className="text-lg font-semibold">{data.sell_per_unit ?? 'Unavailable until cost is complete'}</div>
        </div>
      </div>

      <CostReadiness cost={data.cost} />
      {data.components.length > 0 && (
        <div className="space-y-2 border-t pt-3">
          <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Components</div>
          {data.components.map((c) => (
            <div key={c.component.id} className="flex items-center justify-between gap-2 rounded border px-3 py-2">
              <div>
                <div className="font-medium">
                  {c.component.kind}
                  {c.component.item ? ` — ${c.component.item.code}` : ''}
                </div>
                <div className="text-xs text-muted-foreground">
                  Technical quantity {c.resolved_quantity}; priced quantity {c.cost.valued_quantity} at {c.cost.unit_cost ?? 'Unavailable'} — {c.cost_source}
                </div>
              </div>
              <div className="font-medium">{c.cost.total_cost ?? 'Unavailable'}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
