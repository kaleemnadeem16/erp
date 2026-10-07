import { useEffect, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  useGoodsReceiptLinesPackageCompositionUpdate,
  useItemsPackageTrackingShow,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { Stepper } from "./package-draw-editor";
import { fromUnits, packageLabel, toUnits, trimQty } from "./quantity";
import { REASON_HINTS, type TrackingState } from "./types";

export interface ObservedRow {
  item_packaging_id: number | null;
  form: string;
  sealed_count: number | null;
  stock_quantity: string;
}

/**
 * "What arrived": the physical composition of a delivery line of a package-tracked item — sealed packages of the tracked sizes, the rest loose. Required
 * before the receipt posts; the agreed (commercial) package never decides it. Quantity-only items render nothing here.
 */
export function ReceiptCompositionPanel({
  lineId,
  itemId,
  stockQty,
  unit,
  editable,
  observed,
  commercialPackagingId,
  commercialPackageCount,
  onChanged,
  onNeedsComposition,
}: {
  lineId: number;
  itemId: number;
  stockQty: string;
  unit: string;
  editable: boolean;
  observed: ObservedRow[];
  commercialPackagingId: number | null;
  commercialPackageCount: string | null;
  onChanged: () => void;
  /** Reports whether this line is package-tracked and still has no recorded arrival, so the screen can hold the Post button. */
  onNeedsComposition?: (lineId: number, needs: boolean) => void;
}) {
  const queryClient = useQueryClient();
  const tracking = useItemsPackageTrackingShow(itemId, {
    query: { retry: false },
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
  const save = useGoodsReceiptLinesPackageCompositionUpdate();
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (state) onNeedsComposition?.(lineId, grouped && observed.length === 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state?.mode, observed.length]);

  useEffect(() => {
    if (!state) return;
    const next: Record<number, number> = {};
    if (observed.length > 0) {
      for (const row of observed)
        if (row.item_packaging_id !== null && row.sealed_count)
          next[row.item_packaging_id] = row.sealed_count;
    } else if (
      commercialPackagingId &&
      state.tracked_levels.some(
        (level) => level.id === commercialPackagingId,
      ) &&
      commercialPackageCount
    ) {
      // A suggestion only: the agreed package, if it is a tracked size. The person confirms what physically arrived.
      next[commercialPackagingId] = Math.floor(Number(commercialPackageCount));
    }
    setCounts(next);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state?.item_id, observed.length]);

  const levels = state?.tracked_levels ?? [];
  const loose = useMemo(
    () =>
      toUnits(stockQty) -
      levels.reduce(
        (sum, level) =>
          sum + toUnits(level.stock_quantity) * BigInt(counts[level.id] ?? 0),
        0n,
      ),
    [stockQty, levels, counts],
  );

  if (!grouped) return null;

  if (!editable) {
    return (
      <div
        className="space-y-1 text-sm"
        data-testid="receipt-composition-readonly"
      >
        <span className="text-muted-foreground">What arrived</span>
        {observed.length === 0 ? (
          <div className="text-muted-foreground">
            No physical composition recorded.
          </div>
        ) : (
          <div className="tabular-nums">
            {observed.map((row, index) => (
              <span key={index} className="mr-3">
                {row.form === "SEALED"
                  ? `${row.sealed_count} × package #${row.item_packaging_id}`
                  : `${trimQty(row.stock_quantity)} ${unit} loose`}
              </span>
            ))}
          </div>
        )}
      </div>
    );
  }

  const submit = () => {
    setMessage(null);
    const composition: Array<{
      packaging_id?: number;
      count?: number;
      quantity?: string;
    }> = levels
      .filter((level) => (counts[level.id] ?? 0) > 0)
      .map((level) => ({ packaging_id: level.id, count: counts[level.id] }));
    if (loose > 0n) composition.push({ quantity: fromUnits(loose) });
    save.mutate(
      {
        goodsReceiptLine: lineId,
        data: { reason: "Counted on arrival", composition },
      },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          onChanged();
        },
        onError: (error) => {
          const code = getApiReasonCode(error);
          setMessage(
            [getApiErrorMessage(error), code ? REASON_HINTS[code] : null]
              .filter(Boolean)
              .join(" "),
          );
        },
      },
    );
  };

  return (
    <div
      className="space-y-2 rounded-md border bg-muted/30 p-3"
      data-testid="receipt-composition-panel"
    >
      <p className="text-sm font-medium">What arrived</p>
      <p className="text-xs text-muted-foreground">
        Delivered: {trimQty(stockQty)} {unit}. Count the sealed packages; the
        rest is loose.
      </p>
      {!state && (
        <p className="text-sm text-muted-foreground">
          Loading the item&apos;s package sizes…
        </p>
      )}
      {levels.map((level) => (
        <div
          key={level.id}
          className="flex items-center justify-between gap-2 text-sm"
        >
          <span>Sealed {packageLabel(level)}</span>
          <Stepper
            label={`sealed ${packageLabel(level)}`}
            value={counts[level.id] ?? 0}
            max={Number(
              toUnits(stockQty) / toUnits(level.stock_quantity || "1"),
            )}
            onChange={(count) =>
              setCounts((current) => ({ ...current, [level.id]: count }))
            }
          />
        </div>
      ))}
      <p
        className={
          loose < 0n
            ? "text-sm text-destructive"
            : "text-sm text-muted-foreground"
        }
        data-testid="receipt-loose"
      >
        Loose: {trimQty(fromUnits(loose))} {unit}
      </p>
      {message && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      <Button
        size="sm"
        onClick={submit}
        disabled={!state || loose < 0n || save.isPending}
        data-testid="save-composition"
      >
        {save.isPending
          ? "Saving…"
          : observed.length > 0
            ? "Update what arrived"
            : "Save what arrived"}
      </Button>
      {observed.length > 0 && (
        <p className="text-xs text-status-success">Recorded.</p>
      )}
    </div>
  );
}
