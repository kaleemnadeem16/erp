import { useEffect, useState } from "react";
import { usePhysicalCountLinesComposition } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { DecimalInput } from "../../components/ui/decimal-input";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { Stepper } from "./package-draw-editor";
import { fromUnits, isExactQuantity, packageLabel, toUnits, trimQty } from "./quantity";
import { REASON_HINTS, type PackagingOption } from "./types";
import type { CountedRow } from "./counted-composition";

/**
 * Records what is on the shelf for one grouped count line: whole sealed packages of each tracked size plus the loose quantity. The counted total is the
 * exact sum. Nothing of the book is shown (blind counting): the person counts what they see, and the reviewer decides what the difference means.
 */
export function CountCompositionDialog({
  open,
  onOpenChange,
  lineId,
  itemLabel,
  locationLabel,
  unit,
  tracked,
  initial,
  onDone,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  lineId: number;
  itemLabel: string;
  locationLabel: string;
  unit: string;
  tracked: PackagingOption[];
  initial: CountedRow[];
  onDone: () => void;
}) {
  const record = usePhysicalCountLinesComposition();
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [loose, setLoose] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    const next: Record<number, number> = {};
    for (const row of initial) if (row.packaging_id !== null) next[row.packaging_id] = row.count ?? 0;
    setCounts(next);
    setLoose(trimQty(initial.filter((row) => row.packaging_id === null).reduce((sum, row) => sum + Number(row.quantity), 0)) || "");
    setMessage(null);
  }, [open, initial]);

  const looseValid = loose.trim() === "" || (isExactQuantity(loose) && toUnits(loose) >= 0n);
  const sealedUnits = tracked.reduce((sum, level) => sum + toUnits(level.stock_quantity) * BigInt(counts[level.id] ?? 0), 0n);
  const total = sealedUnits + (looseValid && loose.trim() !== "" ? toUnits(loose) : 0n);

  const submit = () => {
    setMessage(null);
    const composition: Array<{ packaging_id?: number; count?: number; quantity?: string }> = tracked
      .filter((level) => (counts[level.id] ?? 0) > 0)
      .map((level) => ({ packaging_id: level.id, count: counts[level.id] }));
    if (loose.trim() !== "" && toUnits(loose) > 0n) composition.push({ quantity: fromUnits(toUnits(loose)) });
    record.mutate(
      { physicalCountLine: lineId, data: { composition } },
      {
        onSuccess: () => {
          onDone();
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
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="count-composition-dialog">
        <DialogHeader>
          <DialogTitle>Count {locationLabel} by package</DialogTitle>
          <DialogDescription>{itemLabel}</DialogDescription>
        </DialogHeader>
        <p className="text-sm">Count what is physically on the shelf: whole sealed packages, then everything else as loose {unit}.</p>
        {tracked.map((level) => (
          <div key={level.id} className="flex items-center justify-between gap-2 text-sm">
            <span>Sealed {packageLabel(level)}</span>
            <Stepper label={`sealed ${packageLabel(level)}`} value={counts[level.id] ?? 0} max={100000} onChange={(count) => setCounts((current) => ({ ...current, [level.id]: count }))} />
          </div>
        ))}
        <div className="space-y-1">
          <label htmlFor="count-loose-input" className="text-sm font-medium">Loose / open {unit}</label>
          <DecimalInput id="count-loose-input" rule={{ spec: "stockLedger", sign: "nonNegative" }} value={loose} onChange={setLoose} placeholder="0" aria-invalid={!looseValid} />
        </div>
        <p className="text-sm font-medium tabular-nums" data-testid="count-total">
          Counted: {trimQty(fromUnits(total))} {unit}
        </p>
        {message && <p role="alert" className="text-sm text-destructive">{message}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button onClick={submit} disabled={!looseValid || record.isPending} data-testid="count-composition-submit">
            {record.isPending ? "Saving…" : "Save count"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
