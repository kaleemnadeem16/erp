import { getApiErrorMessage, type ApiError } from '../api-errors';

import { isPrecisionReason, PRECISION_REASON_ADVICE } from './advice';

export { isPrecisionReason, PRECISION_REASON_ADVICE };

/**
 * The text to show for a refused request: the server's message, followed by the advice for its precision reason code when it has one. Returns null for
 * an error that is not a precision refusal, so the caller keeps its normal handling.
 */
export function describePrecisionRefusal(error: unknown): string | null {
  const failure = error as Partial<ApiError> | null;
  const code = failure?.body?.reason_code;
  if (!isPrecisionReason(code)) return null;
  const message = failure?.body?.message?.trim();
  const advice = PRECISION_REASON_ADVICE[code];

  return message ? `${message.replace(/\.?$/, '.')} ${advice}` : (advice ?? null);
}

/** What a form-level banner should say for any failed submit: the precision advice when the refusal is one, otherwise the server's own message. */
export function describeSubmitError(error: unknown): string | null {
  return describePrecisionRefusal(error) ?? getApiErrorMessage(error);
}
