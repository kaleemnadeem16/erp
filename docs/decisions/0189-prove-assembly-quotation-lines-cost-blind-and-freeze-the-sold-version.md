[← Decisions](README.md)

# 0189 — Prove Assembly quotation lines cost-blind and freeze the sold version

- **Status:** accepted (implementation; owner decision 2026-11-13)
- **Date:** 2026-11-13
- **Affects:** Phase 4 Sales quotation draft, preview, issue and Tax source facts; Phase 3 Estimating Assembly versions
- **Builds on:** [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md), [0122](0122-govern-quotation-candidates-adoption-and-revalidation.md), [0056](0056-progressive-tax-calculation-and-recovery.md), [0142](0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md)

## Context

The pricing kernel already had an Assembly source adapter, the draft line table already allowed `ASSEMBLY_WORK`, and the cost-blind control evaluator (#29b) existed, but the draft
workflow refused the kind. The adapter reads cost under the estimating cost-read duty, so a seller without that duty could not even prepare Assembly work. The owner decided on
2026-11-13 that Sales users prepare Assembly quotations **without** Estimating cost-read permission and that confidential cost stays protected.

## Decision

1. **Prove the source, not the cost.** A quotation proves an Assembly line with `AssemblyWorkSourceAdapter::identify()` (via `PricingSourceRegistry::quotationAdapterFor`): company,
   ACTIVE version, the Assembly's work unit and the quantity — with cost explicitly UNKNOWN, never zero. The existing `resolve()` (cost under the estimating duty) is unchanged for
   pricing callers. Draft, preview and the pricing kernel's quotation call use the cost-blind path.
2. **One pinned version.** A line names `assembly_version_id`. It is never replaced silently: a newer activation leaves the line on its version, preview reports the superseded
   version as `SOURCE_INCOMPATIBLE`, and the seller moves to the current version on purpose. A successor draft copies the pinned version. The issued line freezes the same version
   (`quotation_issued_lines.assembly_version_id`, equal to its draft line's by a database guard), so the sold version is identifiable after later activations.
3. **Two scales, no rounding.** The work quantity is proven at the six-place technical scale, then must fit the four places an offer holds (`QuantityContract::commercialLine(4)`);
   a finer value is refused with `QUANTITY_NOT_REPRESENTABLE`. This settles the open "offered scale" question for Assembly: **four places**.
4. **Margin controls stay cost-blind at issue.** For an Assembly line `ConfiguredQuotationControlsProvider` judges floor and reduction controls itself and sends margin controls
   to `EvaluateAssemblyLineControls`, taking only its opaque outcomes; any refusal leaves the control UNEVALUABLE, which blocks issue. Other line kinds are unchanged.
5. **Tax classifies Assembly work by its own explicit fact** (ADR 0056): `tax_quotation_assembly_classifications`, append-only with a non-overlapping period, read by
   `QuotationTaxSourceFacts::assembly()`. No group or company default is invented here; an unclassified Assembly stays `TAX_CLASSIFICATION_REQUIRED`.

## Consequences

- A seller without any `estimating.*` permission can prepare, preview and (through the issuer) issue Assembly lines; the cost figures never reach a draft, preview, edit view or blocker.
- Not delivered: corrections to Assembly tax facts as later versions (Item facts have them), the ADR 0056 Assembly-group and company-services defaults, and Assembly packages or
  alternate units. Material Plan adoption, variance and stock-choice approvals are the next batch; physical allocation, reservation and execution stay in Phase 5.
- No permission is activated; `sales.quotation.prepare` remains STAGED.

## Alternatives rejected

- **Require the estimating cost duty to prepare Assembly work** — contradicts the owner decision and makes pricing a duty of cost holders.
- **Re-point a line to the newest active version on edit** — silently changes what was priced and what Tax classified.
- **Round the offered quantity to four places** — hides a technical measure the Assembly could represent (ADR 0142).

## Related

[Sales README](../../backend/app/Modules/Sales/README.md) · [Estimating README](../../backend/app/Modules/Estimating/README.md) · [Tax domain](../domain/tax.md)
