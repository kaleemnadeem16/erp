[← Domain index](README.md)

# Quotation delivery and customer evidence

**2026-10-15 · ACCEPTED0135 / SPECIFIED; no R8 delivery adapter IMPLEMENTED or VERIFIED.**
Delivery records what was attempted with which original/rendition, recipient and channel. It cannot
decide commercial consent, revise issue or create operational work. [Artefact contract](quotation-documents.md)
provides exact private verified bytes. Initial scope: authenticated download/print and manual recording.

## Models — annotated pseudo-DDL

```
quotation_dispatch_intents
  id, company_id, revision_id, artefact_id/hash, audience_attachment_manifest_id
  channel, recipient_snapshot, message_content/version/hash, requested_by, request_key/intent_digest
  purpose CURRENT_OFFER | HISTORICAL_COPY | WITHDRAWAL_NOTICE
  policy_version, source_generation, dispatch_fence, state, next_attempt_at nullable
quotation_delivery_attempts
  id, company_id, dispatch_intent_id, attempt_identity, actor/service_identity
  channel, recipient_snapshot, artefact_id/hash, attachment_hashes, occurred_at, recorded_at
  provider_account_reference nullable, provider_request/message_id nullable, intent/fence
quotation_delivery_outcomes
  id, company_id, attempt_id, event_identity, result, provider_event_time nullable, recorded_at
  source, diagnostic_code, evidence_id nullable; append-only authenticated event provenance
quotation_manual_delivery_evidence
  id, company_id, revision_id, artefact_id nullable, actual_document_reference
  channel, representative/recipient, occurred_at/precision/timezone, recorded_by/at
  evidence_id nullable, claimed_result, verification_state
customer_document_evidence_links
  id, company_id, R6_case/component_id, exact_revision_id, document_digest nullable
  kind RECEIPT_ACKNOWLEDGEMENT | DOCUMENT_SIGNATURE | COMMERCIAL_CONSENT_OBSERVATION
  evidence_reference, representative/authority, occurred_at/precision/timezone, recorded_at/by
  acceptance_method_policy_version, source/recipient/terms/scope binding, verification_state
```

Tenant-safe references and company-scoped unique intent/request/event identities; immutable attempt,
recipient/message/attachment snapshots and outcomes; mutable scheduling/head generations only.
Append corrections rather than editing who/what/when was sent. File hash proves content identity,
not customer authority or business uniqueness. Same recipient can receive several legitimate
deliveries; dedup uses dispatch intent and stable business purpose, not address/hash alone.

## Actions, Requests and initial API/UI

| Specified Action | Inputs and result | Boundary |
|---|---|---|
| DownloadQuotationArtefact / RecordPrintInvocation | Scoped artefact identity, exact original/rendition, actor; authenticated bytes or local invocation evidence | Independent file access; download request/print dialog cannot prove printer success or customer receipt |
| RecordManualQuotationDelivery | Exact document/revision/artefact when known, actual channel/recipient/date/precision/source, evidence and key | Captures claimed/verified facts truthfully; unknown historical artefact is pending evidence, never an invented official send |
| PrepareQuotationDispatch / DispatchQuotation | Verified immutable artefact and attachments, recipient/message/purpose, policy/source generations, idempotency key | Later email adapter only; must enforce current source eligibility and final dispatch fence, credentials/service scope |
| RecordDeliveryOutcome / ReconcileUnknownDelivery | Exact intent/attempt/provider IDs and authenticated event/evidence | Later provider adapter; appends evidence and derives safe status, never customer acceptance |
| RecordCustomerDocumentEvidence | R6 case, typed kind, exact document/scope/terms/identity and authority evidence, actual/recorded times | Observations feed R6; no direct accepted-business write or target creation |

Requests and Actions both enforce duty/company/effective subject and evidence scope. Suggested API:
scoped artefact downloads/print invocation, manual deliveries and history, typed customer-evidence
links; later dispatch prepare/send/status/reconcile and provider callback. R9 fixes vocabulary/grants.
Resources show exact document and recipient safely, outcome source/time and pending/unknown meaning,
current allowed actions, no credentials/storage paths/protected internal evidence. UI separates
Issued status, Document generation, Delivery history and Customer decision; it never uses one Sent
badge for all. Manual form has actual date/recipient/channel, evidence precision and correction flow.
Initial routes cannot expose dormant email/public portal callbacks before their contracts are delivered.

## Outcome meanings and retries

