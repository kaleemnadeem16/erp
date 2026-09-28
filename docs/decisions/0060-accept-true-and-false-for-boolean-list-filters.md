# 0060 — Accept "true" and "false" for boolean list filters, once, in the shared list-query trait

- **Status:** accepted
- **Date:** 2026-09-28
- **Affects:** every collection endpoint with a boolean filter, the generated client's consumers,
  [api-conventions.md](../architecture/api-conventions.md)

## Context

A query string is always text. The generated client (orval) serialises a boolean parameter with
`value.toString()`, so `filter[active]: true` becomes `filter[active]=true`. Laravel's `boolean` rule
accepts only `true`, `false`, `1`, `0`, `"1"` and `"0"` — a *string* `"true"` fails it. So every boolean
list filter returned `422` when a screen used it the ordinary, typed way.

The consequence was worse than an error. A list screen renders a failed request as an empty list (or
keeps stale rows), so choosing **Active** in the Warehouses status filter silently showed
"0 records", and the Item Suppliers status filter was broken the same way. Nothing surfaced it: three
other screens had quietly dodged it by not filtering server-side and left a comment about the rule, and
[Q29](../OPEN-QUESTIONS.md#q29--boolean-query-string-filters) asked where the fix belongs.

Boolean filters exist on six list endpoints (warehouses and locations `active`, item suppliers `active`
and `preferred`, application rates `is_default`, stock layers `open`), all of whose requests already
share `ValidatesListQuery`. Boolean fields in request *bodies* are JSON and were never affected.

## Decision

`ValidatesListQuery::prepareForValidation()` rewrites the strings `true` / `false` (any case) to real
booleans **for a `filter.*` key only when that request's own rules declare it `boolean`**, before
validation. Nothing else changes: `yes` and `2` still fail, and `filter[search]=true` is still a search
for the word "true". No endpoint, screen or generated file changes; the screens that filtered client-side
to dodge the 422 filter on the server again.

## Consequences

- **Positive:** Every current and future boolean list filter works from the typed client, fixed in one
  place, and other API consumers (mobile, integrations) that naturally send `true` / `false` work too.
- **Positive:** The rule that decides *which* keys are touched is the request's own validation rules, so
  it cannot drift from what a filter is declared to be.
- **Negative:** The API accepts a slightly wider set of spellings than Laravel's default for these
  filters.
- **Negative:** A request that defines its own `prepareForValidation` while using the trait would
  silently replace this one; none does today.
- **Requires:** A new boolean list filter is declared with a `boolean` rule under `filter.` in a request
  using `ValidatesListQuery`; `BooleanListFilterTest` lists every current one and should gain the new
  endpoint.

## Alternatives considered

**Change the client's serialisation** — rejected. Orval generates the URL builder, so it cannot be
edited by hand, and fixing only the SPA would leave every other consumer with the same trap.

**A global middleware rewriting every `"true"` / `"false"` query value** — rejected. It cannot tell a
boolean filter from a text one, and would turn `filter[search]=false` into a search for "0".

**Override Laravel's `boolean` rule globally** — rejected. Built-in rules are not overridable by an
extension, and it would loosen validation of request bodies that should stay strict.

**Leave it and dodge it per screen** — rejected. That is what three screens did, and two more were broken
without anyone noticing.

## Related

- [API conventions](../architecture/api-conventions.md#filtering-and-sorting)
- [ADR 0006](0006-generated-api-client.md)
