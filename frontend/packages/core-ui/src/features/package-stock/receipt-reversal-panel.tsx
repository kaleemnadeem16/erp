import { useEffect, useMemo, useState } from "react";
import { useGoodsReceiptsReversalPreview } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { PackageBreakdown } from "./package-breakdown";
import { PackageDrawEditor } from "./package-draw-editor";
import { drawStateFromLines, linesFromDrawState, type DrawState } from "./draw-state";
import { fromUnits, packageLabel, toUnits, trimQty } from "./quantity";
import type { DrawLine, SealedRow } from "./types";

interface Blocker {
  code: string;
  message: string;
}

interface PreviewLine {
  goods_receipt_line_id: number;
  item_id: number;
  quantity: string;
  grouped: boolean;
  blockers: Blocker[];
  observed?: Array<{ kind: "SEALED" | "LOOSE"; packaging_id: number | null; count: number | null; quantity: string | null }> | null;
  on_hand?: { total: string; sealed: SealedRow[]; loose: string; fresh: boolean } | null;
  suggested?: PreviewLine["observed"];
  suggested_changed?: boolean;
  coverable?: boolean;
  shortfall?: string[];
}

interface Preview {
  status: string;
  blockers: Blocker[];
  lines: PreviewLine[];
}

export type ReturnLine = Extract<DrawLine, { kind: "SEALED" | "LOOSE" }>;

export interface ReversalDraws {
  /** The `package_draws` payload for the reversal request (grouped lines only). */
  draws: Array<{ goods_receipt_line_id: number; lines: ReturnLine[] }>;
  /** True while something prevents the reversal: a server blocker or a draw that is not on hand or does not add up. */
  blocked: boolean;
}

function toDrawLines(rows: PreviewLine["observed"]): ReturnLine[] {
  return (rows ?? []).map((row) =>
    row.kind === "SEALED"
      ? { kind: "SEALED" as const, packaging_id: row.packaging_id ?? 0, count: row.count ?? 0 }
      : { kind: "LOOSE" as const, quantity: row.quantity ?? "0" },
  );
}

/** Whether a draw is covered by what is on hand, and what it adds up to. Mirrors the server's checks so the person sees the problem before confirming. */
function assess(state: DrawState, line: PreviewLine): { total: bigint; covered: boolean } {
  const onHand = line.on_hand;
  if (!onHand) return { total: 0n, covered: false };
  let total = Number(state.loose) > 0 ? toUnits(state.loose) : 0n;
  let covered = toUnits(state.loose || "0") <= toUnits(onHand.loose);
  for (const row of onHand.sealed) {
    const count = state.whole[row.item_packaging_id] ?? 0;
    total += toUnits(row.content_quantity) * BigInt(count);
    if (count > row.count) covered = false;
  }

  return { total, covered };
}

