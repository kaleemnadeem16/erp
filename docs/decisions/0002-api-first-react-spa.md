[← Decisions](README.md)

# 0002 — API-first React SPA, not Inertia

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** all frontend work, Phase 0

## Context

The frontend is React. The question was how it communicates with Laravel.

Inertia.js would let Laravel handle routing, auth and validation with React as the view layer —
no API, no CORS, no duplicated DTOs. For a small team it is roughly 30–40% less code.

However: mobile applications are planned ([0015](0015-capacitor-for-mobile.md)), and
third-party integrations are in scope. Both need a real API.

## Decision

Backend and frontend are separate applications. React is a standalone SPA communicating with
Laravel over a versioned REST API at `/api/v1`.

## Consequences

- **Positive:** One integration surface serves web, mobile and third parties. No rewrite when
  the mobile app arrives.
- **Positive:** Frontend and backend deploy independently.
- **Negative:** Real cost — validation, resources and types exist on both sides. This is the
  reason people regret API-first, and it is mitigated (not eliminated) by
  [0006](0006-generated-api-client.md).
- **Negative:** Auth is more involved than session-only. See [0005](0005-sanctum-authentication.md).
- **Requires:** the Scramble → Orval pipeline must be in place from Phase 0, not added later.
  Without it, type drift between the two codebases becomes the dominant source of bugs.

## Alternatives considered

**Inertia.js** — genuinely faster to build and the initial recommendation. Rejected because
mobile and integrations would force an API layer later anyway, and running Inertia and an API
side by side means maintaining two representations of every screen's data.

**Inertia now, API later** — appealing middle path. Rejected because "later" arrives with the
business logic already embedded in Inertia controllers, and extracting it is exactly the
refactor this decision avoids. Note that the mitigation still applies: business logic lives in
Actions ([0014](0014-modular-monolith.md)), so controllers stay thin either way.

## Related

- [0006 — Generated API client](0006-generated-api-client.md)
- [0015 — Capacitor for mobile](0015-capacitor-for-mobile.md)
- [architecture/api-conventions.md](../architecture/api-conventions.md)
