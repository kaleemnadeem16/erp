import { describeLoadError } from '../../lib/api-errors';
import { Button } from '../ui/button';

export interface ListErrorProps {
  /** The screen's own context, e.g. "Couldn't load warehouses." */
  message?: string;
  /** The failure itself. When given, its cause is spelled out under the message. */
  error?: unknown;
  /** When given, offers a "Try again" button. */
  onRetry?: () => void;
}

/**
 * What every list shows when its request failed. A bare "something went wrong" left a person unable to
 * tell a missing permission from a bad filter from a server fault from being offline — and so unsure
 * whether to sign in, change the filter or simply wait — so the cause is spelled out from the failure
 * itself (`describeLoadError`) and the person can retry without reloading the page.
 */
export function ListError({ message, error, onRetry }: ListErrorProps) {
  return (
    <div role="alert" className="flex flex-col items-center gap-2 p-6 text-center">
      <p className="text-sm font-medium text-destructive">{message ?? 'Something went wrong loading this list.'}</p>
      {error !== undefined && error !== null && (
        <p className="text-sm text-muted-foreground">{describeLoadError(error)}</p>
      )}
      {onRetry && (
        <Button type="button" variant="outline" size="sm" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  );
}
