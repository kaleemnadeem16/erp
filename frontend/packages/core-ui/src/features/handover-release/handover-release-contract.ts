import { z } from "zod";
import { getApiReasonCode, isApiError } from "../../lib/api-errors";
import type { Observation } from "../customer-decisions/customer-decision-contract";

/**
 * Contract for the handover and release screen (generated client: handoverPlansShow, customerAcceptancesShow,
 * customerDecisionCasesShow, customerAcceptancesCompletionBasesStore, handoverStagesReleasePreview, handoverStagesRelease).
 * Every response is parsed (and stripped to this shape) before a screen sees it. The screen never decides a business rule: readiness,
 * evidence eligibility, scope and the single-Job guarantee are the server's; this file only names and explains what the server says.
 */
const id = z.number().int().positive();

export const planSchema = z.object({
  id,
  acceptance_id: id,
  stage_id: id,
  recorded_at: z.string(),
  state: z.string(),
  release_performed: z.boolean(),
  operational: z.object({
    deliverable_identity: z.string(),
    description: z.string(),
    location_kind: z.string(),
    instructions: z.string(),
    contact: z
      .object({
        id,
        name: z.string(),
        email: z.string().nullable(),
        phone: z.string().nullable(),
      })
      .nullable(),
    site: z
      .object({
        id,
        name: z.string(),
        address_line1: z.string().nullable(),
        city: z.string().nullable(),
        country_code: z.string().nullable(),
      })
      .nullable(),
  }),
});
export const releasePreviewSchema = z.object({
  ready: z.boolean(),
  blockers: z.array(z.string()),
  next_actions: z.array(
    z.object({ fact: z.string(), owner: z.string(), action: z.string() }),
  ),
  cost: z.object({ required: z.boolean(), status: z.string() }),
  applicability: z.object({
    status: z.string(),
    materials: z.string(),
    subcontract: z.string(),
  }),
  basis_id: id.nullable(),
  basis_version: id.nullable(),
  fingerprint: z.string().min(1),
});
export const basisOutcomeSchema = z.object({
  basis_id: id,
  version: id,
  replayed: z.boolean(),
});
export const releaseOutcomeSchema = z.object({
  release_event_id: id,
  job_id: id,
  job_code: z.string().min(1),
  replayed: z.boolean(),
});
export type HandoverPlan = z.infer<typeof planSchema>;
export type ReleasePreview = z.infer<typeof releasePreviewSchema>;
export type BasisOutcome = z.infer<typeof basisOutcomeSchema>;
export type ReleaseOutcome = z.infer<typeof releaseOutcomeSchema>;

export const readPlan = (value: unknown) => planSchema.parse(value);
export const readReleasePreview = (value: unknown) =>
  releasePreviewSchema.parse(value);
export const readBasisOutcome = (value: unknown) =>
  basisOutcomeSchema.parse(value);
export const readReleaseOutcome = (value: unknown) =>
  releaseOutcomeSchema.parse(value);

/** What the screen needs about the acceptance behind the plan: the issued description and the consent evidence. */
export interface AcceptanceFacts {
  caseId: number;
  consentEvidenceId: number;
  issuedDescription: string;
}

/** The existing customer messages (observations) of the accepting case, with the acceptance's own consent one marked. */
export interface EvidenceChoice {
  observation: Observation;
  isConsent: boolean;
}

export interface BasisRecordInput {
  acceptanceId: number;
  /** null = the issued line description on the acceptance's own consent evidence; otherwise the exact text of the chosen written message. */
  agreedText: string | null;
  evidenceId: number;
  requestKey: string;
}

export interface ReleaseInput {
  stageId: number;
  basisId: number;
  fingerprint: string;
  requestKey: string;
}

export interface HandoverReleaseGateway {
  plan: (planId: number) => Promise<HandoverPlan>;
  acceptance: (acceptanceId: number) => Promise<AcceptanceFacts>;
  evidence: (caseId: number) => Promise<Observation[]>;
  preview: (stageId: number) => Promise<ReleasePreview>;
  recordBasis: (input: BasisRecordInput) => Promise<BasisOutcome>;
  release: (input: ReleaseInput) => Promise<ReleaseOutcome>;
}

