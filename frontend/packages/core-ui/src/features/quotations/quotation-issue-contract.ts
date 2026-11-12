import { z } from "zod";

/**
 * CONTRACT for the QL3 issue routes (generated client: quotationsIssueReadiness,
 * quotationsIssue, quotationsIssued; the live gateway is in quotation-live-gateways.ts).
 * These schemas parse and strip every response; the screens depend only on them.
 *
 * Action facts mirrored:
 *  - readiness: needs prepare; returns {revision_id, input_generation, issue_ready,
 *    blockers[{code, scope, line_id}]}; codes only, no amounts or free text.
 *  - issue: needs issue AND an independent view; takes expected_generation and a request
 *    key; returns {family_id, revision_id, issue_id, official_number, issued_at, replayed}.
 *    Refusals: QUOTATION_DRAFT_STALE, QUOTATION_NOT_ISSUABLE, QUOTATION_ALREADY_ISSUED,
 *    QUOTATION_NOT_EDITABLE, QUOTATION_NOT_FOUND, REQUEST_KEY_REQUIRED, REQUEST_KEY_REUSED.
 */
const id = z.number().int().positive();

export const BLOCKER_SCOPES = [
  "HEADER",
  "LINE",
  "TAX",
  "CONTROLS",
  "AUTHORITY",
] as const;
export const blockerSchema = z.object({
  code: z.string(),
  scope: z.enum(BLOCKER_SCOPES),
  line_id: id.nullable(),
});
export const readinessSchema = z.object({
  revision_id: id,
  input_generation: z.number().int().nonnegative(),
  issue_ready: z.boolean(),
  blockers: z.array(blockerSchema),
});
export const issueOutcomeSchema = z.object({
  family_id: id,
  revision_id: id,
  issue_id: id,
  official_number: z.string().min(1),
  issued_at: z.string(),
  replayed: z.boolean(),
});
const decimal = z.string().regex(/^-?\d+(?:\.\d+)?$/);
/**
 * The issued record, mirroring `QuotationIssuedResource` (GET /api/v1/quotations/{revision}/issued): official number, issue
 * instant, maker/checker and frozen header terms. `totals` is null unless the caller also holds prepare or issue for this
 * revision. The server never sends cost, margin, controls outcome, fingerprints or tax evidence; any extra is stripped.
 */
export const issuedTermsSchema = z.object({
  revision_id: id,
  family_id: id,
  official_number: z.string().min(1),
  issued_at: z.string(),
  /** The maker: who prepared (created) the draft. */
  prepared_by: id,
  /** The checker: who issued it. May equal the maker; separation is by duty. */
  issued_by: id,
  currency_id: id,
  pricing_date: z.string(),
  valid_through_local_date: z.string(),
  expires_at: z.string(),
  /** Derived by the server, never stored: AVAILABLE, EXPIRED, WITHDRAWN or REPLACED. Says nothing about acceptance. */
  availability: z.string(),
  /**
   * Per issued line: ACCEPTED, AVAILABLE, CLAIMED (covered by an acceptance recorded elsewhere) or UNAVAILABLE. Null (or absent) unless the caller may
   * see customer messages. Never names a competing acceptance, customer or quantity.
   */
  scope_acceptance: z
    .array(
      z.object({
        issued_line_id: id,
        logical_scope_id: id,
        status: z.string(),
      }),
    )
    .nullable()
    .optional(),
  totals: z
    .object({
      net_total: decimal,
      optional_net_total: decimal.nullable(),
      tax_total: decimal.nullable(),
      gross_total: decimal.nullable(),
    })
    .nullable(),
});
export type IssueBlocker = z.infer<typeof blockerSchema>;
export type IssueReadiness = z.infer<typeof readinessSchema>;
export type IssueOutcome = z.infer<typeof issueOutcomeSchema>;
export type IssuedTerms = z.infer<typeof issuedTermsSchema>;

export const readReadiness = (value: unknown) => readinessSchema.parse(value);
export const readIssueOutcome = (value: unknown) =>
  issueOutcomeSchema.parse(value);
export const readIssuedTerms = (value: unknown) =>
  issuedTermsSchema.parse(value);

