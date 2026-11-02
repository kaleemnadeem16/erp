import { z } from "zod";

/**
 * CONTRACT PENDING. phase4/ql3-issue-quotation (402dc945) ships the Actions
 * AssessQuotationIssueReadiness and IssueQuotation but NO HTTP routes and no generated
 * client. These types mirror the Actions' documented inputs and outputs so the screens can
 * be built and tested; when routes exist, only `QuotationIssueGateway`'s implementation
 * changes. Nothing here is called by a registered route.
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
 * CONTRACT PENDING — NOT CALLED BY ANY SCREEN. No backend read of an issued revision's frozen terms exists
 * (phase4/ql3-issue-quotation has no such route; `IssueQuotation` returns only the outcome above). This shape
 * and `QuotationIssuedView` stay for the design; the issue screen shows only the real outcome until a read
 * contract exists and these fields are re-verified against it.
 */
export const issuedTermsSchema = z.object({
  revision_id: id,
  official_number: z.string().min(1),
  issued_at: z.string(),
  /** The maker: who prepared (created) the draft. */
  prepared_by: id,
  /** The checker: who issued it. May equal the maker; separation is by duty. */
  issued_by: id,
  currency_id: id,
  pricing_date: z.string().nullable(),
  valid_through_local_date: z.string().nullable(),
  expires_at: z.string().nullable(),
  net_total: decimal,
  optional_net_total: decimal.nullable(),
  tax_total: decimal,
  gross_total: decimal,
  controls_outcome: z.string(),
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
}

const pending = async (): Promise<never> => {
  throw new Error("The quotation issue contract is not available yet.");
};
/** Fails closed until the issue routes and generated client exist. SWAP: implement with the generated functions. */
export const pendingIssueGateway: QuotationIssueGateway = {
  readiness: pending,
  issue: pending,
};

/** Named groups the screen shows, in this order. */
export type BlockerGroup =
  | "recipient"
  | "lines"
  | "validity"
  | "pricing"
  | "tax"
  | "controls"
  | "authority";
export const BLOCKER_GROUPS: { id: BlockerGroup; title: string }[] = [
  { id: "recipient", title: "Recipient" },
  { id: "lines", title: "Lines" },
  { id: "validity", title: "Validity" },
  { id: "pricing", title: "Pricing, exchange rates and rounding" },
  { id: "tax", title: "Tax" },
  { id: "controls", title: "Financial controls" },
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
  CONTROLS_NOT_CONFIGURED: {
    group: "controls",
    label: "Financial controls have not been configured.",
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
    official_number: "QUO-2026-0007",
    issued_at: "2026-10-30T10:15:00Z",
    prepared_by: 5,
    issued_by: 9,
    currency_id: 3,
    pricing_date: "2026-10-27",
    valid_through_local_date: "2026-11-30",
    expires_at: "2026-12-01T00:00:00Z",
    net_total: "1000.00",
    optional_net_total: null,
    tax_total: "150.00",
    gross_total: "1150.00",
    controls_outcome: "PASSED",
    ...overrides,
  };
}
