# 0064 — Pickers report a failed lookup and offer a retry

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** the shared `Combobox`, every screen that fills one from a query,
  [ui-building-blocks.md](../architecture/ui-building-blocks.md)

## Context

[ADR 0061](0061-retry-only-transient-failures-and-say-why-a-list-failed.md) made a failed list table say
why it failed and offer "Try again". The pickers that feed forms were left out
([Q32](../OPEN-QUESTIONS.md#q32--pickers-and-lookups-that-fail-to-load-read-as-empty)). A screen loads
its options with a `useXIndex` query and falls back to `[]`, so when the request fails — no permission, a
server fault, the network dropping — the dropdown opens on "No customers found." A person concludes the
company has no customers, and a required field cannot be filled, with nothing to say the request rather
than the data is the problem. About 70 pickers on 30 screens had this.

## Decision

1. **`Combobox` takes `loadError` and `loading`.** With `loadError` it shows, instead of the list, the
   picker's own context ("Couldn't load customers."), the cause from `describeLoadError` (the same wording
   the tables use) and a "Try again" button; the trigger reads "Couldn't load customers" and is marked
   `aria-invalid`, and it does so even when a value is already selected, because the label of that value
   cannot be looked up. While loading, the trigger and list read "Loading…" so an empty list is never
   mistaken for "no results".
2. **`lookupState(query, noun)`** turns the query behind a picker into those two props, so a call site is
   one line: `<Combobox {...lookupState(customersQuery, 'customers')} … />`. A filter-controls component
   that receives options as props takes a `…Lookup: LookupState` prop and spreads it.
3. **Every picker that is fed by a request passes it.** A picker whose options are not requested (a fixed
   list, or one derived from data already on screen, such as an assembly's versions) does not.

## Consequences

- **Positive:** A failed lookup reads as a failure everywhere, in the same words as a failed table, and
  can be retried without leaving a half-filled form.
- **Positive:** One place decides the wording and behaviour; a screen cannot quietly do its own thing.
- **Negative:** A new picker fed by a query must remember `lookupState`; nothing enforces it yet.
- **Negative:** Some screens use several lookups; each shows its own error rather than one summary.

## Alternatives considered

**A banner on the form saying "some lists failed to load"** — rejected: it does not say which picker, and is
easy to overlook beside the empty field.

**Replace the whole screen with an error on any failed lookup** — rejected: a failed optional lookup (say
locations) would break a screen that could otherwise be used, and a person would lose the form they had
filled in.

**Leave it** — rejected: a required field that cannot be filled, with no explanation, is the exact
confusion ADR 0061 removed from tables.
