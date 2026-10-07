import { useEffect, useMemo, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { usePackageDrawsSuggest, useStockIssuesStore } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { PackageBreakdown } from "./package-breakdown";
import { PackageDrawEditor } from "./package-draw-editor";
import {
  drawStateFromLines,
  emptyDraw,
  linesFromDrawState,
  type DrawState,
} from "./draw-state";
import {
  applyDraw,
  drawTotal,
  isExactQuantity,
  packageLabel,
  toUnits,
  trimQty,
} from "./quantity";
import {
  REASON_HINTS,
  type DrawLine,
  type PackageBalance,
  type SuggestedDraw,
} from "./types";

function describeLine(
  line: DrawLine & { name?: string },
  balance: PackageBalance,
  unit: string,
): string {
  if (line.kind === "LOOSE")
    return `Take ${trimQty(line.quantity)} ${unit} loose`;
  const row = balance.sealed.find(
    (candidate) => candidate.item_packaging_id === line.packaging_id,
  );
  const name = packageLabel(row ?? { name: line.name });
  if (line.kind === "SEALED") return `Take ${line.count} sealed ${name}`;

  return `Open ${line.count} ${name} and take ${trimQty(line.quantity)} ${unit} from it`;
}

/**
 * Issue stock of a package-tracked item by what is physically taken. The person says how much they need; the server proposes the draw (loose first, then
 * whole packages, then one opened for the rest) and the result is always shown as the total next to the physical breakdown it leaves. Opening a package
 * is one more line of the same draw, not a separate step.
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
  const suggest = usePackageDrawsSuggest();
  const issue = useStockIssuesStore();
  const requestKey = useRef<string>(
    typeof crypto !== "undefined" && "randomUUID" in crypto
      ? crypto.randomUUID()
      : String(Date.now()),
  );
  const [wanted, setWanted] = useState("");
  const [draw, setDraw] = useState<DrawState>(emptyDraw);
  const [adjusting, setAdjusting] = useState(false);
  const [requiresOpening, setRequiresOpening] = useState(false);
  const [reason, setReason] = useState("Stock issue");
  const [reference, setReference] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    requestKey.current =
      typeof crypto !== "undefined" && "randomUUID" in crypto
        ? crypto.randomUUID()
        : String(Date.now());
    setWanted("");
    setDraw(emptyDraw);
    setAdjusting(false);
    setMessage(null);
  }, [open]);

  // Propose a draw whenever the wanted quantity settles (not on every keystroke).
  useEffect(() => {
    if (!open || adjusting || !isExactQuantity(wanted) || toUnits(wanted) <= 0n)
      return;
    const handle = setTimeout(() => {
      setMessage(null);
      suggest.mutate(
        {
          data: {
            item_id: itemId,
            location_id: balance.location.id,
            batch_id: balance.batch_id,
            quantity: wanted.trim(),
          },
        },
        {
          onSuccess: (response) => {
            const proposal = (
              response as unknown as { data: { data: SuggestedDraw } }
            ).data.data;
            setDraw(drawStateFromLines(proposal.lines));
            setRequiresOpening(proposal.requires_opening);
          },
          onError: (error) => {
            setDraw(emptyDraw);
            setMessage(
              getApiErrorMessage(error) ?? "Could not work out what to take.",
            );
          },
        },
      );
    }, 400);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wanted, open, adjusting, itemId, balance.location.id, balance.batch_id]);

  const lines = useMemo(() => linesFromDrawState(draw), [draw]);
  const total = drawTotal(balance, lines);
  const after = applyDraw(balance, lines);
  const matchesWanted = wanted === "" || toUnits(wanted) === toUnits(total);
  const opens = lines.some((line) => line.kind === "OPEN_AND_DRAW");
  const blockedByOpen = opens && !canOpen;
  const canSubmit =
    lines.length > 0 &&
    after.valid &&
    matchesWanted &&
    !blockedByOpen &&
    reason.trim() !== "" &&
    !issue.isPending;

  const submit = () => {
    setMessage(null);
    issue.mutate(
      {
        data: {
          item_id: itemId,
          location_id: balance.location.id,
          batch_id: balance.batch_id,
          request_key: requestKey.current,
          reason: reason.trim(),
          reference: reference.trim() || null,
          quantity: total,
          draw: lines.map((line) =>
            line.kind === "LOOSE"
              ? { kind: line.kind, quantity: line.quantity }
              : line.kind === "SEALED"
                ? {
                    kind: line.kind,
                    packaging_id: line.packaging_id,
                    count: line.count,
                  }
                : {
                    kind: line.kind,
                    packaging_id: line.packaging_id,
                    count: line.count,
                    quantity: line.quantity,
                  },
          ),
        },
      },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries({
            predicate: (query) =>
              String(query.queryKey[0]).startsWith("/api/v1/package-balances"),
          });
          onOpenChange(false);
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[90vh] max-w-2xl overflow-y-auto"
        data-testid="issue-dialog"
      >
        <DialogHeader>
          <DialogTitle>Issue stock</DialogTitle>
          <DialogDescription>
            {itemLabel} · {balance.location.name}
          </DialogDescription>
        </DialogHeader>

        <section className="space-y-1">
          <h3 className="text-sm font-medium">On hand now</h3>
          <PackageBreakdown
            total={balance.total}
            sealed={balance.sealed}
            loose={balance.loose}
            unit={unit}
            fresh={balance.fresh}
          />
        </section>

        <section className="space-y-2">
          <label htmlFor="issue-wanted" className="text-sm font-medium">
            How much do you need? ({unit})
          </label>
          <Input
            id="issue-wanted"
            inputMode="decimal"
            className="w-40"
            value={wanted}
            onChange={(event) => {
              setAdjusting(false);
              setWanted(event.target.value);
            }}
            placeholder="e.g. 12"
          />
        </section>

        {lines.length > 0 && (
          <section className="space-y-2" data-testid="proposed-draw">
            <h3 className="text-sm font-medium">
              {adjusting ? "What you are taking" : "Proposed"}
            </h3>
            <ul className="list-disc space-y-1 pl-5 text-sm">
              {lines.map((line, index) => (
                <li key={index}>{describeLine(line, balance, unit)}</li>
              ))}
            </ul>
            <div className="text-sm" data-testid="leaves">
              <span className="text-muted-foreground">Leaves: </span>
              <PackageBreakdown
                total={after.total}
                sealed={after.sealed}
                loose={after.loose}
                unit={unit}
              />
            </div>
            {!matchesWanted && (
              <p className="text-sm text-status-warning">
                This takes {trimQty(total)} {unit}, not the {trimQty(wanted)}{" "}
                {unit} you asked for.
              </p>
            )}
            {blockedByOpen && (
              <p className="text-sm text-destructive">
                Opening a package needs permission you do not have; take whole
                packages or loose stock instead.
              </p>
            )}
          </section>
        )}

        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setAdjusting((value) => !value)}
          >
            {adjusting ? "Use the proposal" : "Adjust what is taken"}
          </Button>
        </div>
        {adjusting && (
          <PackageDrawEditor
            sealed={balance.sealed}
            loose={balance.loose}
            unit={unit}
            value={draw}
            onChange={setDraw}
            canOpen={canOpen}
          />
        )}
        {requiresOpening && !adjusting && !canOpen && (
          <p className="text-sm text-status-warning">
            The proposal needs a package opened.
          </p>
        )}

        <section className="grid gap-2 sm:grid-cols-2">
          <div className="space-y-1">
            <label htmlFor="issue-reason" className="text-sm font-medium">
              Reason
            </label>
            <Input
              id="issue-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </div>
          <div className="space-y-1">
            <label htmlFor="issue-reference" className="text-sm font-medium">
              Job or reference (optional)
            </label>
            <Input
              id="issue-reference"
              value={reference}
              onChange={(event) => setReference(event.target.value)}
            />
          </div>
        </section>

        {message && (
          <p
            role="alert"
            className="text-sm text-destructive"
            data-testid="issue-error"
          >
            {message}
          </p>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            onClick={submit}
            disabled={!canSubmit}
            data-testid="issue-submit"
          >
            {issue.isPending ? "Issuing…" : `Issue ${trimQty(total)} ${unit}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