function ReversalLine({
  line,
  label,
  unit,
  onDraw,
}: {
  line: PreviewLine;
  label: string;
  unit: string;
  onDraw: (lineId: number, lines: ReturnLine[], ok: boolean) => void;
}) {
  const initial = useMemo(() => drawStateFromLines(toDrawLines(line.suggested)), [line.suggested]);
  const [state, setState] = useState<DrawState>(initial);
  const [adjusting, setAdjusting] = useState(!line.suggested);
  useEffect(() => {
    setState(initial);
  }, [initial]);

  const { total, covered } = assess(state, line);
  const blocked = line.blockers.some((b) => b.code !== "NEEDS_DECLARED_DRAW");
  const adds = total === toUnits(line.quantity);
  // A reversal removes whole sealed packages and loose quantity only; opening is never part of it.
  const lines = linesFromDrawState(state).filter((entry): entry is ReturnLine => entry.kind !== "OPEN_AND_DRAW");

  useEffect(() => {
    onDraw(line.goods_receipt_line_id, lines, !blocked && covered && adds && lines.length > 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(lines), blocked, covered, adds]);

  const sealedRows = line.on_hand?.sealed ?? [];
  const name = (id: number | null) => packageLabel(sealedRows.find((row) => row.item_packaging_id === id) ?? { name: "package" });

  return (
    <div className="space-y-2 rounded-md border p-3" data-testid="reversal-line">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-sm font-medium">{label}</h3>
        <span className="text-sm text-muted-foreground">Received {trimQty(line.quantity)} {unit}</span>
      </div>
      {line.blockers.map((blocker) => (
        <p key={blocker.code} role="alert" className="text-sm text-destructive" data-testid={`reversal-blocker-${blocker.code}`}>
          {blocker.message}
        </p>
      ))}
      {line.on_hand && (
        <div className="space-y-1 text-sm">
          <div className="flex flex-wrap items-center gap-2" data-testid="reversal-on-hand">
            <span className="text-muted-foreground">On hand now:</span>
            <PackageBreakdown total={line.on_hand.total} sealed={line.on_hand.sealed} loose={line.on_hand.loose} unit={unit} />
          </div>
          <div className="flex flex-wrap items-center gap-2" data-testid="reversal-draw">
            <span className="text-muted-foreground">Returning to supplier:</span>
            {lines.length === 0 ? (
              <span className="text-muted-foreground">nothing chosen yet</span>
            ) : (
              <span className="tabular-nums">
                {[...lines].sort((a, b) => Number(b.kind === "SEALED") - Number(a.kind === "SEALED")).map((entry) => (entry.kind === "SEALED" ? `${entry.count} sealed ${name(entry.packaging_id)}` : `${trimQty(entry.kind === "LOOSE" ? entry.quantity : "0")} ${unit} loose`)).join(" + ")}
                {" = "}
                {trimQty(fromUnits(total))} {unit}
              </span>
            )}
            <span className={covered && adds ? "text-status-success" : "text-destructive"} data-testid="reversal-check">
              {covered && adds ? "✓ on hand" : !covered ? "✗ not on hand" : `✗ must add up to ${trimQty(line.quantity)} ${unit}`}
            </span>
          </div>
          {line.suggested_changed && (
            <p className="text-status-warning" data-testid="reversal-changed">
              Stock has changed form since this arrived — please confirm what is actually going back.
            </p>
          )}
          {(line.shortfall ?? []).map((message) => (
            <p key={message} className="text-muted-foreground">
              {message}
            </p>
          ))}
          {!line.suggested && !line.blockers.some((b) => b.code === "NEEDS_DECLARED_DRAW") && !blocked && (
            <p className="text-destructive">What arrived is no longer on hand in a form that adds up: adjust what is going back, or correct the stock with an issue or count first.</p>
          )}
        </div>
      )}
      {line.on_hand && !blocked && (
        <>
          <Button type="button" variant="outline" size="sm" onClick={() => setAdjusting((value) => !value)} data-testid="reversal-adjust">
            {adjusting ? "Hide editor" : "Change what is going back"}
          </Button>
          {adjusting && <PackageDrawEditor sealed={line.on_hand.sealed} loose={line.on_hand.loose} unit={unit} value={state} onChange={setState} canOpen={false} />}
        </>
      )}
    </div>
  );
}

/**
 * The package part of a receipt reversal: for each grouped line, what arrived (the default), what is on hand now, and the editable draw that will
 * physically go back, plus every reason the reversal would be refused, in plain words. Quantity-only lines only show a blocker when their delivery was
 * already used. The server proves everything again when the reversal is confirmed.
 */
export function ReceiptReversalPanel({
  receiptId,
  describe,
  onChange,
}: {
  receiptId: number;
  describe: (itemId: number) => { label: string; unit: string };
  onChange: (value: ReversalDraws) => void;
}) {
  const query = useGoodsReceiptsReversalPreview(receiptId, { query: { retry: false } });
  const response = query.data as unknown as { status: number; data: { data: Preview } } | undefined;
  const preview = response?.status === 200 ? response.data.data : undefined;
  const [draws, setDraws] = useState<Record<number, { lines: ReturnLine[]; ok: boolean }>>({});

  const serverBlocked = !!preview && (preview.blockers.length > 0 || preview.lines.some((line) => line.blockers.some((b) => b.code !== "NEEDS_DECLARED_DRAW")));
  useEffect(() => {
    if (!preview) {
      onChange({ draws: [], blocked: query.isError });

      return;
    }
    const grouped = preview.lines.filter((line) => line.grouped);
    onChange({
      draws: grouped.map((line) => ({ goods_receipt_line_id: line.goods_receipt_line_id, lines: draws[line.goods_receipt_line_id]?.lines ?? [] })),
      blocked: serverBlocked || grouped.some((line) => !draws[line.goods_receipt_line_id]?.ok),
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preview, draws, serverBlocked, query.isError]);

  if (query.isLoading) return <p className="text-sm text-muted-foreground">Checking what can be reversed…</p>;
  if (query.isError || !preview) {
    return (
      <p role="alert" className="text-sm text-destructive" data-testid="reversal-preview-error">
        {(query.error as { message?: string } | null)?.message ?? "Could not check this reversal."}
      </p>
    );
  }
  const relevant = preview.lines.filter((line) => line.grouped || line.blockers.length > 0);
  if (relevant.length === 0 && preview.blockers.length === 0) return null;

  return (
    <div className="space-y-3" data-testid="reversal-panel">
      {preview.blockers.map((blocker) => (
        <p key={blocker.code} role="alert" className="text-sm text-destructive" data-testid={`reversal-blocker-${blocker.code}`}>
          {blocker.message}
        </p>
      ))}
      {relevant.map((line) => {
        const { label, unit } = describe(line.item_id);

        return (
          <ReversalLine
            key={line.goods_receipt_line_id}
            line={line}
            label={label}
            unit={unit}
            onDraw={(lineId, lines, ok) => setDraws((current) => (current[lineId] && JSON.stringify(current[lineId]) === JSON.stringify({ lines, ok }) ? current : { ...current, [lineId]: { lines, ok } }))}
          />
        );
      })}
    </div>
  );
}
