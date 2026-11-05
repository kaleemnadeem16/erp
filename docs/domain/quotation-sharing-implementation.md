[← Domain](README.md)

# Quotation sharing — local implementation checkpoint

**2026-11-05: in progress, unpublished.** This is a backend increment, not completed QA5 or an
activation decision. [The resume checkpoint](quotation-access-resume.md) records the published
baseline. Production activation remains **BLOCKED**.

## Authority and scope

The owner-approved sensitive duties `sales.quotation.access.delegate` and
`sales.quotation.access.revoke` are STAGED. They confer no rights through registration or automatic
all-capability grants. No issue, repair or financial right is added.

`GrantQuotationAccess` and `RevokeQuotationAccess`, in `Sales/Actions/Access`, use the existing
QuotationOperation fence, family-lock, current-authority, receipt and stale-generation protocol.
Management requires independently scoped, non-delegated view plus the relevant management duty;
PREPARE grants also require non-delegated prepare. Receiving a share never supplies management
eligibility. The existing ownership Actions now explicitly select the same non-delegated view path.

A recipient must be active in the company and independently hold each required active capability.
An unreachable duty must have explicit OWN_OR_DELEGATED opt-in. OWN and MY_UNITS remain bounded;
a record grant never becomes a capability or an ALL scope. Already eligible recipients produce a
receipt-backed no-op. Self, foreign, inactive and ineffective recipients receive one generic refusal.

Administration recognises OWN_OR_DELEGATED only for supported active quotation view/prepare.
The existing SyncMemberAccess self-change, grant-ceiling and tenant guards remain; SaveRole checks
every referenced OWN or OWN_OR_DELEGATED limit before changing a role's duties. Mixed roles refuse.
Normal shipped STAGED duties remain unavailable for assignment; fixture activation is isolated.

## Effective record path

Lists and single-record checks use the same correlated EXISTS predicate before pagination/count.
It uses the server's statement timestamp and half-open [start,end) intervals, same-company/family
identity, active issuer/recipient, current capability paths, no revocation and exact pinned actor,
organisation, capability and ownership epochs. PREPARE evidence requires both participants' view
and prepare; VIEW evidence cannot satisfy prepare. No per-family authority lookup is introduced.
Epoch changes permanently invalidate old grants, including an owner or permission round trip.

The [ledger migration](quotation-access-generation-extension.md) owns immutable typed sources,
strict counter chains, raw storage guards and rollback refusal. Ordinary Actions never rewrite
that evidence. Default starts are calculated after receipt lookup, so retrying an omitted start
retains the original intent. Scheduled same-level overlaps refuse. Revocation after expiry appends
normally, and repeating it does not append another event.

## Verification and remaining gates

The broader quotation/Documents/Administration/CRM/security regression passed 722 tests/4,528
assertions. Final focused Action/consumer/assignment/storage-contention/writer checks passed 26
tests/151 assertions. Whole-project PHPStan passed serially after a parallel worker timed out at
600 seconds; no analysis was suppressed. Formatting passed. Client generation changed only the
Administration request scope enum to add OWN_OR_DELEGATED. Regeneration --check, core-api,
core-ui and main typechecks, and the Vite production build passed. No UI file was changed.

The only existing consumer assertion changed is the owner-approved QuotationOwnScopeTest line
that pinned OWN_OR_DELEGATED as unsupported. Generic-denial, tenant and unknown-resource checks
are unchanged. New tests cover expired/revoked/future/wrong-recipient shares, OWN and MY_UNITS,
foreign identity, hidden 404 parity, independent view/prepare, no onward management, replay,
permanent invalidation and exact recursive writer coverage.

The owner approved the separate narrow catalogue inventory exception: total 59 → 61,
non-existing 51 → 53, and the exact staged list gains only delegate/revoke. Every original assertion
is retained, with additive proofs for STAGED/sensitive/unregistered/automatic exclusion. Fresh private
registration and automatic user/role synchronisation prove neither duty is held by any user or role;
nonempty ordinary grants make that test non-vacuous. The inventory/assignment/writer run passed 13
tests/299 assertions, with one unchanged pre-existing skip. Programmatic comparison confirms only
the approved old expectations changed. No existing guard was weakened.

Not delivered: family access API/resources/client/UI, candidate/history projection, complete
concurrency/scale acceptance, QA4 provenance recipe/backout, demo presets/scenarios and the approved
operator bootstrap. The bootstrap requires its own exact-SHA review and CI before merge. New
features must remain unpublished as completed slices until their usable demo and checks exist.

## Related

- [Accepted record-access design](quotation-record-access-design.md)
- [Storage and Action contract](quotation-record-access-storage.md)
- [Demo personas and presets](quotation-access-demo.md)
- [Issue #77](https://github.com/kaleemnadeem16/erp/issues/77)
