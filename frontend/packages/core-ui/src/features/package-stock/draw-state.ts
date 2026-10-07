import type { DrawLine } from "./types";

/** The draw as a person edits it: litres loose, whole packages per level, and packages opened (with the litres taken from them). */
export interface DrawState {
  loose: string;
  whole: Record<number, number>;
  open: Record<number, { count: number; quantity: string }>;
}

export const emptyDraw: DrawState = { loose: "", whole: {}, open: {} };

export function drawStateFromLines(
  lines: Array<DrawLine & { quantity?: string }>,
): DrawState {
  const state: DrawState = { loose: "", whole: {}, open: {} };
  for (const line of lines) {
    if (line.kind === "LOOSE") state.loose = line.quantity;
    else if (line.kind === "SEALED")
      state.whole[line.packaging_id] =
        (state.whole[line.packaging_id] ?? 0) + line.count;
    else
      state.open[line.packaging_id] = {
        count: line.count,
        quantity: line.quantity,
      };
  }

  return state;
}

export function linesFromDrawState(state: DrawState): DrawLine[] {
  const lines: DrawLine[] = [];
  if (state.loose.trim() !== "" && Number(state.loose) > 0)
    lines.push({ kind: "LOOSE", quantity: state.loose.trim() });
  for (const [id, count] of Object.entries(state.whole)) {
    if (count > 0)
      lines.push({ kind: "SEALED", packaging_id: Number(id), count });
  }
  for (const [id, open] of Object.entries(state.open)) {
    if (
      open.count > 0 &&
      open.quantity.trim() !== "" &&
      Number(open.quantity) > 0
    ) {
      lines.push({
        kind: "OPEN_AND_DRAW",
        packaging_id: Number(id),
        count: open.count,
        quantity: open.quantity.trim(),
      });
    }
  }

  return lines;
}