export interface QuotationIssueGateway {
  readiness(revisionId: number): Promise<IssueReadiness>;
  /** `requestKey` is sent as the Idempotency-Key; the same key must be reused on retry. */
  issue(input: {
    revisionId: number;
    expectedGeneration: number;
    requestKey: string;
  }): Promise<IssueOutcome>;
  /** The issued record; a draft, cancelled draft, hidden or unknown revision is a 404. */
  issued(revisionId: number): Promise<IssuedTerms>;
}

const pending = async (): Promise<never> => {
  throw new Error("The quotation issue contract is not available yet.");
};
/** Fails closed until the issue routes and generated client exist. SWAP: implement with the generated functions. */
export const pendingIssueGateway: QuotationIssueGateway = {
  readiness: pending,
  issue: pending,
  issued: pending,
};

/** Named groups the screen shows, in this order. */
export type BlockerGroup =
  | "recipient"
  | "lines"
  | "validity"
  | "pricing"
  | "tax"
  | "controls"
  | "approval"
  | "authority";
export const BLOCKER_GROUPS: { id: BlockerGroup; title: string }[] = [
  { id: "recipient", title: "Recipient" },
  { id: "lines", title: "Lines" },
  { id: "validity", title: "Validity" },
  { id: "pricing", title: "Pricing, exchange rates and rounding" },
  { id: "tax", title: "Tax" },
  { id: "controls", title: "Financial controls" },
  { id: "approval", title: "Approval" },
  { id: "authority", title: "Authority" },
];

const KNOWN: Record<string, { group: BlockerGroup; label: string }> = {
  RECIPIENT_REQUIRED: {
    group: "recipient",
    label: "Choose who the quotation is for.",
  },
  RECIPIENT_NOT_ACTIVE: {
    group: "recipient",
    label: "The recipient is no longer active.",
  },
  PARTY_NOT_FOUND: {
    group: "recipient",
    label: "The recipient is unavailable or archived.",
  },
  NO_LINES: { group: "lines", label: "Add at least one quotation line." },
  PRICE_NOT_ENTERED: {
    group: "lines",
    label: "A unit price has not been entered.",
  },
  NET_UNKNOWN: { group: "lines", label: "A line net amount is not known." },
  NET_NOT_COMPUTED: {
    group: "lines",
    label: "A line net has not been calculated.",
  },
  CHOICE_BASELINE_MISSING: {
    group: "lines",
    label: "Choose a baseline for each set of alternatives.",
  },
  SOURCE_NOT_FOUND: {
    group: "lines",
    label: "A line’s source is unavailable or archived.",
  },
  SOURCE_INCOMPATIBLE: {
    group: "lines",
    label: "A line’s source is not compatible.",
  },
  NO_ELIGIBLE_PRICE: {
    group: "lines",
    label: "No eligible price exists for a line.",
  },
  PRICING_RULE_CONFLICT: {
    group: "lines",
    label: "Conflicting pricing rules need review.",
  },
  VALID_THROUGH_REQUIRED: {
    group: "validity",
    label: "Set the date the quotation is valid through.",
  },
  VALID_THROUGH_PAST: {
    group: "validity",
    label: "The valid-through date has passed.",
  },
  PRICING_DATE_REQUIRED: { group: "pricing", label: "Set a pricing date." },
  ROUNDING_POLICY_CHANGED: {
    group: "pricing",
    label: "The rounding policy has changed; review the prices.",
  },
  UNIT_PRICE_PRECISION_EXCEEDED: {
    group: "pricing",
    label: "A unit price exceeds the current precision policy.",
  },
  FX_EVIDENCE_CHANGED: {
    group: "pricing",
    label: "The pinned exchange rate has changed; review the prices.",
  },
  FX_EVIDENCE_STALE: {
    group: "pricing",
    label: "The pinned exchange rate is out of date; review the prices.",
  },
  FX_EVIDENCE_MISSING: {
    group: "pricing",
    label: "Exchange-rate evidence is missing.",
  },
  TAX_NOT_DETERMINED: { group: "tax", label: "Tax has not been determined." },
  TAX_ESTIMATE_DATE_REQUIRED: {
    group: "tax",
    label: "Set the tax estimate date.",
  },
  TAX_JURISDICTION_UNKNOWN: {
    group: "tax",
    label: "The tax jurisdiction for this date is not set up.",
  },
  TAX_CLASSIFICATION_REQUIRED: {
    group: "tax",
    label: "A line has no tax classification declared.",
  },
  TAX_NET_UNKNOWN: {
    group: "tax",
    label: "Tax cannot be worked out until the line’s net amount is known.",
  },
  TAX_CONTEXT_UNKNOWN: {
    group: "tax",
    label: "Tax for a line could not be determined.",
  },
  TAX_CALCULATION_UNSUPPORTED: {
    group: "tax",
    label: "Tax for a line is not supported yet.",
  },
  TAX_CONTEXT_NOT_AUTHORISED: {
    group: "tax",
    label: "You are not allowed to see the tax evidence for a line.",
  },
  TAX_GENERATION_MOVED: {
    group: "tax",
    label: "Tax rules changed while checking; check again.",
  },
  APPROVAL_REQUIRED: {
    group: "approval",
    label:
      "A discount or price override needs approval before this can be issued.",
  },
  APPROVAL_PENDING: {
    group: "approval",
    label: "A discount or price override is waiting for someone to approve it.",
  },
  APPROVAL_REJECTED: {
    group: "approval",
    label:
      "The approver rejected a discount or price override. Change it before asking again.",
  },
  CONTROLS_NOT_CONFIGURED: {
    group: "controls",
    label: "Financial controls have not been configured.",
  },
  CONTROLS_NO_MANDATORY_CONTROL: {
    group: "controls",
    label: "The financial controls include no mandatory control.",
  },
  CONTROLS_NO_LINES: {
    group: "controls",
    label: "Financial controls cannot be checked without lines.",
  },
  CONTROLS_NET_UNKNOWN: {
    group: "controls",
    label:
      "Financial controls cannot be checked until the line’s net is known.",
  },
  CONTROL_FAILED: {
    group: "controls",
    label: "A line does not meet a mandatory financial control.",
  },
  CONTROL_UNEVALUABLE: {
    group: "controls",
    label: "A mandatory financial control cannot be checked for a line.",
  },
};

