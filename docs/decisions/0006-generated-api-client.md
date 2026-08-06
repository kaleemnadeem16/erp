[← Decisions](README.md)

# 0006 — Generated API client (Scramble → Orval)

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 0, all API and frontend work

## Context

Splitting frontend from backend ([0002](0002-api-first-react-spa.md)) creates a permanent risk:
the two codebases hold independent representations of the same data, and they drift. A renamed
field in a Laravel API resource surfaces as `undefined` in the browser, at runtime, possibly in
production. This is the single biggest practical cost of API-first, and it compounds as the
surface grows — an ERP will have hundreds of endpoints.

## Decision

Types flow one way, generated, never hand-written:

```
Laravel controllers, form requests, API resources
        │  dedoc/scramble
        ▼
   OpenAPI spec
        │  orval
        ▼
Typed TanStack Query hooks + TypeScript types
```

Scramble infers the spec from existing code with no annotations. Orval generates the client.
Generated output is committed so CI can verify it is current.

## Consequences

- **Positive:** A backend field change breaks the frontend build, not production. This converts
  the main API-first failure mode from a runtime bug into a compile error.
- **Positive:** API documentation exists as a by-product and cannot go stale.
- **Positive:** No hand-written fetch calls or duplicated interfaces.
- **Negative:** Adds a generation step to the workflow; a stale regeneration causes confusing
  errors. Mitigated by a CI check that regenerates and fails on diff.
- **Negative:** Scramble's inference needs help on complex or dynamic responses. Accept that
  some endpoints need explicit type hints.
- **Requires:** must be in place from **Phase 0**. Introducing it after a large hand-written
  client exists means rewriting the client.

## Alternatives considered

**Hand-written API client with manual types** — no tooling, full control. Rejected: it works at
20 endpoints and fails at 200. Drift is invisible until runtime.

**tRPC** — excellent end-to-end type safety, but requires TypeScript on both ends. Incompatible
with [0001](0001-laravel-backend.md).

**Hand-written OpenAPI spec** — spec-first with the API as contract. Rejected because a
hand-maintained spec drifts from the implementation exactly like hand-written types do; the
generation direction matters.

## Related

- [0002 — API-first React SPA](0002-api-first-react-spa.md)
- [architecture/api-conventions.md](../architecture/api-conventions.md)
- [phases/phase-0-foundation.md](../phases/phase-0-foundation.md)
