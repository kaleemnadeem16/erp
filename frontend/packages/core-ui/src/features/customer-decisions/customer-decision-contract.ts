import { z } from "zod";
import { getApiReasonCode, isApiError } from "../../lib/api-errors";

/**
 * Contract for the customer decision routes (generated client: `customerDecisionCases*`):
 *   GET  /v1/customer-decision-cases                    the cases, newest first
 *   POST /v1/customer-decision-cases                    open a case (Idempotency-Key)
 *   GET  /v1/customer-decision-cases/{case}             a case with its components and observation chain
 *   POST /v1/customer-decision-cases/{case}/evidence    add an observation (Idempotency-Key, expected_generation)
 * Everything here is an observation of what a customer sent. Nothing is consent, acceptance or a claim on a quotation: the
 * detail carries `consent_recorded: false` and no amount of any kind. Every response is parsed (and stripped to this shape) before a screen sees it.
 */
const id = z.number().int().positive();

export const SOURCE_KINDS = [
  "PURCHASE_ORDER",
  "SIGNATURE",
  "EMAIL",
  "VERBAL",
  "OTHER_WRITTEN",
] as const;
export type SourceKind = (typeof SOURCE_KINDS)[number];
export const SOURCE_LABELS: Record<SourceKind, string> = {
  PURCHASE_ORDER: "Purchase order",
  SIGNATURE: "Signature",
  EMAIL: "Email",
  VERBAL: "Verbal (spoken)",
  OTHER_WRITTEN: "Other written message",
};
export const AUTHORITY_BASES = [
  "UNVERIFIED",
  "STATED_BY_CUSTOMER",
  "DOCUMENT_ON_FILE",
  "CONFIRMED_BY_SELLER",
] as const;
export type AuthorityBasis = (typeof AUTHORITY_BASES)[number];
export const AUTHORITY_LABELS: Record<AuthorityBasis, string> = {
  UNVERIFIED: "Not checked",
  STATED_BY_CUSTOMER: "The sender said they may decide",
  DOCUMENT_ON_FILE: "A document on file shows their authority",
  CONFIRMED_BY_SELLER: "Confirmed by us with the customer",
};
export const PRECISIONS = ["UNKNOWN", "DATE", "INSTANT", "INTERVAL"] as const;
export type Precision = (typeof PRECISIONS)[number];
export const PRECISION_LABELS: Record<Precision, string> = {
  UNKNOWN: "Not known",
  DATE: "A day",
  INSTANT: "A date and time",
  INTERVAL: "From one time to another",
};

export const summarySchema = z.object({
  id,
  party_id: id,
  party_name: z.string().nullable(),
  intake_identity: z.string(),
  generation: z.number().int().nonnegative(),
  component_count: z.number().int().nonnegative().nullable(),
  observation_count: z.number().int().nonnegative(),
  recorded_by: id,
  recorded_at: z.string().nullable(),
});
const pageLink = z.string().nullable();
export const listPageSchema = z.object({
  data: z.array(summarySchema),
  links: z.object({
    first: pageLink,
    last: pageLink,
    prev: pageLink,
    next: pageLink,
  }),
  meta: z.object({
    current_page: z.number().int().min(1),
    last_page: z.number().int().min(1),
    per_page: z.number().int().min(0),
    total: z.number().int().min(0),
    from: z.number().int().nullable(),
    to: z.number().int().nullable(),
  }),
});
export type CaseSummary = z.infer<typeof summarySchema>;
export type CaseListPage = z.infer<typeof listPageSchema>;

export const observationSchema = z.object({
  id,
  predecessor_id: id.nullable(),
  /** False once a later observation corrects this one. */
  is_current: z.boolean(),
  source_kind: z.string(),
  external_reference: z.string().nullable(),
  source_version: z.string().nullable(),
  customer_representative_snapshot: z.string().nullable(),
  authority_verification_basis: z.string(),
  occurrence_precision: z.string(),
  occurred_at: z.string().nullable(),
  occurred_until: z.string().nullable(),
  source_timezone: z.string().nullable(),
  factual_description: z.string(),
  observation_state: z.string(),
  recorded_by: id,
  recorded_by_name: z.string().nullable(),
  recorded_at: z.string(),
});
export const componentSchema = z.object({
  id,
  intent_identity: z.string(),
  family_id: id,
  original_scope_anchor_id: id,
  recorded_at: z.string(),
});
export const caseDetailSchema = z.object({
  case: z.object({
    id,
    party_id: id,
    party_name: z.string().nullable(),
    intake_identity: z.string(),
    generation: z.number().int().nonnegative(),
    recorded_by: id,
    recorded_by_name: z.string().nullable(),
    recorded_at: z.string(),
    consent_recorded: z.boolean(),
  }),
  components: z.array(componentSchema),
  observations: z.array(observationSchema),
});
export type Observation = z.infer<typeof observationSchema>;
export type CaseComponent = z.infer<typeof componentSchema>;
export type CaseDetail = z.infer<typeof caseDetailSchema>;