export interface BlockerView {
  group: BlockerGroup;
  label: string;
  /** 1-based position of the line among the revision's lines is unknown; a line-scoped blocker is described, not numbered by raw id. */
  lineScoped: boolean;
}

/** Unknown codes are described by scope only; the raw code is never shown as text. */
export function describeBlocker(blocker: IssueBlocker): BlockerView {
  const known = KNOWN[blocker.code];
  const lineScoped = blocker.scope === "LINE";
  if (known) return { ...known, lineScoped };
  if (blocker.code.startsWith("FX_EVIDENCE_"))
    return {
      group: "pricing",
      label: "Exchange-rate evidence needs review.",
      lineScoped,
    };
  const byScope: Record<(typeof BLOCKER_SCOPES)[number], BlockerView> = {
    HEADER: {
      group: "validity",
      label: "A quotation header check needs attention.",
      lineScoped,
    },
    LINE: { group: "lines", label: "A line needs attention.", lineScoped },
    TAX: { group: "tax", label: "A tax check needs attention.", lineScoped },
    CONTROLS: {
      group: "controls",
      label: "A financial control needs attention.",
      lineScoped,
    },
    AUTHORITY: {
      group: "authority",
      label: "You are not able to issue this quotation.",
      lineScoped,
    },
  };
  return byScope[blocker.scope];
}

export function groupBlockers(blockers: IssueBlocker[]) {
  return BLOCKER_GROUPS.map((group) => {
    const seen = new Set<string>();
    const items: { label: string; lineCount: number }[] = [];
    for (const blocker of blockers) {
      const view = describeBlocker(blocker);
      if (view.group !== group.id) continue;
      if (seen.has(view.label)) {
        items.find((item) => item.label === view.label)!.lineCount +=
          view.lineScoped ? 1 : 0;
        continue;
      }
      seen.add(view.label);
      items.push({ label: view.label, lineCount: view.lineScoped ? 1 : 0 });
    }
    return { ...group, items };
  }).filter((group) => group.items.length > 0);
}

