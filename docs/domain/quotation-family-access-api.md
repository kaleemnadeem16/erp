[← Domain](README.md)

# Quotation family access API

**Local implementation in progress, unpublished (2026-11-05).** Family administration is separate
from immutable commercial revision operations. Duties remain STAGED; no holder is granted a right
and production activation remains blocked. The demo bootstrap has no public route.

All routes are under `/api/v1/quotation-families/{family}`. Every Request guards current duties;
every Action repeats current authority under the company fence. A capability absence is 403;
unknown/foreign/hidden families return the same non-disclosing 404. Management always requires
non-delegated eligibility for each duty. Received sharing cannot supply it.

| Method / suffix | Duties | Body / projection |
|---|---|---|
| GET `access` | view, including compatible named path | Safe creator/owner/unit/generations and server allowed-actions; independent history gates below |
| GET `access-candidates` | view + assign for owner/unit; view + delegate (+ prepare for PREPARE recipient) | Minimal active company directory identities: id/name only; directory presence is not grant authority |
| POST `reassign-owner` | view + assign | owner_id, expected_ownership_generation, expected_access_generation, reason |
| POST `change-access-unit` | view + assign | explicit nullable access_unit_id, both expected generations, reason |
| POST `grant-access` | view + delegate (+ prepare for PREPARE) | recipient_id, VIEW/PREPARE level, optional starts_at/ends_at, both expected generations, reason |
| POST `revoke-access` | view + revoke | grant_id, both expected generations, reason; expiry does not prevent revocation |

Writes require an `Idempotency-Key` header of 1–85 characters. Identical retries replay before
staleness checks, after current actor/family authority is proved again. A changed intent under the
same key is 409 REQUEST_KEY_REUSED. Replay sets `Idempotency-Replayed: true`. New shares return
201; no-op/replay and the other writes return 200. Responses use explicit Resources, never models.
They contain stable identities/generations/changed/replayed, no authority epochs or financial facts.

## Histories and selectors

GET access exposes ownership history only with independently scoped assign+view; share roster
only with independently scoped delegate/revoke+view. No raw reason text is returned. Ownership
history has `ownership_before`; shares use `shares_before`, both exclusive positive generation
cursors. `history_per_page` defaults to 25, maximum 50. Each collection has has_more and a nullable
next_generation. Grant history orders by its audited ledger generation, not an assumed timestamp
or sequence. An ordinary viewer querying a protected history field is refused.

Candidate query: required kind owner/recipient/unit, optional VIEW/PREPARE level, filter[search]
(literal name search), per_page (default 25, maximum 50), and cursor. Cursor results have no total;
links retain the validated query. Names/ids of active members are directory choices, not promises
of compatible recipient scope. The write Action rejects ineffective/inactive/foreign/self recipients.
Selected/current labels come from response facts, not first-page searches. No emails, roles,
permissions, authentication details or costs are exposed. Pagination performs no per-person query.
Malformed cursor objects are validated before invoking the framework cursor machinery and return
422; unknown filters/includes are refused.

## Refusals and concurrency

- QUOTATION_OWNERSHIP_STALE (422): refresh and review current generations before a new attempt.
- QUOTATION_OWNERSHIP_UNRESOLVED (422): needs an approved internal baseline, never inferred ownership.
- QUOTATION_SHARE_RECIPIENT_INELIGIBLE (422): recipient lacks current compatible independent authority;
  self, inactive and foreign recipient ids receive the same message.
- QUOTATION_SHARE_OVERLAP (422): revoke/replace instead of overlapping an effective same-level interval,
  including scheduled intervals. Epoch-invalid or revoked rows do not block a new grant.
- REQUEST_KEY_REQUIRED (422) / REQUEST_KEY_REUSED (409): normal shared operation protocol.
- REASON_REQUIRED / INVALID_INPUT / ACCESS_UNIT_NOT_FOUND (422): correct the indicated field.

Start/end are explicit ISO 8601 instants with time zones, half-open [start,end). Omitted start is
calculated after receipt lookup, so retry intent is stable. Expiry, revocation and permanent epoch
invalidation use the audited ledger contract. Current issuer/recipient capability paths are checked
on use. The family/receipt/evidence commit atomically through the existing Actions.

## Related

- [Interaction and coverage contract](quotation-family-access-ui.md)
- [Sharing checkpoint and checks](quotation-sharing-implementation.md)
- [Audited ledger migration](quotation-access-generation-extension.md)
- [API conventions](../architecture/api-conventions.md)
