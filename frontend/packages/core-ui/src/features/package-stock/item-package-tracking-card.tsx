import { useState } from "react";
import {
  useItemsPackageTrackingShow,
  usePackageBalancesIndex,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { usePermission } from "../../hooks/use-permission";
import { ActivationWizard } from "./activation-wizard";
import { IssuePackageStockDialog } from "./issue-package-stock-dialog";
import { PackageBreakdown } from "./package-breakdown";
import { packageLabel } from "./quantity";
import type { PackageBalance, TrackingState } from "./types";

/**
 * The item's package-tracking state. A quantity-only item shows one quiet line (and, to people who may, the way to start grouped tracking); a grouped item
 * shows its tracked package sizes and, per location, the stock total together with its physical breakdown.
 */
export function ItemPackageTrackingCard({
  itemId,
  itemLabel,
  unit,
  isStocked,
}: {
  itemId: number;
  itemLabel: string;
  unit: string;
  isStocked: boolean;
}) {
  const mayIssue = usePermission("inventory.package-state.issue");
  const mayOpen = usePermission("inventory.package-state.open");
  const mayView = usePermission("inventory.package-state.view");
  const tracking = useItemsPackageTrackingShow(itemId, {
    query: { enabled: isStocked, retry: false },
  });
  const state =
    (
      tracking.data as unknown as
        { status: number; data: { data: TrackingState } } | undefined
    )?.status === 200
      ? (tracking.data as unknown as { data: { data: TrackingState } }).data
          .data
      : undefined;
  const grouped = state?.mode === "PACKAGE_BALANCE";
  const balances = usePackageBalancesIndex(
    { item_id: itemId },
    { query: { enabled: grouped && mayView, retry: false } },
  );
  const rows = (
    ((balances.data as unknown as { data?: { data?: PackageBalance[] } })?.data
      ?.data ?? []) as PackageBalance[]
  ).filter((row) => Number(row.total) > 0 || row.operation_id !== null);
  const [wizard, setWizard] = useState(false);
  const [issuing, setIssuing] = useState<PackageBalance | null>(null);

  if (!isStocked || !state) return null;

  return (
    <div className="space-y-3" data-testid="package-tracking-card">
      {!grouped ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-muted-foreground">
            Tracked by quantity only.
          </p>
          {state.can_activate && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setWizard(true)}
              data-testid="start-grouped-tracking"
            >
              Start grouped tracking…
            </Button>
          )}
        </div>
      ) : (
        <>
          <p className="text-sm" data-testid="tracking-status">
            Grouped package tracking
            {state.activated_at
              ? ` since ${new Date(state.activated_at).toLocaleDateString()}`
              : ""}
            . Tracked packages:{" "}
            {state.tracked_levels
              .map((level) => packageLabel(level))
              .join(", ") || "none"}
            .
          </p>
          {rows.length === 0 && (
            <p className="text-sm text-muted-foreground">No stock on hand.</p>
          )}
          {rows.map((row) => (
            <div
              key={`${row.location.id}-${row.batch_id ?? "none"}`}
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
              data-testid="package-balance-row"
            >
              <div className="space-y-1">
                <p className="text-sm font-medium">
                  {row.location.warehouse_name} — {row.location.name}
                </p>
                <PackageBreakdown
                  total={row.total}
                  sealed={row.sealed}
                  loose={row.loose}
                  unit={unit}
                  fresh={row.fresh}
                />
              </div>
              {mayIssue && row.fresh && Number(row.total) > 0 && (
                <Button
                  size="sm"
                  onClick={() => setIssuing(row)}
                  data-testid="issue-stock"
                >
                  Issue stock…
                </Button>
              )}
            </div>
          ))}
        </>
      )}
      {wizard && (
        <ActivationWizard
          open={wizard}
          onOpenChange={setWizard}
          itemId={itemId}
          itemLabel={itemLabel}
          unit={unit}
          state={state}
        />
      )}
      {issuing && (
        <IssuePackageStockDialog
          open
          onOpenChange={(open) => !open && setIssuing(null)}
          itemId={itemId}
          itemLabel={itemLabel}
          unit={unit}
          balance={issuing}
          canOpen={mayOpen}
        />
      )}
    </div>
  );
}
