import { z } from "zod";

/**
 * CONTRACT for the quotation approval routes (generated client: quotationsApprovalShow, quotationsApprovalStore,
 * quotationsApprovalDecide, quotationsApprovalWithdraw; the live gateway is in quotation-live-gateways.ts).
 * These schemas parse and strip every response; the screens depend only on them.
 *
 * Server facts mirrored (ADR 0158):
 *  - read: for a preparer (prepare) or a decider (approve), each with the independent view. `state` is the approval for the
 *    draft EXACTLY as it is now; a request given for earlier content shows as `superseded`. `can` says what THIS person may do.
 *  - request / withdraw need prepare; decide needs approve. All take expected_generation and an Idempotency-Key.
 *  - the preparer, the requester and anyone who entered a concession can never decide; the server enforces it, `can.decide`
 *    only says whether to offer the buttons.
 *  - no amount, limit, authority ceiling or cost is ever sent.
 */
const id = z.number().int().positive();
const person = z.object({ id, name: z.string() }).nullable();

export const APPROVAL_STATES = [
  "NOT_REQUESTED",
  "PENDING",
  "APPROVED",
  "REJECTED",
] as const;

export const approvalDecisionSchema = z.object({
  decision: z.enum(["APPROVED", "REJECTED"]),
  reason: z.string().nullable(),
  decided_at: z.string(),
  decided_by: person,
});
export const approvalRequestSchema = z.object({
  id,
  status: z.string(),
  requested_at: z.string(),
  requested_by: person,
  /** When the authority evidence for this request lapses (null when none applies); `expired` is decided by the server. */
  expires_at: z.string().nullable().optional(),
  expired: z.boolean().optional(),
  closed_at: z.string().nullable(),
  closing_reason: z.string().nullable(),
  concessions: z.string().nullable(),
  decisions: z.array(approvalDecisionSchema),
});
export const quotationApprovalSchema = z.object({
  revision_id: id,
  input_generation: z.number().int().nonnegative(),
  is_draft: z.boolean(),
  approval_needed: z.boolean(),
  state: z.enum(APPROVAL_STATES),
  superseded: z.boolean(),
  request: approvalRequestSchema.nullable(),
  can: z.object({
    request: z.boolean(),
    withdraw: z.boolean(),
    decide: z.boolean(),
  }),
});
export const approvalOutcomeSchema = z.object({
  family_id: id,
  revision_id: id,
  approval_request_id: id,
  status: z.string(),
  input_generation: z.number().int().nonnegative(),
  replayed: z.boolean(),
});
export type QuotationApproval = z.infer<typeof quotationApprovalSchema>;
export type ApprovalOutcome = z.infer<typeof approvalOutcomeSchema>;
export type ApprovalDecisionKind = "APPROVED" | "REJECTED";

export const readQuotationApproval = (value: unknown) =>
  quotationApprovalSchema.parse(value);
export const readApprovalOutcome = (value: unknown) =>
  approvalOutcomeSchema.parse(value);

export interface QuotationApprovalGateway {
  read(revisionId: number): Promise<QuotationApproval>;
  /** `requestKey` is sent as the Idempotency-Key; the same key must be reused on retry of the same intent. */
  request(input: {
    revisionId: number;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<ApprovalOutcome>;
  decide(input: {
    revisionId: number;
    expectedGeneration: number;
    decision: ApprovalDecisionKind;
    reason: string | null;
    requestKey: string;
  }): Promise<ApprovalOutcome>;
  withdraw(input: {
    revisionId: number;
    expectedGeneration: number;
    reason: string;
    requestKey: string;
  }): Promise<ApprovalOutcome>;
}

/** Plain wording and tone for where the draft stands. */
export const APPROVAL_STATE_VIEW: Record<
  QuotationApproval["state"],
  {
    label: string;
    tone: "draft" | "pending" | "success" | "danger";
    note: string;
  }
> = {
  NOT_REQUESTED: {
    label: "No approval requested",
    tone: "draft",
    note: "A discount or price override on this draft has to be approved before the quotation can be issued.",
  },
  PENDING: {
    label: "Waiting for approval",
    tone: "pending",
    note: "Someone who is allowed to approve discounts has been asked. Changing the draft cancels this request.",
  },
  APPROVED: {
    label: "Approved",
    tone: "success",
    note: "This draft was approved exactly as it is now. Changing it will need a new approval.",
  },
  REJECTED: {
    label: "Rejected",
    tone: "danger",
    note: "The approver rejected this draft. Change the discount or override before asking again.",
  },
};

export const CONCESSION_LABEL: Record<string, string> = {
  DISCOUNT: "a discount",
  OVERRIDE: "a final price override",
  "DISCOUNT+OVERRIDE": "a discount and a final price override",
};

/** Wording for a refused approval request, decision or withdrawal; only the stable code decides the text. */
export const APPROVAL_REFUSALS: Record<string, string> = {
  QUOTATION_DRAFT_STALE:
    "The draft changed since you looked at it. Reload it and check again.",
  APPROVAL_STALE:
    "The draft changed after approval was requested, so that request no longer counts. It has to be requested again.",
  APPROVAL_NOT_PENDING: "No approval is waiting on this draft any more.",
  APPROVAL_NOT_NEEDED:
    "This draft has no discount or price override that needs approval.",
  APPROVAL_NOT_CONFIGURED:
    "Approval of discounts is not set up for this company yet. Ask an administrator.",
  APPROVAL_ALREADY_GRANTED: "This draft is already approved as it stands.",
  APPROVAL_REJECTED:
    "The approver rejected this draft as it stands. Change it before asking again.",
  APPROVAL_SELF_APPROVAL:
    "The person who prepared or requested this cannot decide it.",
  APPROVAL_NOT_ELIGIBLE: "You are not allowed to do this for this approval.",
  REASON_REQUIRED: "Give a reason.",
  QUOTATION_NOT_EDITABLE: "Only a draft quotation has an approval.",
  REQUEST_KEY_REUSED:
    "This request was already used for a different action. Start again.",
  REQUEST_KEY_REQUIRED: "The request could not be identified. Try again.",
};

/** MOCK DATA: tests and the unregistered preview only. */
export function mockApproval(
  overrides: Partial<QuotationApproval> = {},
): QuotationApproval {
  return {
    revision_id: 12,
    input_generation: 6,
    is_draft: true,
    approval_needed: true,
    state: "NOT_REQUESTED",
    superseded: false,
    request: null,
    can: { request: true, withdraw: false, decide: false },
    ...overrides,
  };
}
export function mockApprovalRequest(
  overrides: Partial<NonNullable<QuotationApproval["request"]>> = {},
): NonNullable<QuotationApproval["request"]> {
  return {
    id: 31,
    status: "PENDING",
    requested_at: "2026-11-05T09:00:00Z",
    requested_by: { id: 5, name: "Amina Khan" },
    closed_at: null,
    closing_reason: null,
    concessions: "DISCOUNT",
    decisions: [],
    ...overrides,
  };
}
