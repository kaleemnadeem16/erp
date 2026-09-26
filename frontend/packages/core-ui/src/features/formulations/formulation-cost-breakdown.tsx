import { useFormulationVersionsCostBreakdown } from '@erp/core-api';
import { Skeleton } from '../../components/ui/skeleton';

interface CostBreakdownLine {
  line_id: number;
  input_item_id: number;
  input_item_code: string;
  input_item_name: string;
  qty: string;
  wastage_pct: string;
  unit_cost: string;
  line_cost: string;
  sub_formulation: CostBreakdownExplosion | null;
}

interface CostBreakdownExplosion {
  version_id: number;
  total_cost: string;
  cost_per_output_unit: string;
  lines: CostBreakdownLine[];
}

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

  const explosion = result.data.data.data as unknown as CostBreakdownExplosion;

  return (
    <div className="space-y-3">
      <div className="rounded-md border bg-panel/60 p-3">
        <p className="text-sm text-muted-foreground">Cost per output unit</p>
        <p className="text-lg font-semibold">{explosion.cost_per_output_unit}</p>
      </div>
      <ExplosionTree explosion={explosion} depth={0} />
    </div>
  );
}

function ExplosionTree({ explosion, depth }: { explosion: CostBreakdownExplosion; depth: number }) {
  return (
    <ul className="space-y-2" style={{ marginLeft: depth > 0 ? 16 : 0 }}>
      {explosion.lines.map((line) => (
        <li key={line.line_id} className="rounded-md border p-3">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="font-medium">
              {line.input_item_code} — {line.input_item_name}
            </span>
            <span className="text-sm text-muted-foreground">
              {line.qty} × {line.unit_cost}
              {Number(line.wastage_pct) > 0 && ` (+${line.wastage_pct}% wastage)`} = {line.line_cost}
            </span>
          </div>
          {line.sub_formulation && (
            <div className="mt-2 border-l-2 pl-3">
              <p className="text-xs text-muted-foreground">
                From its own formulation — {line.sub_formulation.cost_per_output_unit} per output unit
              </p>
              <ExplosionTree explosion={line.sub_formulation} depth={depth + 1} />
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}
