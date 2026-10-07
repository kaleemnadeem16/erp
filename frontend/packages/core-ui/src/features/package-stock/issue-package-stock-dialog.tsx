import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useStockIssuesStore } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { DrawPlanSection } from "./draw-plan-section";
import { PackageBreakdown } from "./package-breakdown";
import { trimQty } from "./quantity";
import { usePackageDrawPlan } from "./use-package-draw-plan";
import { REASON_HINTS, type PackageBalance } from "./types";

function newKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : String(Date.now());
}

/**
 * Issue stock of a package-tracked item by what is physically taken. The person says how much they need; the server proposes the draw (loose first, then
 * whole packages, then one opened for the rest) and the result is always shown as the total next to the physical breakdown it leaves.
 */
export function IssuePackageStockDialog({
  open,
  onOpenChange,
  itemId,
  itemLabel,
  unit,
  balance,
  canOpen,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemId: number;
  itemLabel: string;
  unit: string;
  balance: PackageBalance;
  canOpen: boolean;
}) {
  const queryClient = useQueryClient();
  const issue = useStockIssuesStore();
  const plan = usePackageDrawPlan({ open, itemId, balance, canOpen });
  const requestKey = useRef(newKey());
  const [reason, setReason] = useState("Stock issue");
  const [reference, setReference] = useState("");

  useEffect(() => {
    if (open) requestKey.current = newKey();
  }, [open]);

  const canSubmit = plan.valid && reason.trim() !== "" && !issue.isPending;
  const submit = () => {
    plan.setMessage(null);
    issue.mutate(
      {
        data: {
          item_id: itemId,
          location_id: balance.location.id,
          batch_id: balance.batch_id,
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto" data-testid="issue-dialog">
        <DialogHeader>
          <DialogTitle>Issue stock</DialogTitle>
          <DialogDescription>
            {itemLabel} · {balance.location.name}
          </DialogDescription>
        </DialogHeader>

        <section className="space-y-1">
          <h3 className="text-sm font-medium">On hand now</h3>
          <PackageBreakdown total={balance.total} sealed={balance.sealed} loose={balance.loose} unit={unit} fresh={balance.fresh} />
        </section>

        <DrawPlanSection plan={plan} balance={balance} unit={unit} canOpen={canOpen} wantedLabel="How much do you need?" wantedId="issue-wanted" />

        <section className="grid gap-2 sm:grid-cols-2">
          <div className="space-y-1">
            <label htmlFor="issue-reason" className="text-sm font-medium">Reason</label>
            <Input id="issue-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
          <div className="space-y-1">
            <label htmlFor="issue-reference" className="text-sm font-medium">Job or reference (optional)</label>
            <Input id="issue-reference" value={reference} onChange={(event) => setReference(event.target.value)} />
          </div>
        </section>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button onClick={submit} disabled={!canSubmit} data-testid="issue-submit">
            {issue.isPending ? "Issuing…" : `Issue ${trimQty(plan.total)} ${unit}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
