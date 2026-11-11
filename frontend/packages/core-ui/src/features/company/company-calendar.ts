import { useCompanyShow } from '@erp/core-api';

import { asCompanyView } from './company-types';

/**
 * The calendar day ("2026-11-10") in a named time zone, so a company's business date never follows the browser's or UTC's clock.
 * An unknown or empty zone falls back to the browser's own zone rather than throwing.
 */
export function calendarDayIn(timeZone: string | null | undefined, now: Date = new Date()): string {
  const parts = (zone: string | undefined) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
  try {
    return parts(timeZone || undefined);
  } catch {
    return parts(undefined);
  }
}

/**
 * The company's own calendar day, from the time zone set in Settings -> Company (the same `timezone` the server uses for business dates).
 * Until the profile has loaded, or when it cannot be read, this is the browser's day: forms prefill it as an editable value and the
 * server stays the authority on every date it accepts.
 */
export function useCompanyToday(now: Date = new Date()): string {
  const query = useCompanyShow({ query: { staleTime: 5 * 60_000, retry: false } });
  const zone = query.data?.status === 200 ? asCompanyView(query.data.data.data)?.values.timezone : undefined;
  return calendarDayIn(typeof zone === 'string' ? zone : undefined, now);
}
