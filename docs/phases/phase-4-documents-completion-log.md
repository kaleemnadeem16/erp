[← Phase index](README.md)

# Phase 4 — quotation documents completion: work log

Work area: quotation PDF, document history, manual delivery. Worked in a separate worktree (`work/documents-completion`) because other agents edit the shared working tree.

## Slices

| Slice | State | Commits |
|---|---|---|
| 1. Delivery correction (Action, route, client, screen) | done | 72397978 (backend), 6a231170 (client), eeaa8f3c (screen) |
| 4. Document history detail trimming | done (in 72397978) | 72397978 |
| 5. Demo data through the real Actions, company as a parameter | done | see git log (`feat(demo)`) |
| 6. ADR 0160, README, this log | done | see git log |
| 2. Company branding versions and asset references (#23, DF1b) | NOT STARTED: owner decision needed | none |
| 3. DOC5-DOC7 | not started: none is clearly specified and small (below) | none |

## Decisions (safest default, owner may refine)

- Correction: reason always required; only the current record of a chain can be corrected; a withdrawal is final and repeats the old values; reuses `sales.delivery.record` (no new duty, STAGED stays STAGED). See ADR 0160.
- History: Sales allowlists detail fields per event; Documents' internal timeline is unchanged.
- ADR number 0160 (not 0157) to avoid colliding with the access agent's uncommitted 0157.
- The older `corrects_id` on the record route is kept for compatibility.

## Questions for the owner

1. **Company branding / logo (#23).** Issue #23 was deferred on 2026-10-27 in favour of a shared file foundation, which now exists (Files F0, ADR 0153). Recommended default:
   a Company-owned, immutable, numbered `company_brand_versions` table (display name, tagline, colours, contact selection), one active version per company with a generation-locked
   activation history, plus a logo as an optional reference to a Files blob owned by a new `company.brand_asset` file owner (PNG/JPEG only, verified by Files, size-limited), read by Documents only
   through a typed Company contract and pinned into the manifest at freeze time; a missing brand renders exactly as today. Needs your word on: (a) go/no-go now, (b) the logo formats and size limit,
   (c) the capability name `company.branding.manage` (STAGED, not granted), (d) whether a Company screen section is wanted.
2. DOC5 (R6 method evidence bridge) needs CA0/CA1/CA2 (customer acceptance), which are not delivered; DOC6 (email provider) needs an approved provider and credentials; DOC7 are separately contracted
   extensions. None is small and clearly specified, so none was started.

## Remaining work

See the final report; summary: branding (#23) pending the answer above, DOC5 after customer acceptance, DOC6 after a provider decision, DOC7 only by new contracts, and the owner activation of the STAGED duties.
