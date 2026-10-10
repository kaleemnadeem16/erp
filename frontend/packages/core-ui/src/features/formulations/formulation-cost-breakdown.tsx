import { useFormulationVersionsCostBreakdown, type FormulationCostResource } from '@erp/core-api';
import { Skeleton } from '../../components/ui/skeleton';

import { CostEvidence, CostReadiness } from '../costing/cost-evidence';

/**
 * Renders docs/domain/formulations.md#cost-of-one-output-unit's breakdown as
 * a nested tree, per that doc's own "Landmines" — wastage compounding
 * through nesting "surprises people. Show the breakdown in the UI rather
 * than only the total."
 */
export function FormulationCostBreakdown({ versionId }: { versionId: number }) {
  const result = useFormulationVersionsCostBreakdown(versionId);

  if (result.isLoading) return <Skeleton className="h-24 w-full" />;
  if (result.isError || result.data?.status !== 200) {
    return <p className="text-sm text-destructive">Couldn't load the cost breakdown.</p>;
  }

  const explosion = result.data.data.data;

  return (
    <div className="space-y-3">
      <div className="rounded-md border bg-panel/60 p-3">
        <p className="text-sm text-muted-foreground">Cost per output unit</p>
        <p className="text-lg font-semibold">{explosion.cost.unit_cost ?? 'Unavailable'}</p>
      </div>
      <CostEvidence cost={explosion.cost} label="Output batch cost" />
      <CostReadiness cost={explosion.cost} />
      <ExplosionTree explosion={explosion} depth={0} />
    </div>
  );
}

function ExplosionTree({ explosion, depth }: { explosion: FormulationCostResource; depth: number }) {
  return (
    <ul className="space-y-2" style={{ marginLeft: depth > 0 ? 16 : 0 }}>
      {explosion.lines.map((line) => (
        <li key={line.line_id} className="rounded-md border p-3">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="font-medium">
              {line.input_item_code} — {line.input_item_name}
            </span>
            <span className="text-sm text-muted-foreground">
              Priced quantity {line.cost.valued_quantity} × {line.cost.unit_cost ?? 'Unavailable'}
              {Number(line.wastage_pct) > 0 && ` (+${line.wastage_pct}% wastage)`} = {line.cost.total_cost ?? 'Unavailable'}
            </span>
          </div>
          {line.sub_formulation && (
            <div className="mt-2 border-l-2 pl-3">
              <p className="text-xs text-muted-foreground">
                From its own formulation — {line.sub_formulation.cost.unit_cost ?? 'Unavailable'} per output unit
              </p>
              <ExplosionTree explosion={line.sub_formulation} depth={depth + 1} />
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}
