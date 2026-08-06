[← Decisions](README.md)

# 0015 — Capacitor for mobile, not a WebView wrapper

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 7, and frontend layout decisions from Phase 4 onward

## Context

The plan is to use the web application on mobile initially, then wrap it in a native shell —
"create an app with a browser which renders our app".

A raw WebView wrapper does exactly that and nothing more.

## Decision

**Capacitor**, wrapping the existing React SPA, plus `vite-plugin-pwa` for installability and
an offline app shell.

The frontend is designed mobile-first regardless: bottom tab navigation below `md`, sidebar
above it, and **data tables must have a card-view fallback on small screens.**

## Consequences

- **Positive:** Same codebase and same build output as the web app — the incremental cost over a
  raw WebView is close to zero.
- **Positive:** Real App Store and Play Store binaries.
- **Positive:** Access to native capabilities a WebView cannot reach:
  - **Camera / barcode scanning** — wanted the moment stock exists (Phase 2)
  - **Push notifications** — approvals, low-stock alerts
  - **Biometric login** and secure token storage ([0005](0005-sanctum-authentication.md))
  - Filesystem access for offline documents
- **Negative:** App store review and release processes to manage.
- **Negative:** Not a native UI. Acceptable for an internal business tool; would not be for a
  consumer product.
- **Requires:** the twelve-column data grids that dominate ERP screens need a genuine mobile
  presentation. A desktop table shrunk to a phone is the single biggest reason a web app "feels
  like a website" — this is a design obligation from Phase 4, not a Phase 7 fix.

## Alternatives considered

**Raw WebView wrapper** — the original plan. Rejected: identical effort, none of the native
capabilities, and barcode scanning would eventually force a rewrite anyway.

**PWA only, no store presence** — genuinely sufficient for many cases and is a subset of this
decision (the PWA is built either way). Rejected as the sole approach because iOS PWA support
remains limited, particularly for push, and some customers expect a store listing.

**React Native** — a real native UI. Rejected: a second codebase and a second set of screens for
an internal tool, where the web app already covers the functionality.

## Related

- [0002 — API-first React SPA](0002-api-first-react-spa.md)
- [architecture/tech-stack.md](../architecture/tech-stack.md)
