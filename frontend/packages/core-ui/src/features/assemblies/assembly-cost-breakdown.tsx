import { useAssemblyVersionsCostBreakdown } from '@erp/core-api';
import { Skeleton } from '../../components/ui/skeleton';
import { getApiErrorMessage } from '../../lib/api-errors';

// The API's components array is typed unknown[] in the generated client —
// Scramble can't infer the shape of a dynamically array_map()'d response —
// so this mirrors AssemblyVersionController::serializeBreakdown()'s actual
// PHP shape by hand instead of fighting that inference.
interface ComponentBreakdown {
  component: { id: number; kind: string; item?: { code: string; name: string } | null };
  resolved_qty_per_work_unit: string;
  resolved_unit_cost: string;
  line_cost: string;
  cost_source: string;
}

interface CostBreakdownData {
  version_id: number;
  material_cost: string;
  labour_cost: string;
  labour_hours: string;
  plant_cost: string;
  subcontract_cost: string;
  direct_cost: string;
  overhead: { method: string; policy_id: number | null; driver_type: string | null; rate: string | null; amount: string };
  cost_per_unit: string;
  sell_per_unit: string;
  components: ComponentBreakdown[];
}

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

  const data = query.data.data.data as unknown as CostBreakdownData;

  return (
    <div className="space-y-4 rounded-md border p-4 text-sm">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <Figure label="Material" value={data.material_cost} />
        <Figure label="Labour" value={data.labour_cost} />
        <Figure label="Plant" value={data.plant_cost} />
        <Figure label="Subcontract" value={data.subcontract_cost} />
        <Figure label="Overhead" value={data.overhead.amount} />
      </div>

      <div className="rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
        Overhead resolved from {OVERHEAD_METHOD_LABELS[data.overhead.method] ?? data.overhead.method}
        {data.overhead.driver_type && ` — ${data.overhead.rate} (${data.overhead.driver_type})`}
        {data.labour_hours !== '0.000000000' && ` · total labour hours: ${data.labour_hours}`}
      </div>

      <div className="flex flex-wrap items-center gap-4 border-t pt-3">
        <div>
          <div className="text-xs text-muted-foreground">Direct cost</div>
          <div className="font-medium">{data.direct_cost}</div>
        </div>
        <div>
          <div className="text-xs text-muted-foreground">Cost / unit</div>
          <div className="font-medium">{data.cost_per_unit}</div>
        </div>
        <div>
          <div className="text-xs text-muted-foreground">Sell / unit</div>
          <div className="text-lg font-semibold">{data.sell_per_unit}</div>
        </div>
      </div>

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
                  {c.resolved_qty_per_work_unit} × {c.resolved_unit_cost} — {c.cost_source}
                </div>
              </div>
              <div className="font-medium">{c.line_cost}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="font-medium">{value}</div>
    </div>
  );
}
