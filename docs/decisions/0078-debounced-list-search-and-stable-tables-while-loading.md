[← Decisions](README.md)

# 0078 — Wait for a pause before searching, and keep the table steady while it loads

- **Status:** accepted
- **Date:** 2026-10-02
- **Affects:** every list screen's search box, `DataTable`, [list-query-state](../../frontend/packages/core-ui/src/lib/list-query-state.ts)

## Context

The Purchase Orders and Goods Receipts search boxes wrote the typed text straight into the URL on every keystroke, and the
URL is the list's query key, so each key sent a request (typing "00420" sent five). Worse, each new key cleared the table to
skeletons and the footer to "0 records" while the answer was in flight, so the screen flickered as a person typed. Thirteen
other list screens already waited 300 ms, each with its own copy of the same few lines.

The owner asked whether a delay is the right answer, or whether the app should filter what it already holds instantly and
ask the server only when typing stops.

## Decision

1. **Debounce the request, never the typing.** The box shows what is typed at once; only the commit that writes the filter
   (and so fires the request) waits **300 ms** after the last key. Enter commits immediately, and emptying the box commits
   immediately (so deleting a search brings the full list back without a pause). A change made elsewhere (clear all, back
   navigation) replaces what the box shows. One shared hook, `useDebouncedSearch`, implements it.
2. **No client-side filtering of the rows already on screen.** Lists are server-paginated and server-searched: the page in
   view holds 25 of, say, 4,000 orders, and the search matches across supplier, item and line text the table does not show.
   Filtering those 25 rows locally would show a *wrong* answer for a moment (rows that match disappear because they are on
   another page, rows that match only through an unseen field vanish) and then change again. It feels instant and is
   misleading, which is worse than a short wait.
3. **Make the wait feel instant a different way: keep the last rows while loading.** `DataTable` keeps the previous rows
   (dimmed, `aria-busy`) and the previous count while a new page, sort or search loads, and shows skeletons only for the very
   first load. The screen stops flashing; the figures settle when the answer arrives.
4. **Announce the result count.** The footer count is a polite live region, so a screen-reader user hears "1–6 of 6 purchase
   orders" after a search without focus moving (WCAG 4.1.3).

## Consequences

- **Positive:** one request per search, not one per key; no flicker; the same behaviour on every list that adopts the hook.
- **Positive:** a person who stops typing waits ~300 ms plus the request, inside the one-second "flow" limit.
- **Negative:** the two screens that did not debounce now do; the thirteen that already did keep their own copies of the code
  until they are moved onto the hook (a mechanical change, not needed for the behaviour).
- **Negative:** the previous rows stay on screen for the length of the request, so a person could click a row that is about to
  be replaced; the row still opens the correct record, so this is acceptable.
- **Requires:** new list screens use `useDebouncedSearch` rather than writing the filter on each change.

## Alternatives considered

**Filter the current page instantly, then ask the server** — rejected for the reason in decision 2.

**Throttle instead of debounce** — rejected: it still sends requests *during* typing, which is what we want to avoid.

**`useDeferredValue` alone** — rejected: React's own guidance is that it keeps rendering responsive but "does not by itself
prevent extra network requests"; it complements debouncing, it does not replace it.

**A longer delay (500 ms+)** — rejected: the wait becomes the dominant part of the delay for a person who types a short code
and stops.

## Research basis

*Question:* what is the established way to search a server-paginated list without a request per keystroke and without the screen
flickering? *Accessed:* 2026-10-02. Primary sources only; this is a local mechanic, so no ERP comparison was made.

- TanStack Query — keeping the last data visible while a changed key loads (`placeholderData: keepPreviousData`,
  `isPlaceholderData`): [Paginated / lagging queries](https://tanstack.com/query/latest/docs/framework/react/guides/paginated-queries).
  `DataTable` applies the same idea at the table, so every screen gets it without changing each query.
- React — debouncing and throttling are still the tools for fewer network requests, `useDeferredValue` does not reduce them,
  and the two are meant to be used together: [useDeferredValue](https://react.dev/reference/react/useDeferredValue).
- Nielsen Norman Group — 0.1 s feels instantaneous, 1 s keeps the user's flow of thought, 10 s loses attention:
  [Response Times: The 3 Important Limits](https://www.nngroup.com/articles/response-times-3-important-limits/). 300 ms
  leaves the request most of the one-second budget.
- W3C — result counts are status messages that must be announced without moving focus:
  [WCAG 2.2 Understanding 4.1.3](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html).

*Remaining uncertainty:* 300 ms is the app's existing convention (thirteen screens), not a measured optimum; if backend
latency changes materially it is one constant in the hook.

## Related

- [ADR 0061](0061-retry-only-transient-failures-and-say-why-a-list-failed.md) — the list's failure states
- [erp-blue design standard](../architecture/erp-blue-design-standard.md#a-rule-from-the-same-work-screens-refresh-after-every-saved-change)
