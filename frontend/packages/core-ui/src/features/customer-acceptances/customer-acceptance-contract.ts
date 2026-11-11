import { z } from "zod";
import { getApiReasonCode, isApiError } from "../../lib/api-errors";
import type { Observation } from "../customer-decisions/customer-decision-contract";

/**
 * Contract for the acceptance routes (generated client: quotationsAcceptancePreview, quotationsAcceptancesStore,
 * customerAcceptancesShow, customerAcceptancesReadiness):
 *   GET  /v1/quotations/{revision}/acceptance-preview   exact issued terms, an opaque review token (ten minutes)
 *   POST /v1/quotations/{revision}/acceptances          record the SAME intent (Idempotency-Key, preview_token, unchanged_terms)
 *   GET  /v1/customer-acceptances/{acceptance}          the immutable agreed scope
 *   GET  /v1/customer-acceptances/{acceptance}/readiness the current, separate readiness assessment
 * Consent is recorded and nothing else happens: no order, job, reservation, invoice, revenue or outcome. Every response is parsed
 * (and stripped to this shape) before a screen sees it.
 */
const id = z.number().int().positive();
const decimal = z.string().regex(/^-?\d+(?:\.\d+)?$/);
const money = decimal.nullable();

export const intentSchema = z.object({
  revision_id: id,
  component_id: id,
  evidence_id: id,
  issued_line_id: id,
  expected_case_generation: z.number().int().nonnegative(),
  expected_scope_generation: z.number().int().nonnegative(),
  expected_choice_generation: z.number().int().nonnegative().nullable(),
  unchanged_terms: z.boolean(),
});
export const termsSchema = z.object({
  issued_line_id: id,
  description: z.string(),
  quantity: decimal,
  uom_id: id.nullable(),
  unit_price: decimal,
  currency_id: id,
  net: decimal,
  tax: money,
  gross: money,
});
export const previewSchema = z.object({
  intent: intentSchema,
  terms: termsSchema,
  unchanged_terms_statement: z.string(),
  warnings: z.array(z.string()),
  preview_token: z.string().min(1),
  expires_in_seconds: z.number().int().nonnegative(),
});
export const outcomeSchema = z.object({
  acceptance_id: id,
  case_id: id,
  replayed: z.boolean(),
});
export const acceptanceSchema = z.object({
  id,
  case_id: id,
  decision_component_id: id,
  revision_id: id,
  consent_evidence_id: id,
  policy_version_id: id,
  recorded_by: id,
  recorded_at: z.string(),
  scope: z.object({
    issued_line_id: id,
    logical_scope_id: id,
    choice_group_id: id.nullable(),
    description: z.string(),
    source_kind: z.string(),
    quantity: decimal,
    uom_id: id.nullable(),
    unit_price: decimal,
    currency_id: id,
    net: decimal,
    tax: money,
    gross: money,
  }),
  acceptance_policy: z.string(),
  unchanged_terms: z.boolean(),
});
export const readinessSchema = z.object({
  acceptance_id: id,
  assessment_version: z.string(),
  path: z.string(),
  status: z.string(),
  missing: z.array(z.string()),
});
export type AcceptanceIntent = z.infer<typeof intentSchema>;
export type AcceptancePreview = z.infer<typeof previewSchema>;
export type AcceptanceOutcome = z.infer<typeof outcomeSchema>;
export type Acceptance = z.infer<typeof acceptanceSchema>;
export type AcceptanceReadiness = z.infer<typeof readinessSchema>;

export const readPreview = (value: unknown) => previewSchema.parse(value);
export const readOutcome = (value: unknown) => outcomeSchema.parse(value);
export const readAcceptance = (value: unknown) => acceptanceSchema.parse(value);
export const readReadiness = (value: unknown) => readinessSchema.parse(value);

/** What identifies one acceptance request, before the review. The scope generation is 0 for a scope nobody has claimed. */
export interface AcceptanceTarget {
  revisionId: number;
  componentId: number;
  evidenceId: number;
  issuedLineId: number;
  caseGeneration: number;
}

export interface RecordAcceptanceInput {
  intent: AcceptanceIntent;
  previewToken: string;
  requestKey: string;
}

export const READINESS_PATHS = ["SERVICE_JOB", "GOODS_ORDER"] as const;
export type ReadinessPath = (typeof READINESS_PATHS)[number];
export const READINESS_PATH_LABELS: Record<ReadinessPath, string> = {
  SERVICE_JOB: "Service job",
  GOODS_ORDER: "Goods order",
};

export interface CustomerAcceptanceGateway {
  preview: (input: {
    revisionId: number;
    intent: Omit<AcceptanceIntent, "revision_id" | "unchanged_terms">;
  }) => Promise<AcceptancePreview>;
  record: (input: RecordAcceptanceInput) => Promise<AcceptanceOutcome>;
  show: (acceptanceId: number) => Promise<Acceptance>;
  readiness: (
    acceptanceId: number,
    path: ReadinessPath,
  ) => Promise<AcceptanceReadiness>;
}

/**
 * The issued read does not say whether a scope belongs to a set of alternatives. The server expects no choice generation for an
 * ordinary scope and one (0 while nobody has chosen) for an alternative, and answers PREVIEW_TOKEN_STALE for the wrong one. A preview
 * writes nothing, so the screen asks as an ordinary scope first and, only on that answer, as an alternative.
 */
