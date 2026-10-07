import { useEffect, useMemo, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useLocationsIndex, usePackageBalancesIndex, useStockTransfersStore } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { formatLocationOption } from "../../lib/format-location";
import { CountLocationDialog } from "./count-location-dialog";
import { DrawPlanSection } from "./draw-plan-section";
import { PackageBreakdown } from "./package-breakdown";
import { applyArrival, trimQty } from "./quantity";
import { usePackageDrawPlan } from "./use-package-draw-plan";
import { REASON_HINTS, type PackageBalance, type PackagingOption } from "./types";

function newKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : String(Date.now());
}

/**
 * Move stock of a package-tracked item to another location by what physically moves. The source side is planned exactly like an issue (same editor); the
 * destination is shown before and after, and is blocked — with a way to count it — when it holds stock that has no current package count. Whole sealed
 * packages arrive sealed; loose stock, and anything taken from an opened package, arrives loose.
 */
export function TransferPackageStockDialog({
  open,
  onOpenChange,
  itemId,
  itemLabel,
  unit,
  source,
  canOpen,
  canCount,
  tracked,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemId: number;
  itemLabel: string;
  unit: string;
  source: PackageBalance;
  canOpen: boolean;
  canCount: boolean;
  tracked: PackagingOption[];
}) {
  const queryClient = useQueryClient();
  const transfer = useStockTransfersStore();
  const plan = usePackageDrawPlan({ open, itemId, balance: source, canOpen });
  const requestKey = useRef(newKey());
  const [destinationId, setDestinationId] = useState<number | null>(null);
  const [reason, setReason] = useState("Stock transfer");
  const [reference, setReference] = useState("");
  const [counting, setCounting] = useState(false);

  useEffect(() => {
    if (!open) return;
    requestKey.current = newKey();
    setDestinationId(null);
  }, [open]);

  const locations = useLocationsIndex({ per_page: 100, sort: "code" }, { query: { enabled: open } });
  const options = useMemo(() => {
    const rows = ((locations.data as unknown as { status: number; data: { data: Array<{ id: number; active?: boolean }> } } | undefined)?.status === 200
      ? (locations.data as unknown as { data: { data: Array<{ id: number; active?: boolean }> } }).data.data
      : []) as Array<Parameters<typeof formatLocationOption>[0] & { id: number; active?: boolean }>;

    return rows.filter((row) => row.id !== source.location.id && row.active !== false);
  }, [locations.data, source.location.id]);

  const destinationQuery = usePackageBalancesIndex({ item_id: itemId, location_id: destinationId ?? 0 }, { query: { enabled: open && destinationId !== null, retry: false } });
  const destinationRows = (((destinationQuery.data as unknown as { data?: { data?: PackageBalance[] } })?.data?.data ?? []) as PackageBalance[]).filter(
    (row) => row.location.id === destinationId && row.batch_id === source.batch_id,
  );
  const destinationRow = destinationRows[0];
  const destination: Pick<PackageBalance, "sealed" | "loose" | "total" | "fresh"> = destinationRow ?? { sealed: [], loose: "0.0000", total: "0.0000", fresh: true };
  const holdsStock = Number(destination.total) > 0;
  const needsCount = destinationId !== null && !destinationQuery.isLoading && holdsStock && !destination.fresh;
  const destinationLabel = options.find((row) => row.id === destinationId);
  const arrival = applyArrival(destination, source, plan.lines);

  const canSubmit = plan.valid && destinationId !== null && !destinationQuery.isLoading && !needsCount && reason.trim() !== "" && !transfer.isPending;
  const submit = () => {
    if (destinationId === null) return;
    plan.setMessage(null);
    transfer.mutate(
      {
        data: {
          item_id: itemId,
          from_location_id: source.location.id,
          to_location_id: destinationId,
          batch_id: source.batch_id,
          request_key: requestKey.current,
          reason: reason.trim(),
          reference: reference.trim() || null,
          quantity: plan.total,
          draw: plan.lines.map((line) =>
            line.kind === "LOOSE"
              ? { kind: line.kind, quantity: line.quantity }
              : line.kind === "SEALED"
                ? { kind: line.kind, packaging_id: line.packaging_id, count: line.count }
                : { kind: line.kind, packaging_id: line.packaging_id, count: line.count, quantity: line.quantity },
          ),
        },
      },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries({ predicate: (query) => String(query.queryKey[0]).startsWith("/api/v1/package-balances") });
          onOpenChange(false);
        },
        onError: (error) => {
          const code = getApiReasonCode(error);
          plan.setMessage([getApiErrorMessage(error), code ? REASON_HINTS[code] : null].filter(Boolean).join(" "));
        },
      },
    );
  };

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto" data-testid="transfer-dialog">
          <DialogHeader>
            <DialogTitle>Transfer stock</DialogTitle>
            <DialogDescription>
              {itemLabel} · from {source.location.name}
            </DialogDescription>
          </DialogHeader>

          <section className="space-y-1" data-testid="transfer-source">
            <h3 className="text-sm font-medium">From {source.location.warehouse_name} — {source.location.name}</h3>
            <PackageBreakdown total={source.total} sealed={source.sealed} loose={source.loose} unit={unit} fresh={source.fresh} />
          </section>

          <section className="space-y-2">
            <label htmlFor="transfer-destination" className="text-sm font-medium">To</label>
            <select
              id="transfer-destination"
              className="w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={destinationId ?? ""}
              onChange={(event) => setDestinationId(event.target.value === "" ? null : Number(event.target.value))}
            >
              <option value="">Choose a destination…</option>
              {options.map((row) => (
                <option key={row.id} value={row.id}>
                  {formatLocationOption(row)}
                </option>
              ))}
            </select>
            {destinationId !== null && !destinationQuery.isLoading && (
              <div className="space-y-1" data-testid="transfer-destination-now">
                <h3 className="text-sm font-medium">{destinationLabel ? formatLocationOption(destinationLabel) : "Destination"} now</h3>
                {holdsStock ? (
                  <PackageBreakdown total={destination.total} sealed={destination.sealed} loose={destination.loose} unit={unit} fresh={destination.fresh} />
                ) : (
                  <p className="text-sm text-muted-foreground">No stock of this item there yet.</p>
                )}
              </div>
            )}
            {needsCount && (
              <div className="space-y-2 rounded-md border border-destructive/40 p-3" data-testid="transfer-needs-count">
                <p className="text-sm text-destructive">
                  This location holds {trimQty(destination.total)} {unit} that has no current package count. Count it before moving stock there.
                </p>
                {canCount ? (
                  <Button size="sm" variant="outline" onClick={() => setCounting(true)} data-testid="count-destination">
                    Count destination first…
                  </Button>
                ) : (
                  <p className="text-sm text-muted-foreground">Ask someone who may count stock to count it first.</p>
                )}
              </div>
            )}
          </section>

          <DrawPlanSection plan={plan} balance={source} unit={unit} canOpen={canOpen} wantedLabel="How much do you want to move?" wantedId="transfer-wanted" />

          {plan.lines.length > 0 && destinationId !== null && !needsCount && (
            <section className="space-y-1" data-testid="transfer-arrival">
              <h3 className="text-sm font-medium">Destination afterwards</h3>
              <PackageBreakdown total={arrival.total} sealed={arrival.sealed} loose={arrival.loose} unit={unit} />
              <p className="text-xs text-muted-foreground">Whole sealed packages arrive sealed; loose stock, and anything taken from an opened package, arrives loose.</p>
            </section>
          )}

          <section className="grid gap-2 sm:grid-cols-2">
            <div className="space-y-1">
              <label htmlFor="transfer-reason" className="text-sm font-medium">Reason</label>
              <Input id="transfer-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            <div className="space-y-1">
              <label htmlFor="transfer-reference" className="text-sm font-medium">Reference (optional)</label>
              <Input id="transfer-reference" value={reference} onChange={(event) => setReference(event.target.value)} />
            </div>
          </section>

          <DialogFooter>
            <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button onClick={submit} disabled={!canSubmit} data-testid="transfer-submit">
              {transfer.isPending ? "Transferring…" : `Transfer ${trimQty(plan.total)} ${unit}`}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      {counting && destinationId !== null && (
        <CountLocationDialog
          open
          onOpenChange={(value) => !value && setCounting(false)}
          itemId={itemId}
          itemLabel={itemLabel}
          unit={unit}
          locationId={destinationId}
          locationLabel={destinationLabel ? formatLocationOption(destinationLabel) : "the destination"}
          batchId={source.batch_id}
          total={destination.total}
          tracked={tracked}
          onDone={() => void destinationQuery.refetch()}
        />
      )}
    </>
  );
}
