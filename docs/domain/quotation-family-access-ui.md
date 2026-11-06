[← Domain](README.md)

# Quotation family access — interaction contract

**2026-11-05: implementation in progress, unpublished.** The existing quotation detail workspace
owns this feature; it is not a separate administration screen. Production UI activation stays off.
Demo-only duty handout and ordinary server authority remain separate concerns.

## People and workflow

An ordinary viewer reads owner/unit identity separately from immutable creator identity. A preparer
with both view and prepare may use the existing review/edit workflow. Access governors see only
management actions allowed by the current, independently scoped server decision. A received share
never enables onward management. An inactive owner is visible for recovery, not silently replaced.

Add an Access and responsibility section to the existing quotation detail. Use shared DetailSection,
fields, status badges and loading/error patterns. Quick management forms use the existing Dialog
pattern over the record. They retain input after refusal, confirm the proposed change and required
reason, block repeated submission and guard dirty dismissal. Changing owner/unit warns that the
actor may lose access; a resulting 404 returns safely to the list with its existing query context.

## Coverage matrix

| Contract element | Destination / availability | Acceptance |
|---|---|---|
| Current owner, active/inactive status | Detail access section, any eligible view holder | Real name and status from family response; no first-page lookup |
| Immutable creator | Separate labelled detail fact | Never changes when ownership moves |
| Explicit access unit; absent unit | Detail fact and governance form | Not inferred from owner; absent unit labelled clearly |
| Owner assignment | Governor-only action and confirmation | Scoped source family; active same-company directory selector; real Action and fresh generations |
| Unit change / clear | Governor-only action and confirmation | Searchable active company units; explicit clear choice; owner unchanged |
| Named recipient and VIEW/PREPARE level | Share form, independently authorised governor | Server-searched directory identities; no inferred recipient permission; Action checks scope/capability |
| Optional start/end | Advanced disclosure with non-default summary | Native datetime-local, explicitly labelled local time, sent as UTC instants; blank start means now and blank end unbounded |
| Required reason | Every mutation form | Inline/server validation; stored audit reason, never echoed through history |
| Ownership/access generations | Hidden request state | Stale refusal offers refresh; never relabelled as authority grants |
| Idempotency key / retry | Existing IntentKey helper | Same intent retains key after uncertain failure; edited intent gets a new key |
| Revoke including expired grants | Existing share row, revoke-authorised governor | Review + reason; append-only result; repeated revocation is a no-op |
| Active/scheduled/expired/revoked/invalidated | Share history badge and interval | No expiry scheduler; show server status, not a guessed client verdict |
| Ownership/share histories | Independent authorised disclosures and generation pagination | No duplicate/missing rows across cursors; no raw reason, financial fact or authority pin |
| Candidate search and cursor pagination | Shared remote lookup | Preserve selected label; loading, retry, empty and load-more; do not assume first page is complete |
| Safe result / refresh | Action-local message then refetch | Invalidate family, quotation detail and list; remove stale protected projection on refusal |
| Production-disabled / missing duty | Existing quotation gate and permission controls | No query while gate is off; no issue/repair permission or new setting |

Access applies across revisions of one family. A new separate quotation starts without its shares;
UI help says this explicitly. No bulk grant, wildcard/role recipient, transitive share, finance,
issue, repair, print or export action is added by this feature. Those are separate duties/workflows.

## States and navigation

Loading, empty histories, no directory matches, directory failure, validation, missing capability,
hidden/unavailable record, stale/conflict, uncertain retry and success are distinct. Unknown/foreign/
hidden records have the same wording. Field errors stay associated with labelled controls; errors
are announced. Forms preserve input after failure and do not refresh expected generations silently.

Reuse existing tokens in light/dark; no palette change. Actions wrap at phone width; history rows
use readable cards rather than a wide table. Dialogs scroll internally and keep confirmation reachable.
Use shared reduced-motion, focus return, unsaved-change and lookup behaviour. Preserve the list's
search string through opening/closing the record. No first-page directory lookup resolves a known
owner/unit; their labels are already supplied by the family response.

## Local verification and remaining gates

The integrated implementation uses the real generated client behind the existing quotation gate.
Sixteen intercepted browser fixtures passed: production gate/no query, named VIEW/PREPARE without
management, confirmed owner change, exact uncertain retry, stale refresh, cursor directory selection,
explicit unit clearing/dirty dismissal, optional PREPARE periods, expired-share revocation, independent
history context, invalid links, uniform hidden-family display, light/dark phone accessibility, and cached metadata denial in both full-page and sheet containers.
Twenty-three model and existing quotation unit checks passed. Core-api/core-ui/main typechecks and
the Vite production build passed. Browser traffic is fully intercepted at a synthetic origin; these
checks do not exercise the owner application or constitute representative concurrency evidence.

Response family identity is checked before projection. Full-page and sheet containers wait for
current family eligibility before showing cached metadata or preparation. Their shared access panel
stays mounted during refetch so hiding stale content cannot create a mount/refetch loop.
Creation keeps its original prepare gate; review/edit require view and prepare. The approved existing
unit assertion change is limited to that rule, with a both-duties-positive case. No existing assertion
is removed or skipped. Current directory names label immutable ownership/share evidence; they are
not historical name snapshots.


Browser fixtures must cover ordinary OWN, view-only named access, PREPARE named access, unit
supervision, revoked/expired/inactive recipients, foreign/unknown records, stale refresh and retry,
phone overflow, keyboard/focus and light/dark accessibility. The demo personas/presets and real
Action scenarios are required before this feature is presented as complete. Backend/API progress
is recorded in the sharing checkpoint; this contract is not completion evidence.

## Related

- [Sharing implementation checkpoint](quotation-sharing-implementation.md)
- [Family API contract](quotation-family-access-api.md)
- [Demo persona/preset contract](quotation-access-demo.md)
- [Screen delivery contract](../architecture/screen-delivery-contract.md)
