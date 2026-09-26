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
