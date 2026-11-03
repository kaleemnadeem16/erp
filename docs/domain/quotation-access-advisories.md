[← Quotation access fence](quotation-access-qa2-qa4.md)

# Quotation access advisory corrections

Continuation of [#77](https://github.com/kaleemnadeem16/erp/issues/77), including the access-related
reviews on #76, #78 and #80. Duties remain STAGED; this is not a real-holder rollout.

## Rules applied

- Exact document/delivery duties are checked on a freshly loaded active actor **under the same
  company fence** as quotation visibility, before record lookup, projection, generation or replay.
  A cached direct permission on a supplied User cannot survive revocation. Document duties remain
  company-wide; an unsupported limited document grant is refused, never widened to ALL.
- Family and revision loaders both use `QUOTATION_NOT_FOUND`; hidden, foreign and missing families
  have the same message. Assignment no longer needs a separate exception translation.
- Unique-receipt races and deadlock/serialisation failures have independent bounded retry budgets.
  The whole operation restarts, including authority and receipt checks. Caller-owned transactions
  still cannot be silently restarted.
- Count and page are produced by one SQL statement and MVCC snapshot, including a page beyond
  the last result. Only page rows are aggregated. The predicate is applied before either result.
  Supported writers take the fence, and raw revision INSERT already advances the fenced family;
  the reproduced gap is a raw DRAFT header change that changes filter membership between queries.
- Equal-time sorting fixtures freeze the clock **before** real Actions create records. Immutable
  creation timestamps are never edited or their guard disabled to manufacture a tie.
- File recovery free text stays in append-only Files evidence. The document history projection
  returns `restored_identical_bytes` for recovery and only known stable file-health codes otherwise.
  It never echoes an operator's recovery note or a path embedded in that note.
- Delivery-date validation uses the same database `current_date + 1` bound as the existing insert
  guard, preserving its timezone tolerance rather than inventing a new date rule. Impossible dates
  and malformed input are refused by the Action before PostgreSQL parsing.

## Review disposition

| Review item | Treatment |
|---|---|
| #77 A1 cross-company serialisation | Bounded waits and parameterised measurements delivered; production activation still blocked pending owner representative company counts, workload profile and latency budgets. |
| #77 A2 shared-lock upgrade | Real opposing-session reproduction; supported writers take the exclusive fence first. Arbitrary upgrading callers are prohibited. |
| #77 A3 family-first inversion | Real opposing-session reproduction; supported writer inventory proves fence-before-subject order. Arbitrary caller-owned prior locks remain outside that proof. |
| #77 A4 count/page consistency | One-statement snapshot and independent PDO header-update regression. |
| #77 A5 list gaps | OWN/unresolved, suspended and stale direct-grant callers, foreign rows, exact counts and deterministic tie-break covered. |
| #77 A6 used barrier rollback | Intentional safe refusal, with populated evidence and retained row/trigger proofs; not relaxed for development. |
| #77 shared retry counter | Separate budgets; both orderings of unique race and deadlock are tested. |
| #77 inconsistent family refusal | Same stable quotation-not-found refusal as revision loader. |
| #80 stale direct document duty | Fresh exact-duty actor under the fence; direct calls probe existing and unknown records and receipt replay. |
| #80 history reason | Free-text recovery note redacted in projection; original audit reason retained and asserted. |
| #80 OWN/ALL adoption | Existing integrated locator/adapter policy retained and regression-tested. |
| #80 delivery replay authority | Authority remains inside the operation transaction and before existing delivery lookup. |
| #80 delivery date arithmetic | Same database clock/bound as the insert guard; tests move the PHP clock in both directions. |

The transaction-required `LogicException` in `CompanyAccessFence::lock/lockAll` is intentionally
retained: it is a fail-closed programming guard, not a capability verdict. Removing it or returning
an unprotected query would weaken the invariant. Route/Action tests exercise the transactional
entry points; callers must provide the enclosing transaction.

Explicit ALL continues to include unresolved families; OWN excludes them. This preserves the
integrated QA3 rule. Owner-reviewed legacy capture and recovery remain activation prerequisites;
no new mutation restriction or invented historical owner is introduced by this advisory pass.

`RecordManualQuotationDelivery` records a person's claim about an actual historical hand-off; it
sends nothing. Accepted [0135](../decisions/0135-separate-quotation-delivery-evidence-from-customer-consent.md)
and the [delivery contract](quotation-delivery-evidence.md) distinguish that from a new dispatch.
The advice to refuse all such evidence after current expiry/withdrawal is therefore not applied:
a future current-offer dispatch needs its own availability gate and historical-copy purpose.

The safe history continues to include actor IDs and registered renderer contract/version metadata.
These are attributed document timeline facts, not the access-management recipient roster or a
storage identity. `DOCUMENT_NOT_READY` remains the existing authorised-user 422. The local grant
command's description already names document/delivery duties; the approved demo/preset extension
is a separate delivery slice.

## Reviewed choices retained

Line references identify the integrated advisory checkpoint; search the named method if later edits move them.

| Retained behaviour | Source | Rule/reason |
|---|---|---|
| Transaction-required programming refusal | `backend/app/Support/Scope/CompanyAccessFence.php:25` | A builder without the enclosing fence transaction is unsafe; keep LogicException rather than return an unprotected query. |
| ALL includes unresolved; OWN excludes it | `backend/app/Modules/Sales/Support/Quotation/QuotationFamilyScopeAdapter.php:34` | Preserve accepted QA3 recovery semantics; explicit company-wide holders can review the recovery queue, with no invented historical owner. |
| Current availability does not prohibit historical manual hand-off evidence | `backend/app/Modules/Sales/Actions/RecordManualQuotationDelivery.php:33` | Accepted 0135 records a past event and sends nothing; a new dispatch is a separate capability/availability decision. |
| History retains attributed actor IDs and renderer contract/version | `backend/app/Modules/Documents/Actions/ReadDocumentArtefactHistory.php:37` | These are document timeline facts, not the access-management roster or storage identity; free-text restoration notes are separately redacted. |
| Existing authorised-user DOCUMENT_NOT_READY refusal | `backend/app/Modules/Sales/Support/Documents/QuotationDocumentLocator.php:86` | Preserve the existing 422 contract after current authority/visibility checks. |

## Verification and remaining work

Focused authority, ownership, list and retry tests passed before integration. The snapshot suite
passes a real two-connection filter-membership race. The full baseline suite passed 3,371 tests
with one pre-existing skip; this is not a claim that the later integrated head has passed CI.
New pricing/tax routes and migration proofs must be reconciled after merging their published
contracts. Exact-head CI, line references for retained review choices, and the complete slice
status are recorded on #77. QA4 preparation, QA5 and the approved demo extension remain separate.

## Related

- [Safety proofs and harness](quotation-access-safety-proofs.md)
- [Fence/policy semantics](quotation-access-qa2-qa4.md) · [Domain index](README.md)
- [Ordered slices](../phases/phase-4-quotation-record-access-slices.md)
- [Authorisation rules](../architecture/authorization.md)