export const outcomeSchema = z.object({
  case_id: id,
  generation: z.number().int().nonnegative(),
  evidence_id: id.nullable(),
  replayed: z.boolean(),
});
export type WriteOutcome = z.infer<typeof outcomeSchema>;

export const readListPage = (value: unknown) => listPageSchema.parse(value);
export const readCaseDetail = (value: unknown) => caseDetailSchema.parse(value);
export const readOutcome = (value: unknown) => outcomeSchema.parse(value);

export interface CaseListParams {
  search?: string;
  /** Only this customer's cases (the endpoint's `filter[party_id]`). */
  partyId?: number;
  /** Opened on or after / on or before this day of the company calendar (`yyyy-mm-dd`, the endpoint's `filter[recorded_from]` / `filter[recorded_to]`). */
  recordedFrom?: string;
  recordedTo?: string;
  /** Only cases linked to this quotation family (`filter[quotation_family_id]`); the server answers an unseen quotation like an unknown one. */
  quotationFamilyId?: number;
  sort: "id" | "-id";
  page: number;
  perPage: number;
}

/** One quotation scope a case is about. The anchor is an ORIGINAL scope of the quotation family (the server refuses a later one). */
export interface CaseComponentInput {
  intent_identity: string;
  family_id: number;
  original_scope_anchor_id: number;
}

export interface OpenCaseInput {
  party_id: number;
  intake_identity: string;
  /** Optional: the issued quotation scopes this message is about. Recording a message holds nothing on them. */
  components?: CaseComponentInput[];
}

/** An issued quotation of one customer that a message can be linked to. */
export interface LinkableQuotation {
  revision_id: number;
  family_id: number;
  official_number: string | null;
  availability: string;
}
/** The scopes of one issued quotation, as the issued read lists them (null when the caller may not see them). */
export interface LinkableScopes {
  revision_id: number;
  family_id: number;
  official_number: string;
  scopes: { issued_line_id: number; logical_scope_id: number }[] | null;
}

export interface ObservationInput {
  source_kind: SourceKind;
  authority_verification_basis: AuthorityBasis;
  occurrence_precision: Precision;
  factual_description: string;
  external_reference?: string | null;
  customer_representative_snapshot?: string | null;
  occurred_at?: string | null;
  occurred_until?: string | null;
  /** The earlier observation this one corrects, if any. */
  predecessor_id?: number | null;
}

export interface CustomerDecisionGateway {
  list: (params: CaseListParams) => Promise<CaseListPage>;
  detail: (caseId: number) => Promise<CaseDetail>;
  open: (input: {
    body: OpenCaseInput;
    requestKey: string;
  }) => Promise<WriteOutcome>;
  /** Issued quotations (needs the quotation view duty; refused otherwise): one customer's, or, without a customer, the most recent ones. */
  quotationsFor: (partyId?: number) => Promise<LinkableQuotation[]>;
  /** The scopes of one issued quotation. */
  quotationScopes: (revisionId: number) => Promise<LinkableScopes>;
  addObservation: (input: {
    caseId: number;
    expectedGeneration: number;
    body: ObservationInput;
    requestKey: string;
  }) => Promise<WriteOutcome>;
}

/** Fixed wording for the refusals a person can act on. Never the server's text, never another case's content. */
export const REFUSAL_MESSAGES: Record<string, string> = {
  REQUEST_KEY_REUSED:
    "That request was already used for different details. Check the details and try again.",
  DECISION_CASE_STALE:
    "This case changed since you opened it. Reload it, then add your observation again.",
  INTAKE_IDENTITY_EXISTS:
    "A case with that reference already exists. Open it instead; cases are never merged automatically.",
  EVIDENCE_PREDECESSOR_INVALID:
    "That observation can no longer be corrected, because a later one already corrects it. Reload the case.",
  INVALID_INPUT:
    "Check the details and try again. A linked quotation scope must be an original scope of that quotation.",
};

export function refusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && REFUSAL_MESSAGES[code]) return REFUSAL_MESSAGES[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do that.";
  if (isApiError(error) && error.status === 404)
    return "This customer message is not available.";
  if (isApiError(error) && error.status === 422)
    return "Check the details and try again.";
  return "That could not be completed. Try again.";
}

export function isStale(error: unknown): boolean {
  return getApiReasonCode(error) === "DECISION_CASE_STALE";
}
