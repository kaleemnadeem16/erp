import { useEffect, useMemo, useState } from "react";
import { usePackageDrawsSuggest } from "@erp/core-api";
import { getApiErrorMessage } from "../../lib/api-errors";
import { drawStateFromLines, emptyDraw, linesFromDrawState, type DrawState } from "./draw-state";
import { applyDraw, drawTotal, isExactQuantity, toUnits } from "./quantity";
import type { PackageBalance, SuggestedDraw } from "./types";

/**
 * The shared planning of "how do I take this much from these packages": the wanted quantity, the server's proposal, the person's edits, and the totals
 * and leftovers derived from them. Used by issue and transfer alike, so the two never drift apart.
 */
export function usePackageDrawPlan({
  open,
  itemId,
  balance,
  canOpen,
}: {
  open: boolean;
  itemId: number;
  balance: PackageBalance;
  canOpen: boolean;
}) {
  const suggest = usePackageDrawsSuggest();
  const [wanted, setWanted] = useState("");
  const [draw, setDraw] = useState<DrawState>(emptyDraw);
  const [adjusting, setAdjusting] = useState(false);
  const [requiresOpening, setRequiresOpening] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setWanted("");
    setDraw(emptyDraw);
    setAdjusting(false);
    setMessage(null);
  }, [open]);

  // Propose a draw whenever the wanted quantity settles (not on every keystroke).
  useEffect(() => {
    if (!open || adjusting || !isExactQuantity(wanted) || toUnits(wanted) <= 0n) return;
    const handle = setTimeout(() => {
      setMessage(null);
      suggest.mutate(
        { data: { item_id: itemId, location_id: balance.location.id, batch_id: balance.batch_id, quantity: wanted.trim() } },
        {
          onSuccess: (response) => {
            const proposal = (response as unknown as { data: { data: SuggestedDraw } }).data.data;
            setDraw(drawStateFromLines(proposal.lines));
            setRequiresOpening(proposal.requires_opening);
          },
          onError: (error) => {
            setDraw(emptyDraw);
            setMessage(getApiErrorMessage(error) ?? "Could not work out what to take.");
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

  return { wanted, setWanted, draw, setDraw, adjusting, setAdjusting, requiresOpening, message, setMessage, lines, total, after, matchesWanted, opens, blockedByOpen, valid: lines.length > 0 && after.valid && matchesWanted && !blockedByOpen };
}

export type PackageDrawPlan = ReturnType<typeof usePackageDrawPlan>;
