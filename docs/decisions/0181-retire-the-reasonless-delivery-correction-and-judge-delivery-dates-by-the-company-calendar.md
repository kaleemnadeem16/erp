[← Decisions](README.md)

# 0181 — Retire the reasonless delivery correction; judge delivery dates by the company calendar

- **Status:** accepted (owner decisions D4 and D9 of 2026-11-10)
- **Date:** 2026-11-10
- **Affects:** Sales manual delivery evidence (`POST /quotations/{revision}/deliveries`, the correction route, `quotation_document_deliveries` insert guard)
- **Supersedes:** the one exception in [0160](0160-correct-manual-quotation-deliveries-by-appending-reasoned-records.md) ("`corrects_id` on `POST /deliveries` still works, no reason required"); the rest of 0160 stands
- **Builds on:** 0137 (append-only evidence), 0160

## Context

An independent review of issuing, documents and delivery (issue #95, B8 and H2) found two things. 0160 kept an older way to correct a delivery that needs no reason, beside the
new route that always needs one. And the limit on how far ahead a delivery may be dated was taken from the database session date (`current_date + 1`), which is the UTC day, not the
day on the company's calendar. For a company five hours east of UTC the two differ for the first five hours of every local day.

## Decision

- **Corrections always carry a reason.** `POST /quotations/{revision}/deliveries` only records a new delivery. A `corrects_id` sent to it is refused with a validation error (422); it is
  never quietly turned into a plain new record. The record Action no longer takes a correction target. A correction or withdrawal goes to
  `POST /quotations/{revision}/deliveries/{delivery}/correction` with a required reason (0160 otherwise unchanged: current record only, withdrawn is final, idempotent per actor and key).
  The screen already used only that route. Rows written earlier through the old path keep a null reason; they are history and are not rewritten.
- **The date window is the company calendar day.** A delivery may be dated up to tomorrow on the company's calendar (its `timezone`), judged by `DeliveryDateWindow` for both recording and
  correcting. The database insert guard applies the same rule (migration `2026_12_10_800000_judge_delivery_date_against_the_company_calendar`, which only replaces the guard function).
  There is still no lower limit: a late record of an earlier handover is legitimate.

## Alternatives rejected

- Keep the old path for compatibility: it is the only way to append a correction with no stated reason, the rule 0160 meant to make universal. No client other than this repository's uses it.
- A database check that a correction row has a reason: older rows legitimately lack one, so it would need a not-validated constraint and a backfill decision; the single remaining write path already enforces it. Can be added later.
- Take the date from the application server's UTC clock: wrong in the same way for zones other than UTC.

## Consequences

The OpenAPI request for the record route now marks `corrects_id` as prohibited; the generated client is regenerated. The guard function is replaced in both directions by the migration (the
rollback restores the earlier function after locking the table).

## Related

[0160](0160-correct-manual-quotation-deliveries-by-appending-reasoned-records.md) · [quotation delivery evidence](../domain/quotation-delivery-evidence.md)
