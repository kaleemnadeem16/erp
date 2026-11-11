import { z } from "zod";

/**
 * CONTRACT for the discount authority screens (generated clients: discount-authority and quotation-discount-authority;
 * the live gateway is discount-authority-gateway.ts). Every response is parsed and stripped here; screens depend only on this.
 *
 * Server facts mirrored (ADR 0163/0164, docs/domain/quotation-discount-authority-api.md):
 *  - configuration is company-wide and needs the financial configuration duty; every write carries an Idempotency-Key,
 *    `expected_generation` (from a configuration read) and a reason; a stale generation is 422 AUTHORITY_STALE.
 *  - the company mode is OFF unless the owner has changed it; OFF is the default and these screens never change it.
 *  - protected evidence needs its own duty plus quotation view and family scope.
 */
const id = z.number().int().positive();
const money = z.string().nullable();
// Rows come straight from SQL columns: a boolean may arrive as 0/1.
const flag = z.union([z.boolean(), z.number()]).transform((v) => Boolean(v));

export const MODES = ["OFF", "SHADOW", "ENFORCE"] as const;
export type AuthorityMode = (typeof MODES)[number];

export const bandSchema = z.object({
  id,
  policy_id: id,
  rank: z.number().int(),
  label: z.string(),
  exception_only: flag,
  line_percent: money,
  line_base_amount: money,
  document_percent: money,
  document_base_amount: money,
  input_percent: money,
  input_base_amount: money,
});
export const policySchema = z.object({
  id,
  code: z.string(),
  version_no: z.number().int(),
  priority: z.number().int(),
  base_currency_id: id.nullable(),
  allow_higher_approver: flag,
  operation: z.enum(["ANY", "DISCOUNT", "OVERRIDE"]),
  customer_id: id.nullable(),
  unit_id: id.nullable(),
  contains_item_id: id.nullable(),
  enforcement: z.enum(["LINE", "DOCUMENT", "BOTH"]),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
  expiry_calendar_days: z.number().int(),
  created_by: id.nullable(),
  reason: z.string().nullable(),
});
export const assignmentSchema = z.object({
  id,
  user_id: id,
  band_id: id,
  purpose: z.enum(["ROUTINE", "APPROVE", "BOTH"]),
  role_id: id.nullable(),
  unit_id: id.nullable(),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
  assigned_by: id.nullable(),
  reason: z.string().nullable(),
});
export const policyActivationSchema = z.object({
  id,
  policy_id: id,
  actor_id: id.nullable(),
  reason: z.string().nullable(),
  recorded_at: z.string(),
});
export const revocationSchema = z.object({
  id,
  assignment_id: id,
  actor_id: id.nullable(),
  reason: z.string().nullable(),
  recorded_at: z.string(),
});
const page = <T extends z.ZodTypeAny>(record: T) =>
  z.object({
    generation: z.number().int().nonnegative(),
    mode: z.enum(MODES),
    page: z.number().int(),
    per_page: z.number().int(),
    total: z.number().int(),
    records: z.array(record),
  });

export type DiscountBand = z.infer<typeof bandSchema>;
export type DiscountPolicy = z.infer<typeof policySchema>;
export type DiscountAssignment = z.infer<typeof assignmentSchema>;
export type PolicyActivation = z.infer<typeof policyActivationSchema>;
export type AssignmentRevocation = z.infer<typeof revocationSchema>;

/** Everything the settings screen shows, read together so the generation is consistent. */
export interface AuthorityConfiguration {
  generation: number;
  mode: AuthorityMode;
  policies: DiscountPolicy[];
  bands: DiscountBand[];
  assignments: DiscountAssignment[];
  activations: PolicyActivation[];
  revocations: AssignmentRevocation[];
  /** True when a collection holds more rows than were read (the newest 100 are shown). */
  truncated: boolean;
}

export const readPolicyPage = (value: unknown) => page(policySchema).parse(value);
export const readBandPage = (value: unknown) => page(bandSchema).parse(value);
export const readAssignmentPage = (value: unknown) =>
  page(assignmentSchema).parse(value);
export const readActivationPage = (value: unknown) =>
  page(policyActivationSchema).parse(value);
export const readRevocationPage = (value: unknown) =>
  page(revocationSchema).parse(value);
export const readChange = (value: unknown) =>
  z.object({ id, mode: z.string().nullable().optional() }).parse(value);

