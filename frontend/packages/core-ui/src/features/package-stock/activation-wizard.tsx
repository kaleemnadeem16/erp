import { useEffect, useMemo, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  useItemsPackageTrackingActivate,
  useItemsPackageTrackingPreview,
  usePackageBalancesIndex,
  usePackageCheckpointsStore,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { getApiErrorMessage, getApiReasonCode } from "../../lib/api-errors";
import { Stepper } from "./package-draw-editor";
import { fromUnits, packageLabel, toUnits, trimQty } from "./quantity";
import {
  REASON_HINTS,
  type PackageBalance,
  type PackagingOption,
  type TrackingState,
} from "./types";

interface PreviewScope {
  location_id: number;
  batch_id: number | null;
  quantity: string;
  operation_id: number | null;
  problems: string[];
}
interface Preview {
  ready: boolean;
  blockers: string[];
  scopes: PreviewScope[];
}

const STEPS = ["Readiness", "Tracked packages", "Opening count", "Confirm"];

function scopeKey(scope: {
  location_id: number;
  batch_id: number | null;
}): string {
  return `${scope.location_id}|${scope.batch_id ?? "none"}`;
}

/**
 * Starts grouped package tracking for an item (ADR 0091): what stands in the way, which package sizes to track, an opening count of every place the item is
 * stocked (sealed packages per size; the loose litres are simply the rest), then confirmation. Nothing is guessed: the counts are what the person observed.
 */
export function ActivationWizard({
  open,
  onOpenChange,
  itemId,
  itemLabel,
  unit,
  state,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemId: number;
  itemLabel: string;
  unit: string;
  state: TrackingState;
}) {
  const queryClient = useQueryClient();
  const preview = useItemsPackageTrackingPreview();
  const checkpoint = usePackageCheckpointsStore();
  const activate = useItemsPackageTrackingActivate();
  const balances = usePackageBalancesIndex(
    { item_id: itemId },
    { query: { enabled: open } },
  );
  const names = useMemo(() => {
    const rows = ((
      balances.data as unknown as { data?: { data?: PackageBalance[] } }
    )?.data?.data ?? []) as PackageBalance[];

    return new Map(
      rows.map((row) => [
        scopeKey({ location_id: row.location.id, batch_id: row.batch_id }),
        `${row.location.warehouse_name} — ${row.location.name}`,
      ]),
    );
  }, [balances.data]);
  const key = useRef("");
  const [step, setStep] = useState(0);
  const [report, setReport] = useState<Preview | null>(null);
  const [tracked, setTracked] = useState<number[]>([]);
  const [counts, setCounts] = useState<Record<string, Record<number, number>>>(
    {},
  );
  const [reason, setReason] = useState("");
  const [understood, setUnderstood] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    key.current =
      typeof crypto !== "undefined" && "randomUUID" in crypto
        ? crypto.randomUUID()
        : String(Date.now());
    setStep(0);
    setCounts({});
    setReason("");
    setUnderstood(false);
    setMessage(null);
    setTracked(
      state.packagings.filter((p) => !p.inner_packaging_id).map((p) => p.id),
    );
    preview.mutate(
      { item: itemId, data: { checkpoints: [] } },
      {
        onSuccess: (response) =>
          setReport(
            (response as unknown as { data: { data: Preview } }).data.data,
          ),
        onError: (error) => setMessage(getApiErrorMessage(error)),
      },
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const packagings: PackagingOption[] = state.packagings;
  const trackedOptions = packagings.filter((p) => tracked.includes(p.id));
  const itemBlockers = report?.blockers ?? [];
  const toggle = (option: PackagingOption) => {
    setTracked((current) => {
      if (current.includes(option.id)) {
        const holders = packagings.filter(
          (p) => p.inner_packaging_id === option.id && current.includes(p.id),
        );
        return holders.length > 0
          ? current
          : current.filter((id) => id !== option.id);
      }
      const withInner = [...current, option.id];
      let inner = option.inner_packaging_id ?? null;
      while (inner) {
        if (!withInner.includes(inner)) withInner.push(inner);
        inner =
          packagings.find((p) => p.id === inner)?.inner_packaging_id ?? null;
      }
      return withInner;
    });
  };

  const looseOf = (scope: PreviewScope): bigint => {
    const row = counts[scopeKey(scope)] ?? {};
    const sealedUnits = trackedOptions.reduce(
      (sum, option) =>
        sum + toUnits(option.stock_quantity) * BigInt(row[option.id] ?? 0),
      0n,
    );

    return toUnits(scope.quantity) - sealedUnits;
  };
  const countsOk = (report?.scopes ?? []).every(
    (scope) => looseOf(scope) >= 0n,
  );

  const run = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const operationIds: number[] = [];
      for (const scope of report?.scopes ?? []) {
        const row = counts[scopeKey(scope)] ?? {};
        const composition: Array<{
          packaging_id?: number;
          count?: number;
          quantity?: string;
        }> = trackedOptions
          .filter((option) => (row[option.id] ?? 0) > 0)
          .map((option) => ({
            packaging_id: option.id,
            count: row[option.id],
          }));
        const loose = looseOf(scope);
        if (loose > 0n) composition.push({ quantity: fromUnits(loose) });
        const response = await checkpoint.mutateAsync({
          data: {
            item_id: itemId,
            location_id: scope.location_id,
            batch_id: scope.batch_id,
            request_key: `activate-${key.current}-${scopeKey(scope)}`,
            reason: reason.trim() || "Opening count",
            expected_quantity: scope.quantity,
            composition,
          },
        });
        operationIds.push(
          (response as unknown as { data: { data: { operation_id: number } } })
            .data.data.operation_id,
        );
      }
      await activate.mutateAsync({
        item: itemId,
        data: {
          request_key: `activate-${key.current}`,
          reason: reason.trim(),
          checkpoints: operationIds,
          tracked_levels: tracked,
        },
      });
      void queryClient.invalidateQueries();
      onOpenChange(false);
    } catch (error) {
      const code = getApiReasonCode(error);
      setMessage(
        [getApiErrorMessage(error), code ? REASON_HINTS[code] : null]
          .filter(Boolean)
          .join(" "),
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[90vh] max-w-2xl overflow-y-auto"
        data-testid="activation-wizard"
      >
        <DialogHeader>
          <DialogTitle>Start grouped package tracking</DialogTitle>
          <DialogDescription>
            {itemLabel} · step {step + 1} of {STEPS.length}: {STEPS[step]}
          </DialogDescription>
        </DialogHeader>

        {step === 0 && (
          <section className="space-y-3" data-testid="wizard-readiness">
            {!report && !message && (
              <p className="text-sm text-muted-foreground">Checking…</p>
            )}
            {itemBlockers.length > 0 ? (
              <ul className="list-disc space-y-1 pl-5 text-sm text-destructive">
                {itemBlockers.map((blocker) => (
                  <li key={blocker}>{blocker}</li>
                ))}
              </ul>
            ) : (
              report && (
                <p className="text-sm">
                  Nothing stands in the way. Grouped tracking records stock as
                  sealed packages plus loose {unit}; the total stays the stock
                  quantity.
                </p>
              )
            )}
            {report && report.scopes.length > 0 && (
              <div>
                <p className="mb-1 text-sm font-medium">Stocked in</p>
                <ul className="space-y-1 text-sm">
                  {report.scopes.map((scope) => (
                    <li key={scopeKey(scope)} className="tabular-nums">
                      {names.get(scopeKey(scope)) ??
                        `Location ${scope.location_id}`}{" "}
                      — {trimQty(scope.quantity)} {unit}
                      {scope.problems
                        .filter(
                          (p) =>
                            p !== "A fresh counted checkpoint is required.",
                        )
                        .map((p) => (
                          <span key={p} className="ml-2 text-destructive">
                            {p}
                          </span>
                        ))}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {report && report.scopes.length === 0 && (
              <p className="text-sm text-muted-foreground">
                This item has no stock yet; tracking starts with its first
                receipt.
              </p>
            )}
          </section>
        )}

        {step === 1 && (
          <section className="space-y-2" data-testid="wizard-levels">
            <p className="text-sm">
              Which package sizes should be counted? The smallest sealed size is
              the normal choice; tracking a larger package also tracks what is
              inside it.
            </p>
            {packagings.length === 0 && (
              <p className="text-sm text-destructive">
                Add the item&apos;s packages (for example a 5 L can) before
                starting.
              </p>
            )}
            {packagings.map((option) => (
              <label
                key={option.id}
                className="flex items-center gap-2 text-sm"
              >
                <input
                  type="checkbox"
                  checked={tracked.includes(option.id)}
                  onChange={() => toggle(option)}
                />
                {packageLabel(option)}{" "}
                <span className="text-muted-foreground">
                  ({trimQty(option.stock_quantity)} {unit})
                </span>
              </label>
            ))}
          </section>
        )}

        {step === 2 && (
          <section className="space-y-4" data-testid="wizard-count">
            <p className="text-sm">
              Count what is physically on the shelf. The loose {unit} is
              whatever remains of the stock total.
            </p>
            {(report?.scopes ?? []).map((scope) => {
              const loose = looseOf(scope);
              return (
                <div
                  key={scopeKey(scope)}
                  className="space-y-2 rounded-md border p-3"
                  data-testid="wizard-scope"
                >
                  <p className="text-sm font-medium">
                    {names.get(scopeKey(scope)) ??
                      `Location ${scope.location_id}`}{" "}
                    — stock total {trimQty(scope.quantity)} {unit}
                  </p>
                  {trackedOptions.map((option) => (
                    <div
                      key={option.id}
                      className="flex items-center justify-between gap-2 text-sm"
                    >
                      <span>Sealed {packageLabel(option)}</span>
                      <Stepper
                        label={`sealed ${packageLabel(option)}`}
                        value={counts[scopeKey(scope)]?.[option.id] ?? 0}
                        max={Number(
                          toUnits(scope.quantity) /
                            toUnits(option.stock_quantity || "1"),
                        )}
                        onChange={(count) =>
                          setCounts((current) => ({
                            ...current,
                            [scopeKey(scope)]: {
                              ...(current[scopeKey(scope)] ?? {}),
                              [option.id]: count,
                            },
                          }))
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
                    data-testid="wizard-loose"
                  >
                    Loose: {trimQty(fromUnits(loose))} {unit}
                  </p>
                </div>
              );
            })}
          </section>
        )}

        {step === 3 && (
          <section className="space-y-3" data-testid="wizard-confirm">
            <label htmlFor="activation-reason" className="text-sm font-medium">
              Reason
            </label>
            <input
              id="activation-reason"
              className="w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="e.g. Track emulsion by sealed can"
            />
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1"
                checked={understood}
                onChange={(event) => setUnderstood(event.target.checked)}
              />
              I understand this cannot be undone for now. From here on this
              item&apos;s stock changes only through package-aware receipts,
              issues, transfers and counts.
            </label>
          </section>
        )}

        {message && (
          <p
            role="alert"
            className="text-sm text-destructive"
            data-testid="wizard-error"
          >
            {message}
          </p>
        )}
        <DialogFooter>
          <Button
            variant="outline"
            onClick={() =>
              step === 0 ? onOpenChange(false) : setStep(step - 1)
            }
          >
            {step === 0 ? "Cancel" : "Back"}
          </Button>
          {step < 3 ? (
            <Button
              data-testid="wizard-next"
              onClick={() => setStep(step + 1)}
              disabled={
                (step === 0 &&
                  (!report ||
                    itemBlockers.length > 0 ||
                    report.scopes.some((s) =>
                      s.problems.some(
                        (p) => p !== "A fresh counted checkpoint is required.",
                      ),
                    ))) ||
                (step === 1 && tracked.length === 0) ||
                (step === 2 && !countsOk)
              }
            >
              Next
            </Button>
          ) : (
            <Button
              data-testid="wizard-activate"
              onClick={run}
              disabled={busy || !understood || reason.trim() === ""}
            >
              {busy ? "Starting…" : "Start grouped tracking"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
