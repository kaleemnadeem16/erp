# 0154 — Record draft concessions, tax declarations and list values as versioned, per-row facts

- **Status:** proposed (owner acceptance pending; implemented on `develop` under the owner's task authorisation)
- **Date:** 2026-11-03
- **Affects:** Sales (draft pricing, list), Tax (source facts), [sales-pricing](../domain/sales-pricing.md), [tax](../domain/tax.md)

## Context

The accepted R3/R4 designs (0115-0120) describe discretionary discounts, reasoned final-net overrides, explicit manual tax facts and append-only tax evidence, but left open how a draft
stores them, how "approval needed" is recorded before an approvals engine exists, how a wrong tax source fact is put right, and how the quotation list may show a recipient name and
an amount without leaking either. The docs were silent, so the safest default-deny behaviour was chosen.

## Decision

1. **Concessions are draft inputs with actor and time.** One discount slot per line and one per document; a final-net override is separate, reasoned, may only reduce the calculated net,
   excludes a discount, and needs the price it replaces. All arithmetic runs on the pricing kernel; a document discount is spread over counted lines by largest remainder so the header
   stays the sum of its settled lines. Discount, override and commercial view are three STAGED duties (family scope), judged in the Request and the Action; none is activated, granted or
   added to the dev preview.
2. **Approval need is a recorded code, default-deny.** An override always needs approval. A discount needs it unless a published reduction control proves it within a limit; no control,
   no reduction control, a mismatched rounding policy or uncomputable figures mean "not proven within a limit". The draft is then not issuable (`APPROVAL_REQUIRED`). Only a code is stored
   or returned.
3. **Tax is corrected by new versions.** Tax source facts gain an append-only correction version (`CorrectQuotationTaxSource`). A manual draft line carries the seller's versioned tax
   declaration (the "manual explicit" fact); Item lines cannot, their classification being Tax's. Sales' tax facts reader uses these and defaults nothing.
4. **List values are per-row and null when hidden.** `recipient_name` follows Party read (no duty or scope exists; active member + company scope); `net_amount` is the frozen net of an issued
   revision and needs commercial view for that row's family. Hidden equals absent; neither is sortable or filterable.

## Consequences

- **Positive:** nothing is mutated; every concession and declaration names who and when; no cost, margin, limit or threshold reaches a reader; issue cannot slip past a needed approval.
- **Negative:** until a reduction control is published every discount blocks issue; a draft row shows no amount in the list; the approvals engine, a customer-facing discount policy and
  Party read duties remain future work.
- **Requires:** the holder recipes for the three duties are an owner decision; the dev preview does not include them.

## Alternatives considered

**Store the approval flag only live** - loses the record of when it was raised. **Allow overrides above the calculated net** - the customer document would show an unexplained surcharge.
**Show a draft's previewed net in the list** - a list would reprice N drafts and present a figure that is not a fact. **Edit a wrong tax fact in place** - breaks append-only evidence.
**Gate the name on the commercial duty** - the name is Party data, not a commercial figure.

## Research basis

Local technical decision refining accepted 0115-0120; no new external research.