export async function previewScope(
  gateway: CustomerAcceptanceGateway,
  target: AcceptanceTarget,
): Promise<AcceptancePreview> {
  const base = {
    component_id: target.componentId,
    evidence_id: target.evidenceId,
    issued_line_id: target.issuedLineId,
    expected_case_generation: target.caseGeneration,
    expected_scope_generation: 0,
  };
  try {
    return await gateway.preview({
      revisionId: target.revisionId,
      intent: { ...base, expected_choice_generation: null },
    });
  } catch (error) {
    if (getApiReasonCode(error) !== "PREVIEW_TOKEN_STALE") throw error;
    return gateway.preview({
      revisionId: target.revisionId,
      intent: { ...base, expected_choice_generation: 0 },
    });
  }
}

/** Scope states from the issued read. Wording never names a competing customer, acceptance or quantity. */
export const SCOPE_STATUS_VIEW: Record<
  string,
  { label: string; tone: "success" | "pending" | "danger" | "draft" | "active"; note: string }
> = {
  ACCEPTED: {
    label: "Accepted",
    tone: "success",
    note: "Consent for this scope is recorded on this quotation.",
  },
  AVAILABLE: {
    label: "Available",
    tone: "active",
    note: "This scope can be accepted.",
  },
  CLAIMED: {
    label: "Claimed elsewhere",
    tone: "pending",
    note: "This scope, or an alternative to it, is already covered by an acceptance recorded elsewhere. It cannot be accepted again.",
  },
  UNAVAILABLE: {
    label: "Not available",
    tone: "danger",
    note: "The offer is not open (expired, withdrawn or replaced), so nothing on it can be accepted.",
  },
};
export function scopeStatusView(status: string) {
  return (
    SCOPE_STATUS_VIEW[status] ?? {
      label: "Unknown",
      tone: "draft" as const,
      note: "",
    }
  );
}

export const WARNING_TEXT: Record<string, string> = {
  DELIVERY_NOT_RECORDED:
    "No delivery of the official PDF is recorded for this quotation. That does not stop you recording the acceptance.",
};
export function warningText(code: string): string {
  return WARNING_TEXT[code] ?? "Check this review again before you confirm.";
}

export const MISSING_TEXT: Record<string, string> = {
  SERVICE_JOB_ADAPTER_NOT_DELIVERED:
    "Starting a service job from an acceptance is not available yet.",
  HANDOVER_PATH_UNSUPPORTED:
    "There is no supported way yet to hand a goods acceptance over for fulfilment.",
};
export function missingText(code: string): string {
  return MISSING_TEXT[code] ?? "A step before handover is not available yet.";
}

export const REFUSAL_MESSAGES: Record<string, string> = {
  REQUEST_KEY_REUSED:
    "That request was already used for different details. Review again and confirm.",
  DECISION_CASE_STALE:
    "The customer message changed since you chose it. Choose it again and review.",
  PREVIEW_TOKEN_STALE:
    "This review is out of date because something changed. Review again before confirming.",
  DECISION_INTENT_CONFLICT:
    "This scope already has a recorded acceptance with different details. Open the quotation again.",
  SCOPE_ALREADY_CLAIMED:
    "This scope is already covered by a recorded acceptance and cannot be accepted again.",
  CHOICE_ALREADY_SELECTED:
    "One of these alternatives is already selected, so no other can be accepted.",
  ACCEPTANCE_NOT_ELIGIBLE:
    "This customer message cannot support an acceptance of this scope. It needs a written message, a named sender whose authority was checked, and a link to this scope.",
  QUOTATION_NOT_AVAILABLE:
    "This offer is no longer open (expired, withdrawn or replaced), so it cannot be accepted. A new offer is needed, not a smaller quantity or a changed price.",
  INVALID_INPUT: "Check the details and try again.",
};

export function refusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && REFUSAL_MESSAGES[code]) return REFUSAL_MESSAGES[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do that.";
  if (isApiError(error) && error.status === 404)
    return "This quotation or customer message is not available.";
  if (isApiError(error) && error.status === 422)
    return "Check the details and try again.";
  return "That could not be completed. Try again.";
}

/** A review that must be redone (the token, case or scope moved), as opposed to a final refusal. */
export function needsFreshReview(error: unknown): boolean {
  const code = getApiReasonCode(error);
  return code === "PREVIEW_TOKEN_STALE" || code === "DECISION_CASE_STALE";
}

/**
 * Client-side mirror of the server's consent rule, only to explain a disabled button: the current (latest) observation must be
 * written, name the sender and have a verified authority basis. The server decides.
 */
export function consentProblem(latest: Observation | undefined): string | null {
  if (!latest) return "This customer message has no observation yet.";
  if (latest.source_kind === "VERBAL")
    return "The latest observation is verbal. Only a written message can support an acceptance.";
  if (
    latest.authority_verification_basis !== "DOCUMENT_ON_FILE" &&
    latest.authority_verification_basis !== "CONFIRMED_BY_SELLER"
  )
    return "The sender’s authority has not been verified. Add an observation showing it was checked.";
  if (!latest.customer_representative_snapshot?.trim())
    return "The latest observation does not name who sent it. Add one that does.";
  return null;
}
