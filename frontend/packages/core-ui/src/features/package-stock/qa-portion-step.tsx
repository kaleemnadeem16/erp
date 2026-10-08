import { useEffect, useMemo, useState } from "react";
import { useQualityDispositionsPortionPreview } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { PackageBreakdown } from "./package-breakdown";
import { PackageDrawEditor } from "./package-draw-editor";
import { drawStateFromLines, linesFromDrawState, type DrawState } from "./draw-state";
import { fromUnits, packageLabel, toUnits, trimQty } from "./quantity";
import type { DrawLine, SealedRow } from "./types";
import type { ReturnLine } from "./receipt-reversal-panel";

interface PortionPreview {
  pending: string;
  held: { sealed: SealedRow[]; loose: string; total: string };
  suggested: Array<{ kind: "SEALED" | "LOOSE"; packaging_id: number | null; count: number | null; quantity: string | null }> | null;
  message: string | null;
}

export type QaPortion = { portion: ReturnLine[]; ready: boolean };

/** Whether an outcome changes what packages are usable (REWORK and PENDING DECISION leave them held). */
export function decidesPackages(outcome: string): boolean {
  return ["PASS", "ACCEPT_WITH_CONCESSION", "RETURN_TO_SUPPLIER", "SCRAP"].includes(outcome);
}

const RESULT: Record<string, string> = {
  PASS: "released to usable stock",
  ACCEPT_WITH_CONCESSION: "released to usable stock",
  RETURN_TO_SUPPLIER: "rejected — awaiting return",
  SCRAP: "rejected — awaiting scrap",
};

/**
 * The "which packages" step of a QA disposition for a grouped item (ADR 0094): what is still held, the proposed portion the decision applies to, and the same
 * draw editor to change it. The portion must be whole sealed packages and loose quantity that are held and add up to the decision quantity; a sealed package is never split.
 */
export function QaPortionStep({
  lineId,
  outcome,
  quantity,
  unit,
  onChange,
}: {
  lineId: number;
  outcome: string;
  quantity: string;
  unit: string;
  onChange: (value: QaPortion) => void;
}) {
  const valid = /^\d+(\.\d{1,4})?$/.test(quantity.trim()) && Number(quantity) > 0;
  const query = useQualityDispositionsPortionPreview(lineId, { quantity: quantity.trim() }, { query: { enabled: valid, retry: false } });
  const response = query.data as unknown as { status: number; data: { data: PortionPreview } } | undefined;
  const preview = response?.status === 200 ? response.data.data : undefined;
  const suggestion = useMemo(
    () => drawStateFromLines((preview?.suggested ?? []).map((row) => (row.kind === "SEALED" ? { kind: "SEALED" as const, packaging_id: row.packaging_id ?? 0, count: row.count ?? 0 } : { kind: "LOOSE" as const, quantity: row.quantity ?? "0" }))),
    [preview?.suggested],
  );
  const [state, setState] = useState<DrawState>(suggestion);
  const [adjusting, setAdjusting] = useState(false);
  useEffect(() => {
    setState(suggestion);
    setAdjusting(!preview?.suggested);
  }, [suggestion, preview?.suggested]);

  const held = preview?.held;
  const lines = linesFromDrawState(state).filter((entry): entry is ReturnLine => entry.kind !== "OPEN_AND_DRAW");
  let total = Number(state.loose) > 0 ? toUnits(state.loose) : 0n;
  let covered = toUnits(state.loose || "0") <= toUnits(held?.loose ?? "0");
  for (const row of held?.sealed ?? []) {
    const count = state.whole[row.item_packaging_id] ?? 0;
    total += toUnits(row.content_quantity) * BigInt(count);
    if (count > row.count) covered = false;
  }
  const adds = valid && total === toUnits(quantity);
  const ready = !!preview && covered && adds && lines.length > 0;

  useEffect(() => {
    onChange({ portion: lines, ready });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(lines), ready]);

  if (!valid) return null;
  if (query.isLoading) return <p className="text-sm text-muted-foreground">Checking the held packages…</p>;
  if (!preview || !held) {
    return (
      <p role="alert" className="text-sm text-destructive" data-testid="qa-portion-error">
        {(query.error as { message?: string } | null)?.message ?? "Could not read the held packages."}
      </p>
    );
  }

  return (
    <div className="space-y-2 rounded-md border p-3 md:col-span-4" data-testid="qa-portion-step">
      <h3 className="text-sm font-medium">Which packages does this apply to?</h3>
      <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="qa-held">
        <span className="text-muted-foreground">Held now:</span>
        <PackageBreakdown total={held.total} sealed={held.sealed} loose={held.loose} unit={unit} />
      </div>
      <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="qa-portion">
        <span className="text-muted-foreground">This decision:</span>
        {lines.length === 0 ? (
          <span className="text-muted-foreground">nothing chosen yet</span>
        ) : (
          <span className="tabular-nums">
            {[...lines]
              .sort((a, b) => Number(b.kind === "SEALED") - Number(a.kind === "SEALED"))
              .map((entry) => (entry.kind === "SEALED" ? `${entry.count} sealed ${packageLabel(held.sealed.find((row) => row.item_packaging_id === entry.packaging_id) ?? { name: "package" })}` : `${trimQty(entry.quantity)} ${unit} loose`))
              .join(" + ")}
            {" = "}
            {trimQty(fromUnits(total))} {unit} {RESULT[outcome] ? `— ${RESULT[outcome]}` : ""}
          </span>
        )}
        <span className={covered && adds ? "text-status-success" : "text-destructive"} data-testid="qa-portion-check">
          {covered && adds ? "✓ held" : !covered ? "✗ not held" : `✗ must add up to ${trimQty(quantity)} ${unit}`}
        </span>
      </div>
      {preview.message && <p className="text-sm text-muted-foreground">{preview.message}</p>}
      <p className="text-xs text-muted-foreground">A sealed package is released or rejected whole; it is never opened or split here.</p>
      <Button type="button" variant="outline" size="sm" onClick={() => setAdjusting((value) => !value)} data-testid="qa-adjust">
        {adjusting ? "Hide editor" : "Change the packages"}
      </Button>
      {adjusting && <PackageDrawEditor sealed={held.sealed} loose={held.loose} unit={unit} value={state} onChange={setState} canOpen={false} />}
    </div>
  );
}

export type { DrawLine };