/** What the policy form sends: ceilings are decimal strings (at most four places) or null. */
export interface BandInput {
  rank: number;
  label: string;
  exception_only: boolean;
  line_percent: string | null;
  line_base_amount: string | null;
  document_percent: string | null;
  document_base_amount: string | null;
  input_percent: string | null;
  input_base_amount: string | null;
}
export interface PolicyInput {
  code: string;
  priority: number;
  operation: "ANY" | "DISCOUNT" | "OVERRIDE";
  enforcement: "LINE" | "DOCUMENT" | "BOTH";
  effective_from: string;
  effective_to: string | null;
  expiry_calendar_days: number;
  allow_higher_approver: boolean;
  bands: BandInput[];
}
export interface AssignmentInput {
  user_id: number;
  band_id: number;
  purpose: "ROUTINE" | "APPROVE" | "BOTH";
  role_id: number | null;
  unit_id: number | null;
  effective_from: string;
  effective_to: string | null;
}
export interface Person {
  id: number;
  name: string;
}

export interface DiscountAuthorityGateway {
  read(): Promise<AuthorityConfiguration>;
  /** Named people for the assignment form; may fail (no member access), in which case the form asks for a reference number. */
  people(): Promise<Person[]>;
  /** Role and organisation unit lookups for the assignment form; either may fail (no access), then the form asks for a reference number. */
  roles(): Promise<Person[]>;
  units(): Promise<Person[]>;
  createPolicy(input: {
    policy: PolicyInput;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
  activatePolicy(input: {
    policyId: number;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
  assign(input: {
    assignment: AssignmentInput;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
  revoke(input: {
    assignmentId: number;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
}

export const MODE_VIEW: Record<
  AuthorityMode,
  { label: string; tone: "draft" | "pending" | "success"; note: string }
> = {
  OFF: {
    label: "Off",
    tone: "draft",
    note: "Discount authority is not active. Quotations are not checked against anyone’s limits, and nothing here changes that.",
  },
  SHADOW: {
    label: "Record only",
    tone: "pending",
    note: "Quotations are checked and the result is recorded, but nobody is held back by it.",
  },
  ENFORCE: {
    label: "Enforced",
    tone: "success",
    note: "Quotations are checked against each person’s limits. A concession above them needs an independent approver.",
  },
};

export const PURPOSE_LABEL: Record<AssignmentInput["purpose"], string> = {
  ROUTINE: "Clear own discounts",
  APPROVE: "Approve others’ discounts",
  BOTH: "Clear and approve",
};
export const OPERATION_LABEL: Record<PolicyInput["operation"], string> = {
  ANY: "Discounts and price overrides",
  DISCOUNT: "Discounts only",
  OVERRIDE: "Price overrides only",
};
export const ENFORCEMENT_LABEL: Record<PolicyInput["enforcement"], string> = {
  LINE: "Each line",
  DOCUMENT: "Whole document",
  BOTH: "Lines and document",
};

/** Wording for a refused configuration write; only the stable code decides the text. */
export const AUTHORITY_REFUSALS: Record<string, string> = {
  AUTHORITY_STALE:
    "The configuration changed since you opened it. Reload it, check the current state and try again.",
  AUTHORITY_INPUT_INVALID:
    "Some of these values are not accepted. Check the limits: a routine band needs a limit for each level it covers, and an exception band has none.",
  AUTHORITY_POLICY_OVERLAP:
    "Another policy version with the same priority already covers these dates and conditions. Change the dates or the priority.",
  AUTHORITY_BASE_CURRENCY_REQUIRED:
    "Set the company’s base currency before adding monetary limits.",
  REQUEST_KEY_REUSED:
    "This request was already used for different details. Start again.",
  REQUEST_KEY_REQUIRED: "The request could not be identified. Try again.",
  REFERENCE_INVALID:
    "This reference is not accepted. Name the item and its measure, or a project baseline with its family and measure; a customer agreement must name its customer; the price must be more than zero.",
  REFERENCE_INDEPENDENT_APPROVAL_REQUIRED:
    "Someone other than the person who proposed this price must approve it.",
  REFERENCE_ALREADY_APPROVED: "This reference version has already been approved.",
  REFERENCE_BACKDATE_REFUSED:
    "A reference can’t take effect on a date that has already passed. Propose a new version with a current start date.",
  REFERENCE_NOT_BOUND:
    "Capture the approved reference for this quotation before authority can clear a line.",
  REFERENCE_MISSING:
    "No independently approved price reference covers this quotation. Propose one and have someone else approve it first.",
  REFERENCE_AMBIGUOUS:
    "Two equally ranked price references apply. Change a priority or end one of them.",
  REFERENCE_CHANGED:
    "The source or measure of this quotation changed, so its reference needs independent review first.",
  REFERENCE_UNMEASURABLE:
    "The reference price is zero, so no percentage can be checked.",
  QUOTATION_NOT_EDITABLE: "Only a draft quotation can have references captured.",
};

/** Plain wording for each reason the evaluation can give; unknown codes get the generic line. */
export const EVALUATION_REASONS: Record<string, string> = {
  FINAL_NET_OVERRIDE_REQUIRES_APPROVAL:
    "A final price override always needs an independent approver.",
  UNDECLARED_REDUCTION_REQUIRES_APPROVAL:
    "The price is lower than the trusted reference without a declared discount, so it needs an independent approver.",
  EMPLOYEE_AUTHORITY_MISSING:
    "The person who entered the discount has no discount authority assigned.",
  EMPLOYEE_AUTHORITY_EXCEEDED:
    "The discount is above the limit assigned to the person who entered it.",
  APPROVER_BAND_MISSING:
    "No authority level is configured that could approve a discount this size.",
  AUTHORITY_POLICY_MISSING:
    "No discount authority policy covers this quotation.",
  AUTHORITY_POLICY_AMBIGUOUS:
    "More than one policy covers this quotation equally. An administrator needs to resolve this.",
  AUTHORITY_ASSIGNMENT_AMBIGUOUS:
    "A person has more than one equally ranked assignment. An administrator needs to resolve this.",
  AUTHORITY_PRICE_MISSING:
    "There is no trusted reference price for one of the lines.",
  AUTHORITY_FX_UNVERIFIED:
    "The exchange rate used for this quotation could not be verified.",
  AUTHORITY_EVIDENCE_UNVERIFIED:
    "The supporting evidence could not be verified.",
  AUTHORITY_BASE_CURRENCY_REQUIRED:
    "The company’s base currency is not set.",
  AUTHORITY_BASE_CURRENCY_CHANGED:
    "The company’s base currency changed after this was evaluated.",
  AUTHORITY_APPROVAL_EXPIRED: "The approval for this concession has expired.",
  AUTHORITY_DISABLED: "Discount authority is not active.",
};

export const SOURCE_KINDS = [
  "CUSTOMER_AGREEMENT",
  "PRICE_LIST",
  "PROJECT_BASELINE",
] as const;
export type SourceKind = (typeof SOURCE_KINDS)[number];
export const SOURCE_KIND_LABEL: Record<SourceKind, string> = {
  CUSTOMER_AGREEMENT: "Customer agreement",
  PRICE_LIST: "Price list",
  PROJECT_BASELINE: "Project baseline",
};
export const referenceSchema = z.object({
  id,
  code: z.string(),
  version_no: z.number().int(),
  source_kind: z.enum(SOURCE_KINDS),
  source_key: z.string(),
  source_version: z.string(),
  family_id: id.nullable(),
  customer_id: id.nullable(),
  item_id: id.nullable(),
  manual_measure: z.string().nullable(),
  uom_id: id.nullable(),
  currency_id: id,
  unit_price: z.string(),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
  priority: z.number().int(),
  proposed_by: id.nullable(),
  reason: z.string().nullable(),
});
export const referenceApprovalSchema = z.object({
  id,
  reference_id: id,
  approved_by: id.nullable(),
  reason: z.string().nullable(),
  recorded_at: z.string(),
});
export type PriceReference = z.infer<typeof referenceSchema>;
export type ReferenceApproval = z.infer<typeof referenceApprovalSchema>;
export const readReferencePage = (value: unknown) =>
  page(referenceSchema).parse(value);
export const readReferenceApprovalPage = (value: unknown) =>
  page(referenceApprovalSchema).parse(value);

export interface ReferenceConfiguration {
  generation: number;
  mode: AuthorityMode;
  references: PriceReference[];
  approvals: ReferenceApproval[];
  truncated: boolean;
}
/** What the reference form sends; ids and the price are validated by the form model, the server stays authoritative. */
export interface ReferenceInput {
  code: string;
  source_kind: SourceKind;
  source_key: string;
  source_version: string;
  family_id: number | null;
  customer_id: number | null;
  item_id: number | null;
  manual_measure: string | null;
  uom_id: number | null;
  currency_id: number;
  unit_price: string;
  effective_from: string;
  effective_to: string | null;
  priority: number;
}
export interface Currency {
  id: number;
  code: string;
  name: string;
}
export interface ReferenceGateway {
  read(): Promise<ReferenceConfiguration>;
  people(): Promise<Person[]>;
  currencies(): Promise<Currency[]>;
  propose(input: {
    reference: ReferenceInput;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
  approve(input: {
    referenceId: number;
    reason: string;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<{ id: number }>;
}

const decimal = z.string();
export const requiredBandSchema = z.object({
  id,
  policy_id: id,
  rank: z.number().int(),
  label: z.string(),
  exception_only: flag,
  line_percent: money,
  line_base_amount: money,
  document_percent: money,
  document_base_amount: money,
  input_percent: money,
  input_base_amount: money,
  enforcement: z.string(),
  allow_higher_approver: flag,
});
export const evaluationSchema = z.object({
  required_band: requiredBandSchema.nullable(),
  result: z.enum([
    "NOT_APPLICABLE",
    "ROUTINE",
    "APPROVAL_REQUIRED",
    "UNEVALUABLE",
  ]),
  reasons: z.array(z.string()),
  policy_id: id.nullable(),
  required_rank: z.number().int().nullable(),
  currency_id: id.nullable(),
  base_currency_id: id.nullable(),
  reference_amount: money,
  reduction_amount: money,
  reduction_base_amount: money,
  final_net_amount: money,
  document_percent_exact: money,
  lines: z.array(
    z.object({
      line_id: id,
      reference_amount: decimal,
      final_net_amount: decimal,
      reduction_amount: decimal,
    }),
  ),
});
export const quotationAuthoritySchema = z.object({
  revision_id: id,
  mode: z.enum(MODES),
  current: evaluationSchema.nullable(),
});
export type AuthorityEvaluation = z.infer<typeof evaluationSchema>;
export type QuotationAuthority = z.infer<typeof quotationAuthoritySchema>;
export const readQuotationAuthority = (value: unknown) =>
  quotationAuthoritySchema.parse(value);

export interface QuotationAuthorityGateway {
  read(revisionId: number): Promise<QuotationAuthority>;
  /** The immutable evaluation summaries recorded for one revision, newest first. */
  history(revisionId: number, page: number): Promise<AuthorityHistoryPage>;
  /** Capture the approved reference for each line of a draft. Changes no price and clears nothing by itself. */
  bind(input: {
    revisionId: number;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<BindResult>;
}

export const historyRecordSchema = z.object({
  id,
  actor_id: id,
  mode: z.enum(MODES),
  input_generation: z.number().int(),
  recorded_at: z.string(),
  evaluation: evaluationSchema,
});
export const historyPageSchema = z.object({
  revision_id: id,
  mode: z.enum(MODES),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
  total: z.number().int().nonnegative(),
  records: z.array(historyRecordSchema),
});
export type AuthorityHistoryRecord = z.infer<typeof historyRecordSchema>;
export type AuthorityHistoryPage = z.infer<typeof historyPageSchema>;
export const readAuthorityHistory = (value: unknown) =>
  historyPageSchema.parse(value);

export const bindResultSchema = z.object({
  family_id: id,
  revision_id: id,
  bound_lines: z.number().int().nonnegative(),
});
export type BindResult = z.infer<typeof bindResultSchema>;
export const readBindResult = (value: unknown) => bindResultSchema.parse(value);

/** What the history rows say about each stored result, in the same plain wording as the live result. */
export function historyState(record: AuthorityHistoryRecord): AuthorityStateView {
  return authorityState({
    revision_id: 1,
    mode: record.mode,
    current: record.evaluation,
  });
}

/** The single plain-language state a person sees for one quotation. */
export type AuthorityStateKey =
  | "INACTIVE"
  | "NOT_CONFIGURED"
  | "NOT_EVALUATED"
  | "NOT_APPLICABLE"
  | "WITHIN_AUTHORITY"
  | "NEEDS_APPROVAL"
  | "CANNOT_EVALUATE";

export interface AuthorityStateView {
  key: AuthorityStateKey;
  label: string;
  tone: "draft" | "pending" | "success" | "danger";
  note: string;
}

export function authorityState(data: QuotationAuthority): AuthorityStateView {
  const current = data.current;
  if (data.mode === "OFF")
    return {
      key: "INACTIVE",
      label: "Not active",
      tone: "draft",
      note: "Discount authority is switched off for this company, so this quotation is not checked against anyone’s limits.",
    };
  if (current === null)
    return {
      key: "NOT_EVALUATED",
      label: "No result recorded",
      tone: "draft",
      note: "No discount authority result was recorded for this quotation.",
    };
  if (
    current.result === "UNEVALUABLE" &&
    (current.policy_id === null ||
      current.reasons.includes("AUTHORITY_POLICY_MISSING"))
  )
    return {
      key: "NOT_CONFIGURED",
      label: "No authority levels set up",
      tone: "draft",
      note: "No discount authority policy with authority levels covers this quotation yet. Ask an administrator.",
    };
  switch (current.result) {
    case "NOT_APPLICABLE":
      return {
        key: "NOT_APPLICABLE",
        label: "No discount to check",
        tone: "draft",
        note: "This quotation has no discount or price reduction that needs authority.",
      };
    case "ROUTINE":
      return {
        key: "WITHIN_AUTHORITY",
        label: "Within authority",
        tone: "success",
        note: "The discount is within the limit of the person who entered it.",
      };
    case "APPROVAL_REQUIRED":
      return {
        key: "NEEDS_APPROVAL",
        label: "Needs approval",
        tone: "pending",
        note: "The discount is above what the person who entered it may clear alone. An independent approver has to approve it.",
      };
    default:
      return {
        key: "CANNOT_EVALUATE",
        label: "Cannot be checked",
        tone: "danger",
        note: "The discount could not be checked, so it cannot be cleared. The reasons are listed below.",
      };
  }
}

export function reasonText(code: string): string {
  return (
    EVALUATION_REASONS[code] ?? "A check on this quotation did not pass."
  );
}

/** Whether a label or note marks illustrative fixture data (never a real limit or person). */
export const isDemoOnly = (text: string | null | undefined): boolean =>
  typeof text === "string" && text.includes("DEMO ONLY");

/** MOCK DATA: tests and previews only. */
export function mockAuthority(
  overrides: Partial<QuotationAuthority> = {},
): QuotationAuthority {
  return {
    revision_id: 12,
    mode: "ENFORCE",
    current: mockEvaluation(),
    ...overrides,
  };
}
export function mockEvaluation(
  overrides: Partial<AuthorityEvaluation> = {},
): AuthorityEvaluation {
  return {
    required_band: {
      id: 3,
      policy_id: 2,
      rank: 2,
      label: "DEMO ONLY Sales manager",
      exception_only: false,
      line_percent: "10.0000",
      line_base_amount: null,
      document_percent: "10.0000",
      document_base_amount: null,
      input_percent: null,
      input_base_amount: null,
      enforcement: "BOTH",
      allow_higher_approver: false,
    },
    result: "APPROVAL_REQUIRED",
    reasons: ["EMPLOYEE_AUTHORITY_EXCEEDED"],
    policy_id: 2,
    required_rank: 2,
    currency_id: 1,
    base_currency_id: 1,
    reference_amount: "1000.0000",
    reduction_amount: "80.0000",
    reduction_base_amount: "80.0000",
    final_net_amount: "920.0000",
    document_percent_exact: "8.0000",
    lines: [],
    ...overrides,
  };
}
export function mockConfiguration(
  overrides: Partial<AuthorityConfiguration> = {},
): AuthorityConfiguration {
  const band = (
    idValue: number,
    rank: number,
    label: string,
    percent: string | null,
  ): DiscountBand => ({
    id: idValue,
    policy_id: 2,
    rank,
    label,
    exception_only: percent === null,
    line_percent: percent,
    line_base_amount: null,
    document_percent: percent,
    document_base_amount: null,
    input_percent: null,
    input_base_amount: null,
  });
  return {
    generation: 4,
    mode: "OFF",
    policies: [
      {
        id: 2,
        code: "demo-only-authority",
        version_no: 1,
        priority: 0,
        base_currency_id: 1,
        allow_higher_approver: false,
        operation: "ANY",
        customer_id: null,
        unit_id: null,
        contains_item_id: null,
        enforcement: "BOTH",
        effective_from: "2026-11-01",
        effective_to: null,
        expiry_calendar_days: 14,
        created_by: 5,
        reason: "DEMO ONLY illustrative values; no approved real holders or limits",
      },
      {
        id: 3,
        code: "demo-only-authority",
        version_no: 2,
        priority: 0,
        base_currency_id: 1,
        allow_higher_approver: false,
        operation: "DISCOUNT",
        customer_id: null,
        unit_id: null,
        contains_item_id: null,
        enforcement: "LINE",
        effective_from: "2026-12-01",
        effective_to: null,
        expiry_calendar_days: 7,
        created_by: 5,
        reason: "DEMO ONLY proposed next version",
      },
    ],
    bands: [
      band(3, 1, "DEMO ONLY Sales rep", "5.0000"),
      band(4, 2, "DEMO ONLY Sales manager", "10.0000"),
      band(5, 4, "DEMO ONLY independent exception", null),
    ],
    assignments: [
      {
        id: 7,
        user_id: 21,
        band_id: 3,
        purpose: "ROUTINE",
        role_id: null,
        unit_id: null,
        effective_from: "2026-11-01",
        effective_to: null,
        assigned_by: 5,
        reason: "DEMO ONLY illustrative assignment; no capability grant",
      },
    ],
    activations: [
      {
        id: 1,
        policy_id: 2,
        actor_id: 6,
        reason: "DEMO ONLY policy fixture; mode remains unchanged",
        recorded_at: "2026-11-01T08:00:00Z",
      },
    ],
    revocations: [],
    truncated: false,
    ...overrides,
  };
}

export function mockHistoryPage(
  overrides: Partial<AuthorityHistoryPage> = {},
): AuthorityHistoryPage {
  return {
    revision_id: 12,
    mode: "ENFORCE",
    page: 1,
    per_page: 25,
    total: 2,
    records: [
      {
        id: 9,
        actor_id: 21,
        mode: "ENFORCE",
        input_generation: 5,
        recorded_at: "2026-11-09T08:30:00Z",
        evaluation: mockEvaluation(),
      },
      {
        id: 4,
        actor_id: 21,
        mode: "SHADOW",
        input_generation: 3,
        recorded_at: "2026-11-08T07:00:00Z",
        evaluation: mockEvaluation({
          result: "ROUTINE",
          reasons: [],
          required_band: null,
          reduction_amount: "30.0000",
          final_net_amount: "970.0000",
          document_percent_exact: "3.0000",
        }),
      },
    ],
    ...overrides,
  };
}
export function mockReferenceConfiguration(
  overrides: Partial<ReferenceConfiguration> = {},
): ReferenceConfiguration {
  return {
    generation: 6,
    mode: "OFF",
    references: [
      {
        id: 11,
        code: "demo-only-price-list",
        version_no: 1,
        source_kind: "PRICE_LIST",
        source_key: "DEMO ONLY list",
        source_version: "2026-11",
        family_id: null,
        customer_id: null,
        item_id: 31,
        manual_measure: null,
        uom_id: 4,
        currency_id: 1,
        unit_price: "100.0000",
        effective_from: "2026-11-01",
        effective_to: null,
        priority: 0,
        proposed_by: 5,
        reason: "DEMO ONLY illustrative reference",
      },
      {
        id: 12,
        code: "demo-only-baseline",
        version_no: 1,
        source_kind: "PROJECT_BASELINE",
        source_key: "DEMO ONLY baseline",
        source_version: "1",
        family_id: 3,
        customer_id: null,
        item_id: null,
        manual_measure: "per square metre",
        uom_id: null,
        currency_id: 1,
        unit_price: "250.0000",
        effective_from: "2026-11-01",
        effective_to: "2026-12-31",
        priority: 1,
        proposed_by: 5,
        reason: "DEMO ONLY proposed baseline",
      },
    ],
    approvals: [
      {
        id: 2,
        reference_id: 11,
        approved_by: 6,
        reason: "DEMO ONLY independent review",
        recorded_at: "2026-11-02T08:00:00Z",
      },
    ],
    truncated: false,
    ...overrides,
  };
}