QUEUED means durably awaiting dispatch. SUBMITTED means handed to provider transport, not receipt.
PROVIDER_ACCEPTED records provider acknowledgement, not proof recipient read it. FAILED states known
failure with source/cause; UNKNOWN preserves timeout/ambiguous result. RECEIPT_EVIDENCE is a separately
attributed observation of receipt, ACKNOWLEDGEMENT is the customer's stated acknowledgement, and neither
is acceptance by default. Bounced after provider acceptance appends outcome; do not erase prior facts.
Per-recipient outcomes avoid one successful address masking another failure. Provider webhooks may
be duplicated/out of order; verify account/company, signature/freshness/replay ID and deterministic
evidence ordering, quarantine contradictory/unmapped events, no unauthenticated ID-based updates.

Email stage requires approved sender credentials/provider adapter, durable DB intent/outbox and
lease/fence, idempotent receiver/provider key if supported, bounded backoff and operator reconciliation.
Crash before submission may retry same intent when safe; provider accepted but reply lost is UNKNOWN.
First query provider by stable identity or await authentic outcome; if provider cannot establish status,
keep unknown and require separately authorised documented resend risk decision. Do not generate a fresh
send automatically. No exactly-once claim. Changing recipient/content/attachments requires new explicit
linked dispatch intent, preserving original; retry does not secretly pick today's contact address.

Current actor/service authority and source eligibility are rechecked before external submission.
Withdrawal/replacement/expiry races use Sales source generation and dispatch fence at a defined
authorisation point. If invalidated before that point, refuse current-offer submission. Once external
submission has begun it cannot be recalled atomically; record DISPATCH_IN_PROGRESS/UNKNOWN or provider
result truthfully and create a separately authorised corrective notice when needed. Never hold database
locks over provider I/O and claim a distributed transaction. Notification afterCommit alone is not
durable retry. Dispatch authorisation history survives later access changes; no new intent or retry
may use revoked authority. A bounded already-authorised in-flight action is disclosed as such.

## R6 acceptance evidence and method policies

Company/path policy versions state whether that consent method needs exact artefact, delivery or
served revision evidence. Policy does not retroactively invent prerequisites for an authentic earlier
agreement; unresolved historical proof becomes an observation/reconciliation under0127.
RecordCustomerDocumentEvidence attaches typed observation to R6 shared case; only R6 Preview/Record
Acceptance validates representative/employee/seller authority, exact revision and actual eligibility,
scope/quantity/option/terms, quantities/claims/benefits and accepted method policy under its locks.
Missing required artefact/evidence blocks authoritative recording for that method, not every manual
PO/email path. Signature image, file hash, portal click or opened PDF cannot decide full acceptance.

Valid manually verified PO can refer to an exact issued quotation without an ERP email receipt. If
render failed, preserve issued commercial facts; whether the particular externally agreed content is
sufficient is established by the authorised R6 evidence method, never by fabricating an ERP PDF/send.
R6 late/conflicting consent remains pending until historical eligibility/current claims and delivered
R7 correction effects are reconciled. Binary evidence exposure requires delivered secure file adapter.
Customer acknowledgement alone creates no claim reservation, operational hold or forecast win.

## Expiry, withdrawal, resend and correction

An ordinary send needs currently actionable eligible scope; expired/withdrawn/replaced scope cannot
be presented as current. Explicit HISTORICAL_COPY is separately authorised, labelled by a cover with
current status/time and preserves exact archived original unmodified. Historical resend cannot extend
validity or change agreed scope. Withdrawal notice identifies affected unaccepted scope and channel
result; it cannot erase prior authentic consent or retrospectively claim customer receipt. Partial
availability follows actual delivered R5/R6/R7 adapters; no document button bypasses their gates.

## Security, audits and verification

Subject-read access alone need not disclose confidential consent attachments, recipient personal data,
delivery diagnostics or other commercial terms; R9 finalises independently scoped projections. Reuse
private upload/access/recovery from document domain, protected logs and append-only actor/time/authority.
Import/console workers call the same guarded Actions with explicit actor and evidence provenance.
Provider worker/callback identities are restricted to their approved company/account/intent, no global
human permission impersonation. Rate limits, event validation and retention policy precede enablement.

Required implementation cases: no-capability/direct Action/cross-company file/recipient denial,
manual evidence corrected append-only, identical/fresh-key duplicated observation, immutable bytes
after branding/terms change, per-recipient bounce, duplicate/out-of-order callback, lost reply/no fresh
send, expiry/withdrawal race, download without receipt, acknowledgement/signature without acceptance,
and valid PO through R6 versus missing required method evidence. Existing CRM/PO printing unaffected.

## Related

- [0135](../decisions/0135-separate-quotation-delivery-evidence-from-customer-consent.md)
- [Artefacts/recovery](quotation-documents.md) · [R8 slices](../phases/phase-4-r8-implementation-slices.md)
- [Customer decisions](sales-customer-decisions.md) · [R5 lifecycle](quotation-lifecycle.md)
- [R9 proposed review](../phases/phase-4-r9-authority-reporting-review.md)
