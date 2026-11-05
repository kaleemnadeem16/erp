[← Decisions](README.md)

# 0160 — Correct manual quotation deliveries by appending reasoned records

- **Status:** accepted as the safest default under the existing append-correction rule (0137, DOC4); the owner may refine it. Business rules marked "decision" were not specified in the docs.
- **Date:** 2026-11-05
- **Affects:** Sales manual delivery evidence (`quotation_document_deliveries`), the document screen, `sales.delivery.record` (still STAGED)

## Context

DOC4 says manual delivery evidence is append-only and a mistake is corrected by a new record that names the old one. The first slice allowed that only as a full
replacement with no reason, and a delivery recorded by mistake that never happened could not be taken back at all.

## Decision

- A **correction** is a new row that names the record it replaces (`corrects_id`), carries the replacement values and a required **reason** (decision: always required, 1 to 500 characters).
- A **withdrawal** says the delivery did not happen as recorded. It is a row that repeats the values of the withdrawn record (the database checks this) with a required reason.
  A withdrawn record is final (decision): a delivery that really happened later is a new record, not an undo.
- Only the **current** record of a chain can be corrected (decision): one correction per record already held by a unique index; a withdrawn record cannot be corrected.
- The earlier record is never edited, deleted or truncated (the existing guards stand). The list marks each row `kind` (RECORD, CORRECTION, WITHDRAWAL), `superseded_by_id` and `is_current`.
- Authority is `sales.delivery.record` plus the right to see the quotation (decision: no new duty; STAGED duties stay STAGED, no grants or holder recipes). The route is
  `POST /quotations/{revision}/deliveries/{delivery}/correction`, idempotent per actor and key, non-disclosing 404 for an unseen quotation.
- It remains a person's statement: never receipt, reading or acceptance by the customer; nothing is sent.
- The older `corrects_id` on `POST /deliveries` still works for compatibility (no reason required there); the screen uses the new route only.
- Document history shows only allowlisted fields per event (page count; who handed a file out; the stable reason a stored file was marked). Renderer contract and version, purpose
  and file health states stay inside Documents.

## Consequences

Additive migration `2026_11_30_000000_add_quotation_delivery_correction_reason_and_withdrawal` (populated rollback refuses once a reasoned correction or withdrawal exists).
No new table. Company branding and asset references (issue #23) are a separate decision awaiting the owner.
