// Standalone fixture: never imported by application routes. Scripted in-memory gateways, no network.
import { createElement, useState } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CustomerAcceptanceScreen } from "../../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-screen";
import { AcceptancePanel } from "../../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-panel";
import {
  acceptDisabledReason,
  customerAcceptanceAccess,
} from "../../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-access";
import type {
  Acceptance,
  CustomerAcceptanceGateway,
} from "../../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-contract";
import { QuotationIssuedView } from "../../../../packages/core-ui/src/features/quotations/quotation-issue-screen";
import {
  mockIssuedTerms,
  type IssuedTerms,
} from "../../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
import { QuotationLinkPicker } from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-quotation-picker";
import type {
  CaseComponentInput,
  CaseDetail,
  CustomerDecisionGateway,
} from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface CaFixture {
  screen: "issued" | "acceptance" | "picker";
  permissions: string[];
  availability?: string;
  /** Status of the second scope (the first is always AVAILABLE unless `firstStatus`). */
  secondStatus?: string;
  firstStatus?: string;
  /** The scope is one of a set of alternatives (the server wants a choice generation). */
  choice?: boolean;
  /** The latest observation is not eligible consent. */
  verbal?: boolean;
  /** The case has no link to this quotation. */
  noLink?: boolean;
  /** The first record call fails like a lost connection. */
  flaky?: boolean;
  /** The first record call is refused because the scope was claimed meanwhile. */
  claimed?: boolean;
  /** The first record call is refused as a stale review. */
  staleReview?: boolean;
  /** The record call reports an exact replay. */
  replay?: boolean;
  /** Acceptance detail: a scope in a choice group. */
  groupDetail?: boolean;
  /** Readiness fails to load once. */
  readinessFails?: boolean;
  /** The quotation lookup for the picker fails once. */
  pickerFails?: boolean;
  noQuotations?: boolean;
  hiddenScopes?: boolean;
}
declare global {
  interface Window {
    __ca: CaFixture;
    __caCalls: string[];
    __caLinked: CaseComponentInput[];
  }
}
const fixture = window.__ca;
window.__caCalls = [];
window.__caLinked = [];
configureQuotationDevMode({
  development: true,
  flag: "1",
  hostname: "localhost",
});
useAuthStore.setState({
  user: {
    id: 42,
    name: "Synthetic user",
    email: "u@fixture.test",
    must_change_password: false,
    permissions: fixture.permissions,
  },
});

