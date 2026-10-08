import { useMemo } from 'react';
import { Trash2 } from 'lucide-react';
import { useUomsShow } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { PackageBreakdown } from '../package-stock/package-breakdown';
import { PackageDrawEditor } from '../package-stock/package-draw-editor';
import { drawStateFromLines, linesFromDrawState, type DrawState } from '../package-stock/draw-state';
import { isExactQuantity, packageLabel, toUnits, trimQty } from '../package-stock/quantity';
import { REASON_HINTS, type DrawLine } from '../package-stock/types';
import { useTrackingState } from '../package-stock/use-tracking-state';
import type { Blocker, ExitConfig, PortionLine, PreviewLine } from './rejected-exit-api';

/** Wording that differs from the QA screen, where the same code is about held (not rejected) packages. */
const EXIT_HINTS: Record<string, string> = {
  NEEDS_DECLARED_PORTION: 'Choose which rejected packages leave.',
};

/** One delivery line on the document being edited. */
export interface DraftLine {
  key: string;
  goods_receipt_line_id: number;
  /** Where it came from, for the heading ("Receipt GRN-0007"); absent when an existing draft is reopened. */
  source?: string;
  quantity: string;
  outcomeChangeReason: string;
  /** The packages chosen by hand; null means "the system's proposal" and nothing is sent. */
  portion: PortionLine[] | null;
}

function toDrawLines(portion: PortionLine[]): DrawLine[] {
  return portion.map((entry) =>
    entry.kind === 'SEALED' ? { kind: 'SEALED' as const, packaging_id: entry.packaging_id ?? 0, count: entry.count ?? 0 } : { kind: 'LOOSE' as const, quantity: entry.quantity ?? '0' },
  );
}

/** The portion that will be posted: the hand-picked one, else the system's proposal. */
export function effectivePortion(line: DraftLine, preview: PreviewLine | undefined): PortionLine[] | null {
  return line.portion ?? preview?.proposed_portion ?? null;
}

/** Whether a grouped line's chosen packages are rejected stock and add up to the quantity. Non-grouped lines are always fine. */
export function portionAddsUp(line: DraftLine, preview: PreviewLine | undefined): boolean {
  if (!preview?.grouped) return true;
  const portion = effectivePortion(line, preview);
  if (!portion || portion.length === 0) return false;
  const rejected = preview.rejected_packages;
  if (!rejected) return false;
  let total = 0n;
  for (const entry of portion) {
    if (entry.kind === 'LOOSE') {
      if (toUnits(entry.quantity) > toUnits(rejected.loose)) return false;
      total += toUnits(entry.quantity);
    } else {
      const row = rejected.sealed.find((candidate) => candidate.item_packaging_id === entry.packaging_id);
      if (!row || (entry.count ?? 0) > row.count) return false;
      total += toUnits(row.content_quantity) * BigInt(entry.count ?? 0);
    }
  }

  return total === toUnits(line.quantity);
}

/**
 * What stops a line, as the person should see it. Two codes are handled beside their own fields (the change reason) or by the screen itself: the
 * proposal is sent as the declared portion, so "declare a portion" is not a blocker while one is proposed or chosen.
 */
export function activeBlockers(line: DraftLine, preview: PreviewLine | undefined): Blocker[] {
  const portionKnown = effectivePortion(line, preview) !== null;

  return (preview?.blockers ?? []).filter(
    (blocker) => blocker.code !== 'OUTCOME_CHANGE_REASON_REQUIRED' && !(blocker.code === 'NEEDS_DECLARED_PORTION' && portionKnown),
  );
}

/** Why this line cannot be saved yet, in the operator's words; null when it is ready. */
export function lineProblem(line: DraftLine, preview: PreviewLine | undefined): string | null {
  if (!isExactQuantity(line.quantity) || Number(line.quantity) <= 0) return 'Enter the quantity to take out.';
  if (!preview) return null;
  if (preview.outcome_change_required && line.outcomeChangeReason.trim() === '') return REASON_HINTS.OUTCOME_CHANGE_REASON_REQUIRED ?? null;
  if (!portionAddsUp(line, preview)) return 'Choose the rejected packages that add up to the quantity.';

  return null;
}

