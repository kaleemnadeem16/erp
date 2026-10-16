import { useState } from "react";
import { usePhysicalCountLinesRefresh, useUomsShow, type PhysicalCountLineResource } from "@erp/core-api";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { formatLocation } from "../../lib/format-location";
import { getApiErrorMessage } from "../../lib/api-errors";
import { CountCompositionDialog } from "./count-composition-dialog";
import { CountedComposition, type CountedRow } from "./counted-composition";
import { useTrackingState } from "./use-tracking-state";
import { compareDecimal, formatDecimal } from "../../lib/decimal";

/** What a grouped line's count means, for someone who may see the book. Composition-only posts no stock movement; a variance posts a governed adjustment. */
export function countVerdict(variance: string | null, counted: boolean): { kind: "none" | "composition" | "loss" | "gain"; text: string } {
  if (!counted || variance === null) return { kind: "none", text: "" };
  const sign = compareDecimal(variance, "0");
  if (sign === 0) return { kind: "composition", text: "Same quantity — package composition is replaced, no stock movement" };
  const size = formatDecimal(variance.replace(/^-/, ""), { grouping: false });
  if (sign === -1) return { kind: "loss", text: `${size} short — stock adjustment of −${size}` };

  return { kind: "gain", text: `+${size} found — stock adjustment, needs a cost` };
}

/**
 * The counted cell of a grouped physical-count line: what was counted by package, a "count by package" action for counters, and — when stock moved
 * after the line was captured — a stale warning with the refresh that clears the count so it is recounted, never silently rebased.
 */
export function GroupedCountedCell({
  line,
  isDraft,
  mayCount,
  mayRefresh,
  onChanged,
  onError,
}: {
  line: PhysicalCountLineResource;
  isDraft: boolean;
  mayCount: boolean;
  mayRefresh: boolean;
  onChanged: () => void;
  onError: (message: string | null) => void;
}) {
  const [open, setOpen] = useState(false);
  const refresh = usePhysicalCountLinesRefresh();
  const tracking = useTrackingState(line.item_id, true);
  const uomQuery = useUomsShow(tracking?.stock_uom_id ?? 0, { query: { enabled: !!tracking } });
  const unit = uomQuery.data?.status === 200 ? uomQuery.data.data.data.code : "";
  const rows = (line.composition ?? []) as unknown as CountedRow[];
  const stale = isDraft && typeof line.stale_reason === "string" && line.stale_reason !== "";
  const itemLabel = line.item ? `${line.item.code} — ${line.item.name}` : String(line.item_id);

  return (
    <div className="flex flex-col items-end gap-1" data-testid="grouped-count-cell">
      {line.counted_qty !== null && tracking ? (
        <CountedComposition rows={rows} packagings={tracking.packagings} unit={unit} total={line.counted_qty} />
      ) : (
        <span className="text-muted-foreground">Not counted</span>
      )}
      {stale && (
        <Badge variant="destructive" data-testid="stale-line" title={line.stale_reason ?? undefined}>
          Stock changed since this line was captured
        </Badge>
      )}
      <div className="flex gap-1">
        {isDraft && mayCount && !stale && tracking && (
          <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)} data-testid="count-by-package">
            {line.counted_qty === null ? "Count by package…" : "Recount…"}
          </Button>
        )}
        {stale && mayRefresh && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={refresh.isPending}
            data-testid="refresh-line"
            onClick={() => {
              onError(null);
              refresh.mutate({ physicalCountLine: line.id }, { onSuccess: onChanged, onError: (error) => onError(getApiErrorMessage(error)) });
            }}
          >
            Refresh and recount
          </Button>
        )}
      </div>
      {tracking && (
        <CountCompositionDialog
          open={open}
          onOpenChange={setOpen}
          lineId={line.id}
          itemLabel={itemLabel}
          locationLabel={formatLocation(line.location) ?? String(line.location_id)}
          unit={unit}
          tracked={tracking.tracked_levels}
          initial={rows}
          onDone={onChanged}
        />
      )}
    </div>
  );
}
