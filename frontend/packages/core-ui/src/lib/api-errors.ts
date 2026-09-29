import type { FieldPath, FieldValues, UseFormSetError } from 'react-hook-form';

export interface ApiErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}

export interface ApiError extends Error {
  status: number;
  body?: ApiErrorBody;
}

/** `fetchClient` (packages/core-api/src/mutator/fetch-client.ts) throws exactly this shape for every non-2xx response. */
export function isApiError(error: unknown): error is ApiError {
  return error instanceof Error && 'status' in error;
}

export function getApiErrorMessage(error: unknown): string | null {
  if (isApiError(error)) return error.body?.message ?? error.message;
  return error instanceof Error ? error.message : null;
}

/**
 * Whether asking again could plausibly give a different answer. A 4xx is the server's considered
 * verdict on *this* request — no permission (403), not found (404), invalid (422) — and repeating it
 * cannot change it, so it is definitive; a dropped connection, a server fault (5xx), a timeout (408)
 * or a rate limit (429) may well clear up. A thrown error with no HTTP status is a network failure.
 */
export function isTransientFailure(error: unknown): boolean {
  if (!isApiError(error)) return true;

  return error.status === 408 || error.status === 429 || error.status >= 500;
}

/**
 * The app-wide React Query retry rule. The library's default retries *every* failure three times with
 * growing delays, so a definitive answer such as a 403 sat behind about ten seconds of loading
 * skeletons — and four identical requests — before the screen admitted it had failed. Only a
 * transient failure is retried, and only twice.
 */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  return failureCount < 2 && isTransientFailure(error);
}

/**
 * What to tell a person whose list or lookup failed to load. The cause decides the next step —
 * sign in again, ask for access, change the filter, or just try again — so each gets its own words
 * rather than one message for everything.
 */
export function describeLoadError(error: unknown): string {
  if (!isApiError(error)) return "Can't reach the server. Check your connection and try again.";

  switch (error.status) {
    case 401:
      return 'Your session has expired. Sign in again.';
    case 403:
      return "You don't have permission to view this.";
    case 404:
      return "This isn't available.";
    case 422:
      return `${error.body?.message ?? 'The request was not valid.'} Try changing or clearing the filters.`;
    case 429:
      return 'Too many requests. Wait a moment and try again.';
    default:
      return error.status >= 500
        ? 'The server had a problem loading this. Try again in a moment.'
        : (error.body?.message ?? 'This could not be loaded.');
  }
}

/**
 * The shared create/edit form convention for handling a failed submit: field
 * errors from a 422 (`{ message, errors: { field: [...] } }` — every
 * validation/domain-invariant response in bootstrap/app.php uses this shape)
 * are set on the matching react-hook-form field so they render inline via
 * FormMessage. Anything that doesn't match a known field name (or isn't a
 * field-shaped error at all, e.g. a bare integrity-rule 422) is returned so
 * the screen can show it as a form-level banner instead.
 */
export function applyApiErrorsToForm<TFieldValues extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<TFieldValues>,
  knownFields: readonly (keyof TFieldValues & string)[]
): string | null {
  if (!isApiError(error) || !error.body?.errors) {
    return getApiErrorMessage(error);
  }

  const unmatched: string[] = [];
  for (const [field, messages] of Object.entries(error.body.errors)) {
    const rootField = field.split('.')[0] ?? field;
    if ((knownFields as readonly string[]).includes(rootField)) {
      setError(field as FieldPath<TFieldValues>, {
        type: 'server',
        message: messages[0]
      });
    } else {
      unmatched.push(...messages);
    }
  }

  return unmatched.length > 0 ? unmatched.join(' ') : null;
}

/**
 * The loading and failure state of the query behind a picker, in the shape `Combobox` takes:
 * `<Combobox {...lookupState(customersQuery, 'customers')} />`. Without it a failed lookup falls back
 * to an empty list and reads as "no results", so a required field cannot be filled and nothing says why.
 */
export function lookupState(
  query: { isLoading: boolean; isError: boolean; error: unknown; refetch: () => unknown },
  noun: string
) {
  return {
    loading: query.isLoading,
    loadError: query.isError ? { error: query.error, onRetry: () => void query.refetch(), noun } : undefined,
  };
}

/** What `lookupState` returns, for passing a picker's state down to a filter-controls component. */
export type LookupState = ReturnType<typeof lookupState>;