export function ExitLineCard({
  config,
  line,
  preview,
  readOnly = false,
  showErrors,
  onChange,
  onRemove,
}: {
  config: ExitConfig;
  line: DraftLine;
  preview: PreviewLine | undefined;
  readOnly?: boolean;
  /** Reveal field-level problems (after the first save attempt or once the person has typed). */
  showErrors: boolean;
  onChange: (next: DraftLine) => void;
  onRemove?: () => void;
}) {
  const tracking = useTrackingState(preview?.item?.id ?? 0, !!preview?.grouped);
  const uom = useUomsShow(tracking?.stock_uom_id ?? 0, { query: { enabled: !!tracking } });
  const unit = uom.data?.status === 200 ? uom.data.data.data.code : '';
  const rejected = preview?.rejected_packages;

  const state: DrawState = useMemo(
    () => drawStateFromLines(toDrawLines(effectivePortion(line, preview) ?? [])),
    [line, preview],
  );
  const portion = effectivePortion(line, preview) ?? [];
  const problem = lineProblem(line, preview);
  const quantityBad = !isExactQuantity(line.quantity) || Number(line.quantity) <= 0;
  const overAvailable = !!preview?.available && !quantityBad && toUnits(line.quantity) > toUnits(preview.available);
  const reasonMissing = !!preview?.outcome_change_required && line.outcomeChangeReason.trim() === '';
  // The reason field says its own problem beside the field; the list is for everything else that stops the line.
  const listed = activeBlockers(line, preview);

  function setQuantity(value: string) {
    // A hand-picked package list was for the old quantity; fall back to the system's proposal.
    onChange({ ...line, quantity: value, portion: null });
  }

  return (
    <article className="space-y-3 rounded-md border p-3" data-testid="exit-line">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-sm font-medium break-words">
            {preview?.item ? `${preview.item.code} — ${preview.item.name}` : `Delivery line #${line.goods_receipt_line_id}`}
          </h3>
          {line.source && <p className="text-xs text-muted-foreground">{line.source}</p>}
        </div>
        {!readOnly && onRemove && (
          <Button type="button" variant="ghost" size="sm" onClick={onRemove} aria-label="Remove this line">
            <Trash2 className="h-4 w-4" />
            Remove
          </Button>
        )}
      </header>

      {preview && (
        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-3" data-testid="exit-line-facts">
          <div>
            <dt className="text-muted-foreground">Rejected on this delivery</dt>
            <dd className="tabular-nums">{trimQty(preview.rejected)} {unit}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Already taken out</dt>
            <dd className="tabular-nums">{trimQty(preview.exited)} {unit}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Still to take out</dt>
            <dd className="tabular-nums font-medium" data-testid="exit-line-available">{trimQty(preview.available)} {unit}</dd>
          </div>
        </dl>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          <Label htmlFor={`qty-${line.key}`}>Quantity to {config.kind === 'SCRAP' ? 'scrap' : 'return'}</Label>
          <Input
            id={`qty-${line.key}`}
            inputMode="decimal"
            value={line.quantity}
            disabled={readOnly}
            onChange={(event) => setQuantity(event.target.value)}
            aria-invalid={showErrors && (quantityBad || overAvailable)}
          />
          {showErrors && quantityBad && <p className="text-xs text-destructive">Enter a quantity greater than zero (up to four decimals).</p>}
          {overAvailable && <p className="text-xs text-destructive">Only {trimQty(preview?.available)} {unit} is still there to take out.</p>}
          {preview?.remaining_after != null && !quantityBad && !overAvailable && (
            <p className="text-xs text-muted-foreground" data-testid="exit-line-remaining">
              Rejected stock left after this: {trimQty(preview.remaining_after)} {unit}
            </p>
          )}
        </div>
        {preview?.cost?.unit_cost != null && (
          <div className="space-y-1 text-sm">
            <span className="text-muted-foreground">Stock value per unit</span>
            <p className="tabular-nums" data-testid="exit-line-cost">{trimQty(preview.cost.unit_cost)}</p>
          </div>
        )}
      </div>

      {preview?.outcome_change_required && (
        <div className="space-y-1" data-testid="outcome-change">
          <p className="text-sm">
            <Badge variant="warning">Different from the quality decision</Badge>{' '}
            <span className="text-muted-foreground">
              {trimQty(preview.differing_qty)} {unit} of this was not decided as {config.kind === 'SCRAP' ? 'scrap' : 'a return to the supplier'}.
            </span>
          </p>
          <Label htmlFor={`why-${line.key}`}>Why is it leaving this way?</Label>
          <Textarea
            id={`why-${line.key}`}
            rows={2}
            value={line.outcomeChangeReason}
            disabled={readOnly}
            onChange={(event) => onChange({ ...line, outcomeChangeReason: event.target.value })}
            aria-invalid={showErrors && reasonMissing}
          />
          {showErrors && reasonMissing && <p className="text-xs text-destructive">{REASON_HINTS.OUTCOME_CHANGE_REASON_REQUIRED}</p>}
        </div>
      )}

      {preview?.grouped && rejected && (
        <div className="space-y-2 rounded-md bg-muted/40 p-3" data-testid="exit-portion">
          <h4 className="text-sm font-medium">Which rejected packages leave?</h4>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-muted-foreground">Rejected now:</span>
            <PackageBreakdown total={rejected.total} sealed={rejected.sealed} loose={rejected.loose} unit={unit} />
          </div>
          <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="exit-portion-chosen">
            <span className="text-muted-foreground">Leaving:</span>
            {portion.length === 0 ? (
              <span className="text-muted-foreground">nothing chosen yet</span>
            ) : (
              <span className="tabular-nums">
                {[...portion]
                  .sort((a, b) => Number(b.kind === 'SEALED') - Number(a.kind === 'SEALED'))
                  .map((entry) =>
                    entry.kind === 'SEALED'
                      ? `${entry.count} sealed ${packageLabel(rejected.sealed.find((row) => row.item_packaging_id === entry.packaging_id) ?? { name: 'package' })}`
                      : `${trimQty(entry.quantity)} ${unit} loose`,
                  )
                  .join(' + ')}
              </span>
            )}
            <span className={portionAddsUp(line, preview) ? 'text-status-success' : 'text-destructive'} data-testid="exit-portion-check">
              {portionAddsUp(line, preview) ? '✓ adds up' : `✗ must add up to ${trimQty(line.quantity)} ${unit}`}
            </span>
          </div>
          {preview.portion_message && !line.portion && <p className="text-sm text-muted-foreground">{preview.portion_message}</p>}
          <p className="text-xs text-muted-foreground">A sealed package leaves whole; it is never opened or split here.</p>
          {!readOnly && (
            <PackageDrawEditor
              sealed={rejected.sealed}
              loose={rejected.loose}
              unit={unit}
              value={state}
              onChange={(next) => onChange({ ...line, portion: portionFromState(next) })}
              canOpen={false}
            />
          )}
        </div>
      )}

      {listed.length > 0 && (
        <ul className="space-y-1" role="alert" data-testid="exit-line-blockers">
          {listed.map((blocker) => (
            <li key={blocker.code} className="text-sm text-destructive" data-reason-code={blocker.code}>
              {EXIT_HINTS[blocker.code] ?? REASON_HINTS[blocker.code] ?? blocker.message}
            </li>
          ))}
        </ul>
      )}
      {showErrors && problem && listed.length === 0 && !reasonMissing && !quantityBad && !overAvailable && (
        <p className="text-xs text-destructive">{problem}</p>
      )}
    </article>
  );
}

function portionFromState(state: DrawState): PortionLine[] {
  return linesFromDrawState(state)
    .filter((entry): entry is Exclude<DrawLine, { kind: 'OPEN_AND_DRAW' }> => entry.kind !== 'OPEN_AND_DRAW')
    .map((entry) =>
      entry.kind === 'SEALED'
        ? { kind: 'SEALED' as const, packaging_id: entry.packaging_id, count: entry.count }
        : { kind: 'LOOSE' as const, quantity: entry.quantity },
    );
}
