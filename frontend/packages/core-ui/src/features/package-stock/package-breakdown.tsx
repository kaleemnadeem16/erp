import { Badge } from "../../components/ui/badge";
import { cn } from "../../lib/utils";
import { packageLabel, trimQty } from "./quantity";
import type { SealedRow } from "./types";

/**
 * The physical breakdown of a stock quantity: `3 × 5 L can · 5 L loose · 20 L total`. The total is the stock quantity (the authority); the rest explains
 * how it physically exists. Shown wherever a package-tracked item's stock appears, so the two are always read together.
 */
export function PackageBreakdown({
  total,
  sealed,
  loose,
  unit,
  fresh = true,
  className,
}: {
  total: string;
  sealed: Array<
    Pick<SealedRow, "name" | "code" | "count" | "item_packaging_id">
  >;
  loose: string;
  unit?: string;
  fresh?: boolean;
  className?: string;
}) {
  const u = unit ? ` ${unit}` : "";

  return (
    <div
      className={cn(
        "flex flex-wrap items-center gap-x-2 gap-y-1 text-sm",
        className,
      )}
      data-testid="package-breakdown"
    >
      {sealed
        .filter((row) => row.count > 0)
        .map((row) => (
          <span
            key={row.item_packaging_id}
            className="rounded-md bg-muted px-2 py-0.5 tabular-nums"
            data-testid="breakdown-sealed"
          >
            {row.count} × {packageLabel(row)}
          </span>
        ))}
      {Number(loose) > 0 && (
        <span
          className="rounded-md bg-muted px-2 py-0.5 tabular-nums"
          data-testid="breakdown-loose"
        >
          {trimQty(loose)}
          {u} loose
        </span>
      )}
      <span className="font-medium tabular-nums" data-testid="breakdown-total">
        = {trimQty(total)}
        {u}
      </span>
      {!fresh && <Badge variant="warning">Needs a package count</Badge>}
    </div>
  );
}
