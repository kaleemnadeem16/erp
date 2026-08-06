[← Decisions](README.md)

# 0005 — Sanctum for authentication

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 0, all API work

## Context

With a separate frontend ([0002](0002-api-first-react-spa.md)), the API needs authentication
for two different client types: a browser SPA, and native mobile apps
([0015](0015-capacitor-for-mobile.md)). Third-party integrations are also planned, though these
are expected to be primarily **outbound** — the ERP calling Gmail and similar services, rather
than external developers calling us.

## Decision

Laravel Sanctum, in both of its modes:

- **Web SPA** — cookie/session authentication, with the SPA served from the same top-level
  domain as the API (e.g. `app.example.com` and `api.example.com`)
- **Mobile** — Bearer tokens issued as personal access tokens

Outbound OAuth2 (Gmail etc.) is a separate concern handled by Socialite plus per-connection
encrypted refresh tokens, not by the authentication layer.

## Consequences

- **Positive:** Cookie mode keeps tokens out of JavaScript entirely, so XSS cannot exfiltrate a
  session. This is materially safer than storing JWTs in `localStorage`.
- **Positive:** One package covers both client types.
- **Negative:** Cookie mode requires the SPA and API to share a top-level domain, and CSRF
  handling must be correct. This constrains deployment topology and needs documenting.
- **Requires:** if external developers ever need API access with consent screens, revisit —
  that is Passport's territory, not Sanctum's.

## Alternatives considered

**JWT (`tymon/jwt-auth` or similar)** — stateless and fashionable. Rejected: revocation requires
a denylist (so it isn't really stateless), and browser-side storage is either XSS-exposed
(`localStorage`) or effectively a session cookie anyway.

**Laravel Passport** — full OAuth2 server. Rejected as substantial complexity for a capability
not currently needed. Planned integrations are outbound; we are the OAuth *client*, not the
provider.

## Related

- [0002 — API-first React SPA](0002-api-first-react-spa.md)
- [architecture/api-conventions.md](../architecture/api-conventions.md)
