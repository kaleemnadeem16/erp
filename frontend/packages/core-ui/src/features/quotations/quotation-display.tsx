import { ZodError } from "zod";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { StatusBadge } from "../../components/ui/status-badge";
import { formatDate } from "../../lib/format-date";
import {
  describeLoadError,
  getApiErrorMessage,
  getApiReasonCode,
  isTransientFailure,
  isApiError,
} from "../../lib/api-errors";
import type {
  QuotationAmount,
  QuotationDraft,
  QuotationPreview,
} from "./quotation-contract";

const REASON_LABELS: Record<string, string> = {
  TAX_NOT_DETERMINED: "Tax has not been determined.",
  ISSUE_NOT_AVAILABLE: "Issuing is not available.",
  CONTROLS_NOT_CONFIGURED: "Financial controls have not been configured.",
  PRICE_NOT_ENTERED: "A unit price has not been entered.",
  PRICING_DATE_REQUIRED: "Set a pricing date before reviewing prices.",
  ROUNDING_POLICY_CHANGED:
    "The rounding policy has changed; review the entered prices.",
  CHOICE_BASELINE_MISSING: "Choose a baseline for each set of alternatives.",
  NO_LINES: "Add quotation lines before reviewing totals.",
  NET_NOT_COMPUTED: "The line net has not been calculated.",
  UNIT_PRICE_NO_LONGER_FITS:
    "Review unit prices that exceed the current precision policy.",
  SOURCE_NOT_FOUND: "The source Item is unavailable or archived.",
  SOURCE_INCOMPATIBLE: "The source is not compatible with this quotation line.",
  SOURCE_KIND_UNSUPPORTED:
    "This source type is not supported for quotation pricing.",
  SOURCE_EVIDENCE_NOT_AUTHORISED:
    "The source evidence is not available for this workflow.",
  SELLING_UNIT_UNSUPPORTED:
    "Use the Item's supported stock unit for this quotation line.",
  PRICE_BASIS_INCOMPATIBLE:
    "The price basis does not match this quotation line.",
  NO_ELIGIBLE_PRICE: "No eligible price is available for this line.",
  PRICING_RULE_CONFLICT: "Conflicting pricing rules need review.",
  PARTY_NOT_FOUND: "The recipient is unavailable or archived.",
  INVALID_INPUT: "The quotation inputs need correction.",
};
function unitReference(id: number | null): string {
  return id === null ? "Unit not specified" : `Unit reference #${id}`;
}
function label(value: string): string {
  const text = value.toLowerCase().replaceAll("_", " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function QuotationStatus({ state }: { state: string }) {
  return (
    <StatusBadge tone={state === "DRAFT" ? "draft" : "pending"}>
      {label(state)}
    </StatusBadge>
  );
}

/** Never display a partial or unknown value as a complete zero; no client arithmetic. */
export function QuotationMoney({
  value,
  currency,
}: {
  value: QuotationAmount;
  currency: number;
}) {
  const unit = `Currency reference #${currency}`;
  if (value.certainty === "UNKNOWN") return <span>Unknown — {unit}</span>;
  return (
    <span className="tabular-nums">
      {value.certainty === "PARTIAL"
        ? `Partial — at least ${value.known_amount}`
        : value.amount}{" "}
      · {unit}
    </span>
  );
}

export function QuotationFailure({
  error,
  retry,
}: {
  error: unknown;
  retry?: () => void;
}) {
  const status = isApiError(error) ? error.status : null;
  const missing = status === 404;
  const canRefreshReview =
    status === 422 && getApiReasonCode(error) === "QUOTATION_DRAFT_CHANGED";
  const canRetry =
    canRefreshReview ||
    (!(error instanceof ZodError) && isTransientFailure(error));
  return (
    <div
      role="alert"
      className="space-y-3 rounded-lg border border-border bg-card p-4"
    >
      <h2 className="font-semibold">
        {missing ? "Quotation unavailable" : "Could not load quotation"}
      </h2>
      <p className="text-sm">
        {missing
          ? "This quotation is not available."
          : status === 422
            ? (getApiErrorMessage(error) ??
              "The quotation needs to be reviewed again.")
            : error instanceof ZodError
              ? "The server returned an unsupported quotation response. Contact support if this continues."
              : describeLoadError(error)}
      </p>
      {retry && canRetry && (
        <Button variant="outline" onClick={retry}>
          {canRefreshReview ? "Refresh review" : "Try again"}
        </Button>
      )}
    </div>
  );
}

export function QuotationMetadata({ draft }: { draft: QuotationDraft }) {
  return (
    <DetailPageLayout>
      <DetailSection title="Quotation">
        <DetailField label="Revision">
          #{draft.revision_id} · Revision {draft.revision_ordinal}
        </DetailField>
        <DetailField label="Status">
          <QuotationStatus state={draft.state} />
        </DetailField>
        <DetailField label="Family reference">#{draft.family_id}</DetailField>
        <DetailField label="Input generation">
          {draft.input_generation}
        </DetailField>
        <DetailField label="Recipient reference">
          {draft.recipient_party_id === null
            ? "Not specified"
            : `Party #${draft.recipient_party_id}`}
        </DetailField>
        <DetailField label="Currency reference">
          #{draft.currency_id}
        </DetailField>
        <DetailField label="Lines">{draft.line_count}</DetailField>
        <DetailField label="Opportunity reference">
          {draft.current_opportunity_id === null
            ? "Not linked"
            : `#${draft.current_opportunity_id}`}
        </DetailField>
        <DetailField label="Created by reference">
          #{draft.created_by}
        </DetailField>
      </DetailSection>
      <DetailSection title="Dates">
        <DetailField label="Pricing date">
          {formatDate(draft.pricing_date)}
        </DetailField>
        <DetailField label="Tax estimate date">
          {formatDate(draft.tax_estimate_date)}
        </DetailField>
        <DetailField label="Valid through (local date)">
          {formatDate(draft.valid_through_local_date)}
        </DetailField>
      </DetailSection>
    </DetailPageLayout>
  );
}

function reasonLabel(reason: string): string {
  const [code, reference] = reason.split(":");
  if (
    code === "CHOICE_BASELINE_MISSING" &&
    reference &&
    /^[1-9]\d*$/.test(reference)
  ) {
    return `Choose a baseline for choice group #${reference}.`;
  }
  return (
    REASON_LABELS[code ?? ""] ??
    "A quotation check needs attention. Contact support if it persists."
  );
}

function Reasons({ values }: { values: string[] }) {
  return values.length ? (
    <ul className="list-inside list-disc text-sm">
      {values.map((reason, index) => (
        <li key={`${reason}-${index}`} className="break-words">
          {reasonLabel(reason)}
        </li>
      ))}
    </ul>
  ) : null;
}

export function QuotationTotals({ preview }: { preview: QuotationPreview }) {
  return (
    <DetailSection
      title="Preview totals"
      description="Server-calculated net amounts. Optional scope is separate; tax is undetermined."
      fullWidth
    >
      <DetailField label="Base and baseline net">
        <QuotationMoney
          value={preview.totals.net}
          currency={preview.currency_id}
        />
        <Reasons values={preview.totals.net.reasons} />
      </DetailField>
      {preview.totals.optional_net && (
        <DetailField label="Optional net">
          <QuotationMoney
            value={preview.totals.optional_net}
            currency={preview.currency_id}
          />
          <Reasons values={preview.totals.optional_net.reasons} />
        </DetailField>
      )}
      <DetailField label="Tax">
        Unknown — tax has not been determined
      </DetailField>
      <DetailField label="Total including tax">Unknown</DetailField>
    </DetailSection>
  );
}

/** Cards keep offered/priced/stock measures separate and usable at phone width. */
export function QuotationLines({ preview }: { preview: QuotationPreview }) {
  return (
    <DetailSection title="Quotation lines" fullWidth>
      <div className="col-span-full space-y-4">
        {!preview.lines.length && (
          <p className="text-sm">No quotation lines.</p>
        )}
        {preview.lines.map((line, index) => (
          <article
            key={line.id}
            aria-label={`Line ${index + 1}`}
            className="min-w-0 space-y-3 border-b border-border pb-4 last:border-0"
          >
            <h3 className="break-words font-semibold">
              {line.section_title ? `${line.section_title} — ` : ""}
              {line.description || `Line ${index + 1}`}
            </h3>
            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0 [&>*]:break-words">
              <DetailField label="Scope">
                {label(line.membership)}
                {line.is_baseline ? " · Baseline" : ""}
                {line.choice_group_id
                  ? ` · Choice group #${line.choice_group_id}`
                  : ""}
              </DetailField>
              <DetailField label="Source">
                {label(line.source_kind)}
                {line.item_id ? ` · Item reference #${line.item_id}` : ""}
              </DetailField>
              <DetailField label="Offered quantity">
                {line.offered.quantity} ·{" "}
                {line.manual_measure ?? unitReference(line.offered.uom_id)}
              </DetailField>
              <DetailField label="Priced quantity">
                {line.priced
                  ? `${line.priced.quantity} · ${unitReference(line.priced.uom_id)}`
                  : "Not resolved"}
              </DetailField>
              <DetailField label="Stock quantity">
                {line.stock
                  ? `${line.stock.quantity} · ${unitReference(line.stock.uom_id)}${line.stock.conversion_version === null ? "" : ` · Conversion ${line.stock.conversion_version}`}`
                  : "Not applicable"}
              </DetailField>
              <DetailField label="Unit price">
                {line.unit_price === null
                  ? "Not entered"
                  : `${line.unit_price} · Currency reference #${preview.currency_id}`}
              </DetailField>
              <DetailField label="Line net">
                <QuotationMoney
                  value={line.net}
                  currency={preview.currency_id}
                />
                <Reasons values={line.net.reasons} />
              </DetailField>
              {line.fx && (
                <DetailField label="Pinned exchange evidence">
                  {label(line.fx.current)} ·{" "}
                  {line.base_unit_price === null
                    ? "Base unit price not available"
                    : `${line.base_unit_price} in base currency reference #${line.fx.base_currency_id}`}
                </DetailField>
              )}
              {line.settlement && (
                <DetailField label="Settlement evidence">
                  Computed:{" "}
                  {line.settlement.computed ??
                    `${line.settlement.computed_fraction.numerator}/${line.settlement.computed_fraction.denominator}`}{" "}
                  · Residual: {line.settlement.residual}
                </DetailField>
              )}
            </div>
            <Reasons values={line.issues} />
          </article>
        ))}
      </div>
    </DetailSection>
  );
}

export function QuotationReview({ preview }: { preview: QuotationPreview }) {
  return (
    <DetailPageLayout>
      <DetailSection
        title="Draft review"
        description="Read-only preview. This does not save, issue or adopt financial evidence."
        fullWidth
      >
        <DetailField label="Input generation">
          {preview.input_generation}
        </DetailField>
        <DetailField label="Pricing date">
          {formatDate(preview.pricing_date)}
        </DetailField>
        <DetailField label="Issue readiness">Not available</DetailField>
        <DetailField label="Controls">
          {preview.controls.evaluated} evaluated ·{" "}
          {label(preview.controls.status)}
        </DetailField>
        <div className="col-span-full">
          <Reasons values={preview.issue_blockers} />
          <Reasons values={preview.issues} />
        </div>
        {preview.revalidation?.requires_review && (
          <div role="status" className="col-span-full">
            <p className="font-semibold">Pricing evidence needs review</p>
            <Reasons values={preview.revalidation.reasons} />
            {preview.revalidation.non_conforming_lines.length > 0 && (
              <p>
                Prices requiring review on line references:{" "}
                {preview.revalidation.non_conforming_lines.join(", ")}
              </p>
            )}
          </div>
        )}
      </DetailSection>
      {preview.rounding_policy && (
        <DetailSection title="Rounding policy" fullWidth>
          <DetailField label="Policy">
            {label(preview.rounding_policy.source)} · Version{" "}
            {preview.rounding_policy.version_no ?? "default"}
          </DetailField>
          <DetailField label="Unit price precision">
            {preview.rounding_policy.unit_price_scale} decimal places ·{" "}
            {preview.rounding_policy.rounding_mode}
          </DetailField>
          <DetailField label="Settlement basis">
            {label(preview.rounding_policy.settlement_basis)}
          </DetailField>
          <DetailField label="Document net basis">
            {label(preview.rounding_policy.document_net)}
          </DetailField>
        </DetailSection>
      )}
      <QuotationLines preview={preview} />
      <QuotationTotals preview={preview} />
    </DetailPageLayout>
  );
}
