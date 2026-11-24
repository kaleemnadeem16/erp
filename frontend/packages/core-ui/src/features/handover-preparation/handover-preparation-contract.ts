import { z } from "zod";
import { getApiReasonCode, isApiError } from "../../lib/api-errors";

/**
 * Contract for preparing a service handover (generated client: customerAcceptancesHandoverOptions, customerAcceptancesHandoverPreview,
 * customerAcceptancesHandoverPlansStore). Every response is parsed before a screen sees it. The screen never decides readiness: what is
 * missing, what the system accepts and whether preparation may proceed are the server's; this file only names and explains them.
 */
const id = z.number().int().positive();

export const contactOptionSchema = z.object({
  id,
  name: z.string(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
});
export const siteOptionSchema = z.object({
  id,
  name: z.string(),
  address_line1: z.string().nullable(),
  city: z.string().nullable(),
  country_code: z.string().nullable(),
});
export const optionsSchema = z.object({
  acceptance_id: id,
  contacts: z.array(contactOptionSchema),
  sites: z.array(siteOptionSchema),
});
export const preparationPreviewSchema = z.object({
  acceptance_id: id,
  inputs: z.object({
    path: z.string(),
    location_kind: z.string(),
    contact_id: id.nullable(),
    site_id: id.nullable(),
    instructions: z.string(),
  }),
  operational: z.object({
    description: z.string().optional(),
    location_kind: z.string().optional(),
    instructions: z.string().optional(),
    contact: contactOptionSchema.nullable().optional(),
    site: siteOptionSchema.nullable().optional(),
  }),
  readiness: z.object({
    status: z.string(),
    preparation_ready: z.boolean(),
    missing: z.array(z.string()),
    next_actions: z.array(
      z.object({ fact: z.string(), owner: z.string(), action: z.string() }),
    ),
  }),
  preview_token: z.string().min(1),
  expires_in_seconds: z.number().int().nonnegative(),
});
export const preparedSchema = z.object({
  plan_id: id,
  case_id: id,
  replayed: z.boolean(),
});

export type ContactOption = z.infer<typeof contactOptionSchema>;
export type SiteOption = z.infer<typeof siteOptionSchema>;
export type PreparationOptions = z.infer<typeof optionsSchema>;
export type PreparationPreview = z.infer<typeof preparationPreviewSchema>;
export type Prepared = z.infer<typeof preparedSchema>;

export const readOptions = (value: unknown) => optionsSchema.parse(value);
export const readPreparationPreview = (value: unknown) =>
  preparationPreviewSchema.parse(value);
export const readPrepared = (value: unknown) => preparedSchema.parse(value);

/** What the person chose. Only operational facts: never a price, a condition, a quantity or a permission. */
export interface PreparationInputs {
  /** UNKNOWN until the person chooses; the server then reports it as missing. */
  locationKind: "UNKNOWN" | "REMOTE" | "ON_SITE";
  contactId: number | null;
  /** Only meaningful (and only sent) for on-site service. */
  siteId: number | null;
  instructions: string;
}

export interface PrepareInput {
  acceptanceId: number;
  inputs: PreparationInputs;
  previewToken: string;
  requestKey: string;
}

export interface HandoverPreparationGateway {
  options: (acceptanceId: number) => Promise<PreparationOptions>;
  preview: (
    acceptanceId: number,
    inputs: PreparationInputs,
  ) => Promise<PreparationPreview>;
  prepare: (input: PrepareInput) => Promise<Prepared>;
}

/** The wire form of the inputs (the same for the review and the preparation, so the token matches exactly what was reviewed). */
export function wireInputs(inputs: PreparationInputs) {
  return {
    path: "SERVICE_JOB",
    location_kind: inputs.locationKind,
    ...(inputs.contactId !== null ? { contact_id: inputs.contactId } : {}),
    ...(inputs.locationKind === "ON_SITE" && inputs.siteId !== null
      ? { site_id: inputs.siteId }
      : {}),
    instructions: inputs.instructions,
  };
}

/** The server's missing-information codes, in words. Unknown codes fall back to the server's own next-action text. */
export const MISSING_TEXT: Record<string, string> = {
  SERVICE_LOCATION_REQUIRED: "Choose whether the service is remote or on site.",
  SERVICE_CONTACT_REQUIRED:
    "Choose a current contact of this customer who has an email address or telephone.",
  SERVICE_SITE_REQUIRED:
    "Choose a current, active site that belongs to this customer.",
  SERVICE_SOURCE_NOT_AVAILABLE:
    "The accepted service is no longer available as a source for a handover.",
  HANDOVER_PATH_UNSUPPORTED: "Handover is not supported for this kind of item.",
};
export function missingText(code: string): string {
  return MISSING_TEXT[code] ?? "Something needed for the handover is missing.";
}

export const REFUSAL_MESSAGES: Record<string, string> = {
  PREVIEW_TOKEN_STALE:
    "What you reviewed has changed. Review the handover again before you prepare it.",
  REQUEST_KEY_REUSED:
    "That request was already used for different details. Review again and try once more.",
  HANDOVER_NOT_READY:
    "The handover is not ready to prepare. Review the missing information.",
  HANDOVER_PATH_UNSUPPORTED: "Handover is not supported for this kind of item.",
  SCOPE_ALREADY_CLAIMED:
    "A handover already exists for this accepted service. Open it instead.",
};

export function refusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && REFUSAL_MESSAGES[code]) return REFUSAL_MESSAGES[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do that. Preparing a handover needs the Sales handover preparation permission.";
  if (isApiError(error) && error.status === 404)
    return "This accepted service is not available.";
  if (isApiError(error) && error.status === 422)
    return "Check the details and try again.";
  return "That could not be completed. Try again.";
}

/** The review must be redone rather than the same request retried. */
export function needsFreshReview(error: unknown): boolean {
  return getApiReasonCode(error) === "PREVIEW_TOKEN_STALE";
}
/** A different plan already holds this accepted service: open it, never prepare a second. */
export function alreadyPrepared(error: unknown): boolean {
  return getApiReasonCode(error) === "SCOPE_ALREADY_CLAIMED";
}
