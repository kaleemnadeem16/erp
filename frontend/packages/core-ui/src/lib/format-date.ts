/**
 * The app's one date/time-formatting decision, so every list/detail screen
 * makes the same date-vs-date+time call instead of each column re-deciding.
 * `created_at`-style passive record metadata is `formatDate` (the day is
 * what matters); an operational event more than one of which can land on
 * the same day — moved_at, posted_at, approved_at, requested_at, … — is
 * `formatDateTime`, or same-day entries would be indistinguishable and lose
 * their ordering. Neither shows seconds: no timestamp in this app needs
 * second-level precision on screen, and `Date.prototype.toLocaleString()`'s
 * default options otherwise render them on every row.
 */

// `undefined`, not `''`, for a missing date: the table's `formatColumnValue`
// and `DetailField` both special-case null/undefined into a "—" placeholder,
// but neither treats an empty string as missing, so returning '' would
// render as a blank cell instead.

export function formatDate(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  return new Date(iso).toLocaleDateString();
}

export function formatDateTime(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}
