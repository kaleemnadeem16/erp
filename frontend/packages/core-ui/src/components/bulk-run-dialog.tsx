import { useState, type ReactNode } from 'react';

import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';
import { getApiErrorMessage } from '../lib/api-errors';

interface Failure<T> {
  row: T;
  message: string;
}

/**
 * Change many records with the single-record endpoint, one request each and one after another, so one refusal never blocks or undoes the rest. What could not be
 * changed comes back by name with the server's own reason.
 */
export async function applyToEach<T>(rows: T[], change: (row: T) => Promise<unknown>, onProgress: (done: number) => void): Promise<{ done: T[]; failed: Array<Failure<T>> }> {
  const done: T[] = [];
  const failed: Array<Failure<T>> = [];
  for (const [index, row] of rows.entries()) {
    try {
      await change(row);
      done.push(row);
    } catch (failure) {
      failed.push({ row, message: getApiErrorMessage(failure) ?? 'The change was refused.' });
    }
    onProgress(index + 1);
  }

  return { done, failed };
}

/**
 * The confirmation for one bulk change: what will happen to how many records, the inputs it needs (`children`), live progress, then an honest result — how many were
 * changed and which were not and why — with a retry for just those. Mount it fresh each time it opens (give it a `key`) so an earlier result never carries over.
 */
export function BulkRunDialog<T extends { id: number }>({
  open,
  title,
  intro,
  rows,
  ready,
  run,
  labelOf,
  onClose,
  onChanged,
  children,
}: {
  open: boolean;
  title: string;
  intro: ReactNode;
  rows: T[];
  ready: boolean;
  run: (row: T) => Promise<unknown>;
  labelOf: (row: T) => string;
  onClose: () => void;
  onChanged: () => Promise<void> | void;
  children?: ReactNode;
}) {
  const [progress, setProgress] = useState<number | null>(null);
  const [result, setResult] = useState<{ done: number; failed: Array<Failure<T>> } | null>(null);
  const [remaining, setRemaining] = useState<T[]>(rows);
  const running = progress !== null;
  const finished = result !== null && result.failed.length === 0;

  async function start() {
    setProgress(0);
    const outcome = await applyToEach(remaining, run, setProgress);
    setProgress(null);
    setRemaining(outcome.failed.map((failure) => failure.row));
    setResult((previous) => ({ done: (previous?.done ?? 0) + outcome.done.length, failed: outcome.failed }));
    await onChanged();
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && !running && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="bulk-dialog">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{intro}</DialogDescription>
        </DialogHeader>
        {!finished && <div className="space-y-4">{children}</div>}
        {running && (
          <p role="status" className="text-sm text-muted-foreground" data-testid="bulk-progress">
            Changing {progress} of {remaining.length}…
          </p>
        )}
        {result && (
          <div role="status" className="space-y-2 rounded-md border p-3 text-sm" data-testid="bulk-result">
            <p className="font-medium">
              {result.done} changed{result.failed.length > 0 ? `, ${result.failed.length} could not be changed` : '.'}
            </p>
            {result.failed.length > 0 && (
              <ul className="list-disc space-y-1 pl-5 text-destructive">
                {result.failed.map((failure) => (
                  <li key={failure.row.id}>
                    {labelOf(failure.row)} — {failure.message}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={running}>
            {finished ? 'Done' : 'Cancel'}
          </Button>
          {!finished && (
            <Button onClick={() => void start()} disabled={!ready || running || remaining.length === 0} data-testid="bulk-confirm">
              {result ? `Retry ${remaining.length} that failed` : `Change ${remaining.length}`}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