/** Blocker codes the server reports for a release, in words. Unknown codes fall back to the server's own next-action text. */
export const BLOCKER_TEXT: Record<string, string> = {
  COMPLETION_BASIS_MISSING:
    "The customer-agreed completion conditions have not been recorded.",
  SERVICE_SOURCE_NOT_AVAILABLE:
    "The accepted service is no longer available as a source for release.",
  SERVICE_LOCATION_REQUIRED: "Where the service is delivered is not recorded.",
  SERVICE_CONTACT_REQUIRED: "A delivery contact is not recorded.",
  SERVICE_SITE_REQUIRED: "A delivery site is not recorded.",
  SERVICE_COST_EVIDENCE_REQUIRED:
    "A mandatory commercial control needs cost evidence that is not available.",
  SOURCE_OBLIGATION_UNSUPPORTED:
    "The agreed scope says materials or subcontractors apply. This first release does not support them.",
  SOURCE_APPLICABILITY_UNPROVEN:
    "It is not yet proven that no materials or subcontractors are involved. Missing evidence is not proof of none.",
};
export function blockerText(code: string): string {
  return BLOCKER_TEXT[code] ?? "A release requirement is not met yet.";
}

export const SCOPE_LABELS: Record<string, string> = {
  EXCLUDED: "Excluded by the customer’s agreement",
  REQUIRED: "Required by the customer’s agreement",
  UNSTATED: "Not stated in the agreement",
};
export const APPLICABILITY_LABELS: Record<string, string> = {
  NOT_APPLICABLE: "Proven not to apply",
  APPLICABLE: "Applies",
  UNKNOWN: "Not proven",
};
export const LOCATION_LABELS: Record<string, string> = {
  REMOTE: "Remote",
  ON_SITE: "On site",
  UNKNOWN: "Not recorded",
};

export const REFUSAL_MESSAGES: Record<string, string> = {
  REQUEST_KEY_REUSED:
    "That request was already used for different details. Review again and try once more.",
  REQUEST_KEY_REQUIRED: "That could not be completed. Try again.",
  PREVIEW_TOKEN_STALE:
    "What you reviewed has changed. Review the release again before you release.",
  HANDOVER_NOT_READY:
    "The service is not ready to release. Review the blockers below.",
  COMPLETION_BASIS_MISSING:
    "Record the customer-agreed completion conditions first.",
  RELEASE_ALREADY_RECORDED:
    "This service has already been released. A service creates exactly one Job and cannot create a second.",
  RELEASE_RECEIVER_UNAVAILABLE:
    "Releasing is not available right now: nothing can receive the released service.",
  INVALID_INPUT:
    "The message does not qualify. It must be written, from a sender whose authority was checked, recorded after the acceptance, and be exactly the agreed conditions.",
};

export function refusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && REFUSAL_MESSAGES[code]) return REFUSAL_MESSAGES[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do that. Releasing needs the Sales release permission and, separately, permission to receive jobs.";
  if (isApiError(error) && error.status === 404)
    return "This handover is not available.";
  if (isApiError(error) && error.status === 422)
    return "Check the details and try again.";
  return "That could not be completed. Try again.";
}

/** The review must be redone (a basis or fact moved) rather than the same request retried. */
export function needsFreshReview(error: unknown): boolean {
  return getApiReasonCode(error) === "PREVIEW_TOKEN_STALE";
}
export function alreadyReleased(error: unknown): boolean {
  return getApiReasonCode(error) === "RELEASE_ALREADY_RECORDED";
}

/**
 * Client-side mirror of the server's evidence rule, only to explain why a message cannot be chosen: written, sender authority
 * verified, and not the acceptance's own consent message when it carries new conditions. The server decides.
 */
export function evidenceProblem(o: Observation): string | null {
  if (!o.is_current) return "A later message corrected this one.";
  if (o.source_kind === "VERBAL")
    return "Verbal messages cannot be used. Only a written message can.";
  if (
    o.authority_verification_basis !== "DOCUMENT_ON_FILE" &&
    o.authority_verification_basis !== "CONFIRMED_BY_SELLER"
  )
    return "The sender’s authority has not been verified.";
  return null;
}
