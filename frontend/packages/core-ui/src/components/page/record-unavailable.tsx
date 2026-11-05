import { isApiError } from '../../lib/api-errors';
import { Skeleton } from '../ui/skeleton';

/** What a record screen shows in place of its body while the record loads, or when it cannot be shown: skeleton, "isn't available" (gone or not yours), or a load failure. */
export function RecordUnavailable({ isLoading, error, noun }: { isLoading: boolean; error: unknown; noun: string }) {
  if (isLoading) return <Skeleton className="h-40 w-full" data-testid="record-loading" />;
  const unavailable = !isApiError(error) || error.status === 404 || error.status === 403;

  return (
    <p role="alert" className="text-sm text-destructive">
      {unavailable ? `This ${noun} isn't available.` : `Couldn't load this ${noun}.`}
    </p>
  );
}