const terms: IssuedTerms = mockIssuedTerms({
  availability: fixture.availability ?? "AVAILABLE",
  scope_acceptance: [
    {
      issued_line_id: 101,
      logical_scope_id: 30,
      status: fixture.firstStatus ?? "AVAILABLE",
    },
    {
      issued_line_id: 102,
      logical_scope_id: 31,
      status: fixture.secondStatus ?? "CLAIMED",
    },
  ],
});
const detail = (): CaseDetail => ({
  case: {
    id: 7,
    party_id: 3,
    party_name: "Alpha Traders",
    intake_identity: "PO-ALPHA-0417",
    generation: 2,
    recorded_by: 21,
    recorded_by_name: "Demo Recorder",
    recorded_at: "2026-11-10T08:00:00+05:00",
    consent_recorded: false,
  },
  components: fixture.noLink
    ? []
    : [
        {
          id: 1,
          intent_identity: "first scope",
          family_id: terms.family_id,
          original_scope_anchor_id: 30,
          recorded_at: "2026-11-10T08:00:00+05:00",
        },
      ],
  hidden_component_count: 0,
  observations: [
    {
      id: 5,
      predecessor_id: null,
      is_current: true,
      source_kind: fixture.verbal ? "VERBAL" : "PURCHASE_ORDER",
      external_reference: "PO-ALPHA-0417",
      source_version: null,
      customer_representative_snapshot: "Procurement, Alpha Traders",
      authority_verification_basis: fixture.verbal
        ? "UNVERIFIED"
        : "DOCUMENT_ON_FILE",
      occurrence_precision: "UNKNOWN",
      occurred_at: null,
      occurred_until: null,
      source_timezone: null,
      factual_description: "A purchase order arrived.",
      observation_state: "OBSERVED",
      recorded_by: 21,
      recorded_by_name: "Demo Recorder",
      recorded_at: "2026-11-10T09:00:00+05:00",
    },
  ],
});
let pickerFailed = false;
const decisions: CustomerDecisionGateway = {
  list: async (params) => ({
    data: [
      {
        id: 7,
        party_id: 3,
        party_name: "Alpha Traders",
        intake_identity: "PO-ALPHA-0417",
        generation: 2,
        component_count: 1,
        observation_count: 1,
        recorded_by: 21,
        recorded_at: "2026-11-10T08:00:00+05:00",
      },
    ],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: params.perPage,
      total: 1,
      from: 1,
      to: 1,
    },
  }),
  detail: async () => detail(),
  quotationsFor: async () => {
    if (fixture.pickerFails && !pickerFailed) {
      pickerFailed = true;
      throw Object.assign(new Error("refused"), { status: 422, body: {} });
    }
    return fixture.noQuotations
      ? []
      : [
          {
            revision_id: 12,
            family_id: 8,
            official_number: "QUO-2026-0007",
            availability: "AVAILABLE",
          },
        ];
  },
  quotationScopes: async () => ({
    revision_id: 12,
    family_id: 8,
    official_number: "QUO-2026-0007",
    scopes: fixture.hiddenScopes
      ? null
      : [
          { issued_line_id: 101, logical_scope_id: 30 },
          { issued_line_id: 102, logical_scope_id: 31 },
        ],
  }),
  open: async () => {
    throw new Error("not used here");
  },
  addObservation: async () => {
    throw new Error("not used here");
  },
};
const accepted: Acceptance = {
  id: 55,
  case_id: 7,
  decision_component_id: 1,
  revision_id: 12,
  consent_evidence_id: 5,
  policy_version_id: 1,
  recorded_by: 42,
  recorded_at: "2026-11-11T10:00:00+05:00",
  scope: {
    issued_line_id: 101,
    logical_scope_id: 30,
    choice_group_id: fixture.groupDetail ? 9 : null,
    description: "Supply and fit the rooftop unit",
    source_kind: "ASSEMBLY",
    quantity: "2.0000",
    uom_id: 4,
    unit_price: "500.00",
    currency_id: 3,
    net: "1000.00",
    tax: "150.00",
    gross: "1150.00",
  },
  acceptance_policy: "FULL_ONLY",
  unchanged_terms: true,
};
let recordFailed = false;
let readinessFailed = false;
const gateway: CustomerAcceptanceGateway = {
  preview: async ({ revisionId, intent }) => {
    window.__caCalls.push(
      `preview:${revisionId}:${intent.issued_line_id}:g${intent.expected_case_generation}:s${intent.expected_scope_generation}:c${intent.expected_choice_generation ?? "-"}`,
    );
    // An alternative is only reviewable with its choice generation: the first (ordinary) ask is answered as stale.
    if (fixture.choice && intent.expected_choice_generation === null)
      throw Object.assign(new Error("stale"), {
        status: 409,
        body: { reason_code: "PREVIEW_TOKEN_STALE" },
      });
    return {
      intent: { revision_id: revisionId, ...intent, unchanged_terms: true },
      terms: {
        issued_line_id: intent.issued_line_id,
        description: "Supply and fit the rooftop unit",
        quantity: "2.0000",
        uom_id: 4,
        unit_price: "500.00",
        currency_id: 3,
        net: "1000.00",
        tax: "150.00",
        gross: "1150.00",
      },
      unchanged_terms_statement:
        "The customer agrees to this full scope on the exact issued terms, unchanged.",
      warnings: ["DELIVERY_NOT_RECORDED"],
      preview_token: `token-${window.__caCalls.length}`,
      expires_in_seconds: 600,
    };
  },
  record: async ({ intent, previewToken, requestKey }) => {
    window.__caCalls.push(
      `record:${intent.issued_line_id}:${previewToken}:${requestKey}`,
    );
    if (!recordFailed && (fixture.flaky || fixture.claimed || fixture.staleReview)) {
      recordFailed = true;
      if (fixture.flaky)
        throw Object.assign(new Error("network"), { status: 503, body: {} });
      throw Object.assign(new Error("refused"), {
        status: 409,
        body: {
          reason_code: fixture.claimed
            ? "SCOPE_ALREADY_CLAIMED"
            : "PREVIEW_TOKEN_STALE",
          message: "SECRET detail of another customer",
        },
      });
    }
    return { acceptance_id: 55, case_id: 7, replayed: fixture.replay === true };
  },
  show: async () => accepted,
  readiness: async (acceptanceId, path) => {
    window.__caCalls.push(`readiness:${acceptanceId}:${path}`);
    if (fixture.readinessFails && !readinessFailed) {
      readinessFailed = true;
      throw Object.assign(new Error("refused"), { status: 422, body: {} });
    }
    return {
      acceptance_id: acceptanceId,
      assessment_version: "ACCEPTANCE_READINESS.1",
      path,
      status: "NOT_READY",
      missing: [
        path === "SERVICE_JOB"
          ? "SERVICE_JOB_ADAPTER_NOT_DELIVERED"
          : "HANDOVER_PATH_UNSUPPORTED",
      ],
    };
  },
};

function Issued() {
  const permissions = new Set(fixture.permissions);
  const access = customerAcceptanceAccess(permissions);
  return createElement(QuotationIssuedView, {
    terms,
    acceptance: access.read
      ? createElement(AcceptancePanel, {
          terms,
          mayAccept: access.accept,
          disabledReason: acceptDisabledReason(permissions),
          decisions,
          gateway,
          mayOpenMessages: true,
        })
      : undefined,
  });
}
function Picker() {
  const [value, setValue] = useState<CaseComponentInput[]>([]);
  return createElement(
    "div",
    { className: "p-4" },
    createElement(QuotationLinkPicker, {
      gateway: decisions,
      partyId: 3,
      value,
      onChange: (next: CaseComponentInput[]) => {
        window.__caLinked = next;
        setValue(next);
      },
      mayLink: fixture.permissions.includes("sales.quotation.view"),
    }),
  );
}
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
const entry =
  fixture.screen === "acceptance" ? "/customer-acceptances/55" : "/start";
const element = createElement(
  QueryClientProvider,
  { client },
  createElement(
    TooltipProvider,
    null,
    createElement(
      MemoryRouter,
      { initialEntries: [entry] },
      createElement(
        Routes,
        null,
        createElement(Route, {
          path: "/start",
          element: createElement(
            fixture.screen === "picker" ? Picker : Issued,
          ),
        }),
        createElement(Route, {
          path: "/customer-acceptances/:acceptance",
          element: createElement(CustomerAcceptanceScreen, {
            acceptanceId: 55,
            gateway,
          }),
        }),
      ),
    ),
  ),
);
createRoot(document.getElementById("root")!).render(element);
