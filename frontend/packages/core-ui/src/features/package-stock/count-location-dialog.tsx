import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { usePackageCheckpointsStore } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { Stepper } from "./package-draw-editor";
import { fromUnits, packageLabel, toUnits, trimQty } from "./quantity";
import { REASON_HINTS, type PackagingOption } from "./types";

function newKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : String(Date.now());
}

/**
 * Counts one location by package — sealed packages per tracked size, the loose litres being the rest of the stock total — so its package evidence is
 * current again. Records what was observed; it never changes the stock quantity (a quantity variance is a physical count, a separate workflow).
 */
export function CountLocationDialog({
  open,
  onOpenChange,
  itemId,
  itemLabel,
  unit,
  locationId,
  locationLabel,
  batchId,
  total,
  tracked,
  onDone,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemId: number;
  itemLabel: string;
  unit: string;
  locationId: number;
  locationLabel: string;
  batchId: number | null;
  total: string;
  tracked: PackagingOption[];
  onDone?: () => void;
}) {
  const queryClient = useQueryClient();
  const checkpoint = usePackageCheckpointsStore();
  const key = useRef(newKey());
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [reason, setReason] = useState("Package count");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    key.current = newKey();
    setCounts({});
    setMessage(null);
  }, [open]);

  const sealed = tracked.reduce((sum, level) => sum + toUnits(level.stock_quantity) * BigInt(counts[level.id] ?? 0), 0n);
  const loose = toUnits(total) - sealed;

  const submit = () => {
    setMessage(null);
    const composition: Array<{ packaging_id?: number; count?: number; quantity?: string }> = tracked
      .filter((level) => (counts[level.id] ?? 0) > 0)
      .map((level) => ({ packaging_id: level.id, count: counts[level.id] }));
    if (loose > 0n) composition.push({ quantity: fromUnits(loose) });
    checkpoint.mutate(
      { data: { item_id: itemId, location_id: locationId, batch_id: batchId, request_key: key.current, reason: reason.trim(), expected_quantity: total, composition } },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries({ predicate: (query) => String(query.queryKey[0]).startsWith("/api/v1/package-balances") });
          onDone?.();
          onOpenChange(false);
        },
        onError: (error) => {
          const code = getApiReasonCode(error);
          setMessage([getApiErrorMessage(error), code ? REASON_HINTS[code] : null].filter(Boolean).join(" "));
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="count-location-dialog">
        <DialogHeader>
          <DialogTitle>Count {locationLabel} by package</DialogTitle>
          <DialogDescription>
            {itemLabel} · stock total {trimQty(total)} {unit}
          </DialogDescription>
        </DialogHeader>
        <p className="text-sm">Count what is physically on the shelf. The loose {unit} is whatever remains of the stock total.</p>
        {tracked.map((level) => (
          <div key={level.id} className="flex items-center justify-between gap-2 text-sm">
            <span>Sealed {packageLabel(level)}</span>
            <Stepper
              label={`sealed ${packageLabel(level)}`}
              value={counts[level.id] ?? 0}
              max={Number(toUnits(total) / toUnits(level.stock_quantity || "1"))}
              onChange={(count) => setCounts((current) => ({ ...current, [level.id]: count }))}
            />
          </div>
        ))}
        <p className={loose < 0n ? "text-sm text-destructive" : "text-sm text-muted-foreground"} data-testid="count-loose">
          Loose: {trimQty(fromUnits(loose))} {unit}
        </p>
        <div className="space-y-1">
          <label htmlFor="count-reason" className="text-sm font-medium">Reason</label>
          <Input id="count-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
        {message && <p role="alert" className="text-sm text-destructive">{message}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button onClick={submit} disabled={loose < 0n || reason.trim() === "" || checkpoint.isPending} data-testid="count-submit">
            {checkpoint.isPending ? "Saving…" : "Save count"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
