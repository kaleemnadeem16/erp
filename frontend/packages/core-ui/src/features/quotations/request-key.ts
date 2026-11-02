import { isTransientFailure } from "../../lib/api-errors";

/** A new random request key (sent as the Idempotency-Key header). */
export function newRequestKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `k-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * One Idempotency-Key per write INTENT, so a retry is safe and a different request never reuses a key.
 *
 * - The key is bound to the exact intent (for example the revision and `expected_generation`, or the whole
 *   delivery body). Asking for a key with a different intent always mints a new one, so edited data or a
 *   newer draft generation can never be sent under an old key (which the server would refuse as reused).
 * - After an UNCERTAIN failure (network error, timeout 408, rate limit 429, server fault 5xx) the request
 *   may or may not have been applied, so the same intent keeps its key: retrying replays the original
 *   result instead of repeating the change.
 * - After a success or a DEFINITIVE refusal (any other 4xx) the attempt is over, so the next request of
 *   the same intent is a new operation with a new key.
 */
export class IntentKey {
  private current: { key: string; intent: string } | null = null;

  constructor(private readonly mint: () => string = newRequestKey) {}

  /** The key to send for this intent: the pending one if the intent is unchanged, otherwise a fresh one. */
  for(intent: unknown): string {
    const fingerprint = JSON.stringify(intent);
    if (this.current === null || this.current.intent !== fingerprint)
      this.current = { key: this.mint(), intent: fingerprint };
    return this.current.key;
  }

  /** Record how the attempt ended: pass the error, or nothing on success. */
  settle(error?: unknown): void {
    if (error === undefined || !isTransientFailure(error)) this.current = null;
  }

  /** The key a retry of this intent would reuse, if any (for tests and diagnostics). */
  pending(): string | null {
    return this.current?.key ?? null;
  }
}
