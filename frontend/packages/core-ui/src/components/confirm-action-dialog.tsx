import { useEffect, useState, type ReactNode } from 'react';

import { getApiErrorMessage } from '../lib/api-errors';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';

/**
 * Asks before something a person cannot take back (a delete, an archive with no way back), says what will happen, and on a refusal shows the server's own
 * words — "still used by …" — instead of closing as though it worked. The caller supplies the action; it is awaited, so the button shows its pending state and
 * the dialog stays open until the server has answered.
 */
export function ConfirmActionDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  pendingLabel,
  cancelLabel = 'Keep it',
  destructive = true,
  onConfirm,
  failureMessage = 'The change was refused.',
  testId,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  /** What will happen, and what will not. */
  description: ReactNode;
  confirmLabel: string;
  pendingLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  onConfirm: () => Promise<unknown>;
  failureMessage?: string;
  testId?: string;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) setError(null);
  }, [open]);

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      await onConfirm();
      onOpenChange(false);
    } catch (failure) {
      setError(getApiErrorMessage(failure) ?? failureMessage);
    } finally {
      setPending(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="max-h-[90dvh] max-w-md overflow-y-auto" data-testid={testId}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription asChild>
            <div>{description}</div>
          </DialogDescription>
        </DialogHeader>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <DialogFooter className="gap-2 sm:gap-0">
          <Button type="button" variant="outline" className="h-11 md:h-9" disabled={pending} onClick={() => onOpenChange(false)}>
            {cancelLabel}
          </Button>
          <Button type="button" variant={destructive ? 'destructive' : 'default'} className="h-11 md:h-9" disabled={pending} onClick={() => void confirm()}>
            {pending ? (pendingLabel ?? 'Working…') : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
