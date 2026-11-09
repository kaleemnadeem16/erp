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

// A date-only value ("2026-11-06") is a calendar day, not a moment. `new Date('2026-11-06')` reads it as midnight UTC, which a
// browser west of UTC then shows as the 5th. Build it from its parts in the viewer's own calendar so the day never shifts.
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

export function formatDate(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  const calendarDay = DATE_ONLY.exec(iso);
  if (calendarDay) {
    return new Date(Number(calendarDay[1]), Number(calendarDay[2]) - 1, Number(calendarDay[3])).toLocaleDateString();
  }
  return new Date(iso).toLocaleDateString();
}

export function formatDateTime(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

/**
 * A moment shown with the time zone it is displayed in ("5 Nov 2026, 18:00 GMT+1"), for times where the zone changes
 * the meaning — an expiry. The zone is the viewer's browser zone; the label says so rather than leaving it implied.
 */
export function formatDateTimeWithZone(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  return new Date(iso).toLocaleString(undefined, { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
}
