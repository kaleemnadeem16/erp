import { z } from "zod";

const id = z.number().int().positive();
const generation = z.number().int().nonnegative();
const identity = z.object({ id, name: z.string(), status: z.string() });
const ownership = z.object({
  id,
  generation: id,
  kind: z.enum(["INITIAL", "CAPTURED_BASELINE", "REASSIGNED", "UNIT_CHANGED"]),
  old_owner_id: id.nullable(),
  new_owner_id: id,
  old_unit_id: id.nullable(),
  new_unit_id: id.nullable(),
  old_owner_name: z.string().nullable().optional(),
  new_owner_name: z.string().nullable().optional(),
  actor_name: z.string().nullable().optional(),
  old_unit_name: z.string().nullable().optional(),
  new_unit_name: z.string().nullable().optional(),
  actor_id: id,
  recorded_at: z.string(),
});
export const familyShareSchema = z.object({
  id,
  granted_generation: id,
  recipient_id: id,
  recipient_name: z.string(),
  issuer_id: id,
  issuer_name: z.string().optional(),
  revoked_by_name: z.string().nullable().optional(),
  level: z.enum(["VIEW", "PREPARE"]),
  starts_at: z.string(),
  ends_at: z.string().nullable(),
  recorded_at: z.string(),
  revoked_by: id.nullable(),
  revoked_at: z.string().nullable(),
  status: z.enum(["ACTIVE", "SCHEDULED", "EXPIRED", "REVOKED", "INVALIDATED"]),
});
export const familyAccessSchema = z.object({
  family_id: id,
  record_scope: z.enum(["ALL", "OWN", "MY_UNITS", "DELEGATED"]),
  created_by: id,
  created_by_name: z.string().nullable(),
  responsible_owner: identity.nullable(),
  access_unit: identity.nullable(),
  baseline_state: z.enum(["NATIVE", "CAPTURED", "UNRESOLVED"]),
  ownership_generation: generation,
  access_generation: generation,
  allowed_actions: z.object({
    prepare: z.boolean(),
    assign: z.boolean(),
    delegate_view: z.boolean(),
    delegate_prepare: z.boolean(),
    revoke: z.boolean(),
  }),
  ownership_history: z.array(ownership).optional(),
  ownership_history_has_more: z.boolean().optional(),
  ownership_history_next_generation: id.nullable().optional(),
  shares: z.array(familyShareSchema).optional(),
  shares_has_more: z.boolean().optional(),
  shares_next_generation: id.nullable().optional(),
});
export const familyAccessOutcomeSchema = z.object({
  family_id: id,
  ownership_generation: generation,
  access_generation: generation,
  changed: z.boolean(),
  replayed: z.boolean(),
});
export const accessCandidateSchema = z.object({ id, name: z.string() });
export const accessCandidatePageSchema = z.object({
  data: z.array(accessCandidateSchema),
  meta: z.object({
    next_cursor: z.string().nullable(),
    prev_cursor: z.string().nullable(),
  }),
});
export type FamilyAccess = z.infer<typeof familyAccessSchema>;
export type FamilyShare = z.infer<typeof familyShareSchema>;
export type AccessCandidate = z.infer<typeof accessCandidateSchema>;
export type AccessCandidatePage = z.infer<typeof accessCandidatePageSchema>;
export type AccessOutcome = z.infer<typeof familyAccessOutcomeSchema>;
export type AccessHistoryQuery = {
  ownership_before?: number;
  shares_before?: number;
  history_per_page?: number;
};
export type AccessCandidateQuery = {
  kind: "owner" | "recipient" | "unit";
  level?: "VIEW" | "PREPARE";
  search: string;
  cursor?: string | null;
};
type Common = {
  reason: string;
  expected_ownership_generation: number;
  expected_access_generation: number;
};
export type AccessIntent = Common &
  (
    | { operation: "owner"; owner_id: number }
    | { operation: "unit"; access_unit_id: number | null }
    | {
        operation: "grant";
        recipient_id: number;
        level: "VIEW" | "PREPARE";
        starts_at: string | null;
        ends_at: string | null;
      }
    | { operation: "revoke"; grant_id: number }
  );
export interface FamilyAccessGateway {
  read(familyId: number, query?: AccessHistoryQuery): Promise<FamilyAccess>;
  candidates(
    familyId: number,
    query: AccessCandidateQuery,
  ): Promise<AccessCandidatePage>;
  write(
    familyId: number,
    intent: AccessIntent,
    key: string,
  ): Promise<AccessOutcome>;
}
export const ACCESS_REFUSALS: Readonly<Record<string, string>> = {
  QUOTATION_OWNERSHIP_STALE:
    "Responsibility or access has changed. Refresh and review the current details before trying again.",
  QUOTATION_OWNERSHIP_UNRESOLVED:
    "This quotation needs an approved ownership baseline before access can be changed.",
  QUOTATION_SHARE_RECIPIENT_INELIGIBLE:
    "This person needs the appropriate quotation permission and a compatible record scope before they can receive access.",
  QUOTATION_SHARE_OVERLAP:
    "An effective share of this level overlaps that period. Revoke it before creating a replacement.",
  ACCESS_UNIT_NOT_FOUND: "Choose an active unit in this company.",
  REQUEST_KEY_REUSED:
    "This attempt changed after it was sent. Refresh and review before starting a new attempt.",
};

const accessIntentCommon = z.object({
  reason: z.string().trim().min(1).max(1000),
  expected_ownership_generation: generation,
  expected_access_generation: generation,
});
export const accessIntentSchema = z.discriminatedUnion("operation", [
  accessIntentCommon.extend({ operation: z.literal("owner"), owner_id: id }),
  accessIntentCommon.extend({
    operation: z.literal("unit"),
    access_unit_id: id.nullable(),
  }),
  accessIntentCommon.extend({
    operation: z.literal("grant"),
    recipient_id: id,
    level: z.enum(["VIEW", "PREPARE"]),
    starts_at: z.string().datetime({ offset: true }).nullable(),
    ends_at: z.string().datetime({ offset: true }).nullable(),
  }),
  accessIntentCommon.extend({ operation: z.literal("revoke"), grant_id: id }),
]);
