# 0061 — Retry only transient failures, and say why a list failed

- **Status:** accepted
- **Date:** 2026-09-28
- **Affects:** every list screen, the shared table components, the SPA's query client,
  [user-interface.md](../architecture/user-interface.md)

## Context

[Q31](../OPEN-QUESTIONS.md#q31--a-failed-list-request-renders-as-an-empty-or-stale-list) was first
recorded as "a failed list request is invisible". Measuring it showed something more specific. The
tables already have an error state, and all 19 list screens pass it. Forcing the Warehouses request to
fail four ways and watching what a person sees:

| Failure | What was seen |
|---|---|
| 403 (no permission) | ~10 s of loading skeletons under a "0 records" footer, four identical requests, then "Couldn't load warehouses." |
| 422 (invalid filter) | the same |
| 500 / offline | the same, with the same message |

Three separate problems follow. React Query's default retries **every** failure three times with growing
delays, so a definitive answer such as a 403 — which can never succeed on a second try — sits behind the
delay and the extra load. The message is one fixed string whatever the cause, so nobody can tell whether
to sign in, ask for access, change a filter or wait. And there is no way to retry short of reloading the
page. It matters more as permissions become real: every list a user may not see would otherwise sit in
skeletons first.

## Decision

1. **Retry only a transient failure, and only twice.** `shouldRetryQuery` (the query client's `retry`)
   retries a failure with no HTTP status (the network dropped), a 408, a 429 or a 5xx. Any other 4xx is
   the server's considered answer to this request and is reported at once.
2. **One shared error block.** `ListError` — used by the table, card and cursor views — shows the
   screen's own context ("Couldn't load warehouses."), the cause from `describeLoadError` (no permission,
   the server's objection to a filter, a server fault, offline, session expired), and a "Try again"
   button. While in the error state the "0 records" footer is not shown, since it reads as "nothing
   found".
3. **Every list screen passes `error` and `onRetry`** alongside the `isError` it already passed, taken
   from the same query.

## Consequences

- **Positive:** A missing permission or a bad filter is reported at once, in words, with one request. A
  server hiccup still heals itself, and a person can retry a genuine failure without reloading.
- **Positive:** The wording and behaviour are decided once, so a screen cannot quietly do its own thing.
- **Negative:** A brief 5xx or dropped connection now gets two retries rather than three.
- **Negative:** A 401 says "Your session has expired. Sign in again." but nothing yet redirects to the
  login screen; that is a session concern this does not change.
- **Negative:** Only list tables are covered. A picker or lookup that fails to load still shows "No
  items found." ([Q32](../OPEN-QUESTIONS.md#q32--pickers-and-lookups-that-fail-to-load-read-as-empty)).
- **Requires:** A new list screen passes `error` and `onRetry` to its table.

## Alternatives considered

**A toast for any failed request** — rejected. The app has no toast system, so it would add a
dependency; a toast is transient while the table would still read "0 records"; and it does nothing for
the retry.

**Throw to a page-level error boundary** — rejected. It replaces the whole screen, including the filters,
so a person could not correct the bad filter that caused the failure.

**Make the table take the whole query object** — deferred. It would make errors and loading inherent, but
it changes the table's API across all 19 screens for what two extra props achieve.

**Fix only the retry rule, or only the message** — rejected. The delay and the vagueness are separate
problems; either alone leaves the other.

## Related

- [ADR 0060](0060-accept-true-and-false-for-boolean-list-filters.md) — the bug this made visible
- [UI building blocks](../architecture/ui-building-blocks.md)
