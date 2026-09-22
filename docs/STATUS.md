# Project Status

**Updated:** 2026-09-22
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done, including production deployment (2026-09-22, ahead of
the rest of Phase 2 at the user's request).
**Application code:** Laravel 12 backend with Phase 0 foundations, Phase 1 master-data APIs and
Phase 2 purchase orders, receipts, QA/reversal and the full stock movement/valuation engine.
Inventory cutover now posts and strictly reconciles AVCO/FIFO/SPECIFIC opening state; controlled
negative openings and staged open purchase-order commitments, live negative-stock overrides,
backdated recalculation and physical counts are also complete. Each workflow has a read/write API
and React UI; the stock ledger, layers and AVCO
pools have read surfaces. The React 19 + Vite frontend uses the generated client for every
implemented endpoint. Only the later Phase 4/5/6 multi-owner correction package remains.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **First production deployment, seeded as a live demo company** — `main` is live: `https://erp.x1techs.com` and `https://api-erp.x1techs.com` respond over real TLS through Traefik on mainvm, backed by dbvm's shared Postgres/Redis, with a working admin sign-in and full CORS/CSRF/cookie-session round trip verified. Company 1 is deliberately being run as a demo/sandbox company on the live site (the user's choice), so `demo:seed` was run against it too — via Artisan's `--env=local` override, which satisfies the seeder's `local`/`testing`-only guard for that one process without changing the real `APP_ENV=production` anything else uses — with the demo login's credentials overridden away from the well-known dev default that's written throughout this repo's docs. Run entirely by hand over SSH — no deploy job exists in CI, only build-and-push. Four real gaps found and fixed along the way, not previously known: `.env.example`'s `DB_HOST=dbvm`/`REDIS_HOST=dbvm` only resolved as an SSH alias, not inside mainvm's containers; the GHCR images are private and mainvm had no working pull credential; `erp:create-admin --permissions=all` silently grants nothing on a fresh database because the app's ~25 permission strings are only ever registered by a seeder that refuses to run in production; and the demo login's default credentials would otherwise have been publicly guessable → [deployment.md](architecture/deployment.md#first-deploy-runbook) | 2026-09-22 |
| **Supplier sites, a two-column detail layout and a Company/You settings menu** — suppliers can now have order-from/ship-from addresses: `party_sites`/`supplier_sites` (schema-complete since Phase 1 but never exposed) gained their first API (`SupplierSiteController`; nested `POST/GET supplier-accounts/{id}/sites`, flat `PATCH/DELETE supplier-sites/{id}`; a create request only asks for an address, `party_sites.code` is derived and de-duplicated server-side), wired into the PO form as a `Combobox` plus an inline "add a site" `Dialog` (a new centered-modal primitive, `components/ui/dialog.tsx`) and shown on the PO detail screen and its print. `DetailPageLayout` is now a responsive grid so short sections (Order/Supplier/Commercial) share a row instead of each stacking full-width; mark a table-bearing section `fullWidth` to keep it on its own. Quick Print's header dropped the boxed meta panel and colour bar that read as screen UI rather than a printed letterhead. A new Settings dropdown beside the dark-mode toggle groups Company (Purchasing/Inventory/Printing) vs. You (Appearance) → [parties.md](domain/parties.md#shared-sites), [purchasing.md](domain/purchasing.md#user-interface), [ui-building-blocks.md](architecture/ui-building-blocks.md) | 2026-09-22 |
| **Searchable pickers, calendar date field, PO side sheet, light/dark theming and Quick Print branding** — reference-data dropdowns are searchable, dates use a `DateField` (Tab leaves the field, ←/→ move segments, calendar popup, no more placeholder clipping), and creating or opening a purchase order is a side sheet over the still-visible list (create is a 3-step wizard with a live order-summary side column, and an in-place confirm — not a raw ghost button — for discarding unfinished work); each screen keeps its full-page version, switchable in one place. New company/user-layered light/dark mode + 3 accent themes (`Settings → Appearance`, `AppearanceProvider`, pre-paint script against flash-of-wrong-theme) and a global Quick Print branding system (`Settings → Printing`; `PrintLayout`/`DetailPrintLayout`/`PrintableTable` read it directly, so one accent-colour change reaches all 16 print screens); the PO print/detail also gained a proper Supplier section (payment terms, tax ID, lead time) and an accent-styled totals table. Sheets now animate open/close. Also fixed the double scrollbar and the sidebar's dead space → [appearance-and-printing.md](architecture/appearance-and-printing.md), [ui-building-blocks.md](architecture/ui-building-blocks.md) | 2026-09-22 |
| **Desktop sidebar grouping, quick access and inventory value cards** — the ~15-item flat sidebar now groups into Purchasing / Inventory / Stock Operations / Settings sections (mobile's "More" sheet mirrors the same grouping); a "Quick access" bar (hybrid: hover-to-pin any nav item, remaining slots fill by visit frequency, both tracked client-side in localStorage) sits above the content area; the active item now gets a distinct blue tint instead of generic grey, and every non-Dashboard nav link now correctly highlights on its detail/edit sub-routes too (dropped the `end` prop that was silently preventing it). Inventory Overview gained four headline cards (on-hand/available value, shortage/QA-held item counts) from a new `GET /api/v1/inventory-overview/summary` endpoint that rolls the same filtered set up into one aggregate query — a real bug surfaced immediately against live demo data: `available_value`'s SQL division doesn't preserve a fixed decimal scale, and the default `toScale(4)` throws rather than rounds; fixed with `RoundingMode::HALF_UP` and locked in with a regression test using a non-terminating ratio → [user-interface.md](architecture/user-interface.md#desktop-sidebar-grouping-quick-access-active-colour) | 2026-09-10 |
| **Purchasing/inventory policy settings screens and an inventory overview** — the last two screen-level gaps blocking the "complete Phase 2 without direct API/DB access" acceptance criterion are built. `/settings/purchasing` and `/settings/inventory` edit ADR 0047/Q17's policy fields with a mandatory reason and show their own audit trail, via two new read-only endpoints (`GET /api/v1/{purchasing,inventory}-policy-changes`) that exposed already-written-but-unread `PurchasingPolicyChange`/`InventoryPolicyChange` audit rows for the first time; `/inventory-overview` (`GET /api/v1/inventory-overview`) computes on-hand/available/QA-held/on-order/shortage per item in one server-side query and links each row to the Stock Ledger, pre-filtered to that item. Two real bugs caught before shipping: raw `DB::table()` subqueries don't inherit the Eloquent company scope (would have leaked cross-tenant data); AVCO items keep `avco_pools` *and* `stock_layers` rows in lockstep for the same physical quantity rather than using one or the other, so on-hand was initially double-counted until the Feature test caught it. Reading either audit trail now needs its own `{purchasing,inventory}.policy-history.view` permission, deliberately separate from the settings' own manage permission (who changed a policy is more sensitive than the policy itself) — both settings screens show "you don't have permission to view this history" rather than a misleading empty list when it's missing. The demo dataset gained real purchase orders/receipts across every demo item (varied on-hand/QA-held/on-order figures instead of zeros, one still-open PO and one partially-resolved QA split left for the demo login to finish live) — consolidated into one `DEMO_USER_PERMISSIONS` list in `StockDemoSeeder` so re-seeding can no longer silently narrow the demo login's access. The formal keyboard/WCAG audit and explicit end-to-end journey test are now complete: the committed Playwright suites pass all 18 tests locally → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) | 2026-09-22 |
| **Mobile UI standards, piloted then rolled out to every list screen** — bottom nav fixed to 4 primary tabs + a "More" sheet; lists default to cards below `md` (togglable back to table, persisted per-screen via localStorage, same for column visibility); a screen's primary action places as a `PageHeader` `primaryAction`, rendering as a fixed FAB on mobile with matching bottom-padding clearance; non-essential filters move behind a mobile "Filters" sheet; `DataTable`/`CursorDataTable`'s new `rowActions` prop adds icon-only quick actions (edit/archive) to both table rows and cards from one definition — Items exposes archive/activate (the safe, already-validated `status` toggle) but deliberately not hard-delete, which the backend leaves unguarded against existing stock/PO references. Also fixed: `AppShell` wasn't actually height-constrained (`min-h-dvh` vs `h-dvh`), so `<main>`'s `overflow-y-auto` never engaged and the whole window scrolled instead — the desktop sidebar was silently scrolling away with content; a client-side route change now resets scroll to top. Detail-page Quick Print now groups fields into the same sections the screen itself uses instead of one flat grid. Separately, the dev API base URL was hardcoded to `localhost`, breaking login from a phone on the LAN — now derived from the page's own host, with the backend's dev-only CORS/Sanctum config widened to match → [user-interface.md](architecture/user-interface.md#mobile-list-screen-patterns) | 2026-09-10 |
| **Goods-receipt and QA operating UI** — users can now record tracked arrivals from outstanding issued PO lines, post/reverse receipts with consequence review, and resolve QA root/open-branch quantities inline from server-derived balances. Supplier/status list filters, human reference labels and immutable disposition history complete the operational receipt workspace → [purchasing.md](domain/purchasing.md#user-interface) | 2026-09-10 |
| **Purchase-order operating UI** — users can now find, create, edit and inspect POs, then issue, cancel or short-close them through status-gated actions. Detail responses supply exact decimal totals and posted-receipt progress, while currency/UoM reference APIs keep labels human-readable → [purchasing.md](domain/purchasing.md#user-interface) | 2026-09-10 |

## Next

The new [product journeys](journeys/README.md) layer records the implemented Phase 2 flow from
login and master-data setup through PO, receipt, QA, stock visibility and controlled exceptions.
Keep it current whenever a workflow screen, status or consequence changes; later phases add their
own journeys.

Phase 2's first stock-engine UI/API slice is complete, and every screen the operational UI journey
needs now exists — settings screens and the inventory overview were the last two. Acceptance
criteria are now 18 of 22 satisfied: the formal keyboard/focus/WCAG audit and explicit
PO→receipt→QA→availability end-to-end journey both pass in Playwright. Of the four remaining
criteria, three require Phase 4/5/6 correction-package owners and one is the future
valuation-policy boundary.

The first production deployment is done (2026-09-22) — `main`, `https://erp.x1techs.com` and
`https://api-erp.x1techs.com` are live, TLS/CORS/CSRF/cookie-session login verified end-to-end
against the real domains. Full runbook, and the three real gaps found while running it (dbvm
resolving only as an SSH alias, not inside mainvm's containers; the GHCR images being private
with no working pull credential on mainvm; `erp:create-admin --permissions=all` silently
granting nothing because no permission strings are registered outside the production-blocked
demo seeder) → [deployment.md](architecture/deployment.md#first-deploy-runbook). Still open, not
blocking: no deploy job exists in CI (every VM-side step was run by hand over SSH), mainvm's
GHCR credential is a personal token rather than a dedicated one, and there is still no
production-safe command to register the app's permission vocabulary (tracked in
[phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md)).

## Blocked / awaiting input

Nothing blocking. Open questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 3
non-blocking (second customer profile; cross-entity master data; movement posting actor).

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | Complete (2026-09-22; non-blocking reference-doc and infrastructure follow-ups remain) |
| 1 | [Master data](phases/phase-1-master-data.md) | Complete |
| 2 | [Purchasing & stock](phases/phase-2-purchasing-stock.md) | In progress |
| 3 | Estimating core | Not started |
| 4 | Sales pipeline | Not started |
| 5 | Job execution | Not started |
| 6 | Finance | Not started |
| 7 | Mobile & integrations | Not started |

Detail: [phases/README.md](phases/README.md)

---

## How to update this file

This file is loaded by every agent at the start of every session — its size is a recurring
cost. Rules, per [ADR 0044](decisions/0044-status-log-context-budget.md):

1. **One table row per completed item**: a bold keyword, at most two plain sentences, a link to
   the phase doc/domain doc/ADR that holds the detail, and the date — in the same commit as the
   work. The narrative belongs in the linked doc first; if it doesn't fit there, it doesn't
   belong here either.
2. **Keep at most 8 rows in "Recently done".** When adding a row past that, move the oldest row
   to the bottom of [STATUS-LOG.md](STATUS-LOG.md) verbatim.
3. **Keep this file under ~90 lines.** Move the **Next** section forward when a phase completes.

Session note: development runs in a Remote-WSL VS Code window — run Docker/git natively from the
WSL shell.

Docker note: `docker compose restart <service>` restarts the process inside the existing
container — it does **not** re-read `env_file`. After editing `backend/.env`, use
`docker compose up -d <service>` instead (Compose detects the config diff and recreates the
container); `restart` alone silently keeps serving the old environment values.