/** Plain wording and tone for what an issued offer's availability says. Never claims acceptance. */
export const AVAILABILITY_VIEW: Record<
  string,
  {
    label: string;
    tone: "success" | "pending" | "danger" | "draft";
    note: string;
  }
> = {
  AVAILABLE: {
    label: "Issued",
    tone: "success",
    note: "This quotation is issued and open until its expiry date.",
  },
  EXPIRED: {
    label: "Expired",
    tone: "danger",
    note: "This quotation has passed its expiry and is no longer an open offer.",
  },
  WITHDRAWN: {
    label: "Withdrawn",
    tone: "danger",
    note: "This quotation was withdrawn and is no longer an open offer.",
  },
  REPLACED: {
    label: "Replaced",
    tone: "pending",
    note: "A newer revision replaced this quotation.",
  },
};
export function availabilityView(availability: string) {
  return (
    AVAILABILITY_VIEW[availability] ?? {
      label: "Issued",
      tone: "draft" as const,
      note: "",
    }
  );
}

export const ISSUE_REFUSALS: Record<string, string> = {
  QUOTATION_DRAFT_STALE:
    "The draft changed since you reviewed it. Check readiness again before issuing.",
  QUOTATION_NOT_ISSUABLE:
    "The quotation cannot be issued yet. Check readiness again.",
  QUOTATION_ALREADY_ISSUED: "This quotation has already been issued.",
  QUOTATION_NOT_EDITABLE: "Only a draft quotation can be issued.",
  REQUEST_KEY_REUSED:
    "This request was already used for a different action. Start the issue again.",
  REQUEST_KEY_REQUIRED: "The request could not be identified. Try again.",
};

/** Request keys are bound to the write intent; see `IntentKey` in ./request-key. */
export { newRequestKey } from "./request-key";

/** MOCK DATA: tests and the unregistered preview only. */
export function mockReadiness(
  overrides: Partial<IssueReadiness> = {},
): IssueReadiness {
  return {
    revision_id: 12,
    input_generation: 4,
    issue_ready: true,
    blockers: [],
    ...overrides,
  };
}
export function mockIssuedTerms(
  overrides: Partial<IssuedTerms> = {},
): IssuedTerms {
  return {
    revision_id: 12,
    family_id: 8,
    official_number: "QUO-2026-0007",
    issued_at: "2026-10-30T10:15:00Z",
    prepared_by: 5,
    issued_by: 9,
    currency_id: 3,
    pricing_date: "2026-10-27",
    valid_through_local_date: "2026-11-30",
    expires_at: "2026-12-01T00:00:00Z",
    availability: "AVAILABLE",
    totals: {
      net_total: "1000.00",
      optional_net_total: null,
      tax_total: "150.00",
      gross_total: "1150.00",
    },
    ...overrides,
  };
}

/** Historical isolated preview contract; live API consumers retain readinessSchema above. */
// Agent A's internal AssessQuotationIssueReadiness Action at 0c9a774a, not an HTTP contract.
// Reject inconsistent readiness and discard all non-public fields, including any evidence.
const previewId = z.number().int().positive().safe();
const previewBlocker = z.object({
  code: z.string().min(1).max(100),
  scope: z.enum(["HEADER", "LINE", "TAX", "CONTROLS", "AUTHORITY"]),
  line_id: previewId.nullable(),
});
const previewReadinessSchema = z
  .object({
    revision_id: previewId,
    input_generation: z.number().int().nonnegative().safe(),
    issue_ready: z.boolean(),
    blockers: z.array(previewBlocker),
  })
  .superRefine((value, ctx) => {
    if (value.issue_ready !== (value.blockers.length === 0))
      ctx.addIssue({ code: "custom", message: "Inconsistent readiness" });
    if (
      value.blockers.some(
        (item) => item.scope === "LINE" && item.line_id === null,
      )
    )
      ctx.addIssue({ code: "custom", message: "Missing line reference" });
  });
export function readIssueReadiness(value: unknown): IssueReadiness {
  return previewReadinessSchema.parse(value);
}
