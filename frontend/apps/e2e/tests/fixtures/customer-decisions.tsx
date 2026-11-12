// Standalone fixture: never imported by application routes. Scripted in-memory gateway, no network.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Outlet, Route, Routes } from "react-router";
import {
  configureCoreApi,
  setAuthToken,
} from "../../../../packages/core-api/src/index";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CustomerDecisionCaseRoute } from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-routes";
import { CustomerDecisionListScreen } from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-list-screen";
import type {
  CaseDetail,
  CaseSummary,
  CustomerDecisionGateway,
} from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface CdFixture {
  screen: "list" | "case";
  permissions: string[];
  /** The first add-observation call fails as a moved case (409 DECISION_CASE_STALE). */
  stale?: boolean;
  /** The first add-observation call fails like a lost connection (a retry must reuse the key). */
  flaky?: boolean;
  empty?: boolean;
}
declare global {
  interface Window {
    __cd: CdFixture;
    __cdWrites: string[];
    __cdLists: string[];
  }
}
const fixture = window.__cd;
window.__cdWrites = [];
window.__cdLists = [];
// Lookups (the customer filter) go through the generated client; the spec answers them with page.route.
configureCoreApi({ baseUrl: "http://fixture.test/api" });
// A bearer token, because this fixture page may not read document.cookie (the cookie session path would throw).
setAuthToken("fixture");
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

const obs = (
  id: number,
  over: Partial<CaseDetail["observations"][number]> = {},
): CaseDetail["observations"][number] => ({
  id,
  predecessor_id: null,
  is_current: true,
  source_kind: "PURCHASE_ORDER",
  external_reference: "PO-ALPHA-0417",
  source_version: null,
  customer_representative_snapshot: "Procurement, Alpha Traders",
  authority_verification_basis: "DOCUMENT_ON_FILE",
  occurrence_precision: "DATE",
  occurred_at: "2026-11-10T00:00:00+05:00",
  occurred_until: null,
  source_timezone: "Asia/Karachi",
  factual_description:
    "A purchase order arrived by email; quantities were not checked.",
  observation_state: "OBSERVED",
  recorded_by: 21,
  recorded_by_name: "Demo Recorder",
  recorded_at: "2026-11-10T09:00:00+05:00",
  ...over,
});
let detail: CaseDetail = {
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
  components: [
    {
      id: 1,
      intent_identity: "whole-quotation",
      family_id: 12,
      original_scope_anchor_id: 30,
      recorded_at: "2026-11-10T08:00:00+05:00",
    },
  ],
  hidden_component_count: 1,
  observations: [
    obs(1, { is_current: false }),
    obs(2, {
      predecessor_id: 1,
      source_kind: "VERBAL",
      authority_verification_basis: "UNVERIFIED",
      occurrence_precision: "UNKNOWN",
      occurred_at: null,
      source_timezone: null,
      external_reference: null,
      factual_description:
        "A caller said the order covers only the first line.",
    }),
  ],
};
const summary = (): CaseSummary => ({
  id: 7,
  party_id: 3,
  party_name: "Alpha Traders",
  intake_identity: detail.case.intake_identity,
  generation: detail.case.generation,
  component_count: 1,
  observation_count: detail.observations.length,
  recorded_by: 21,
  recorded_at: "2026-11-10T08:00:00+05:00",
});
let failedOnce = false;
const gateway: CustomerDecisionGateway = {
  list: async (params) => {
    window.__cdLists.push(
      `search=${params.search ?? ""}&party=${params.partyId ?? ""}`,
    );
    const rows = fixture.empty
      ? []
      : [summary()].filter(
          (r) =>
            (!params.partyId || r.party_id === params.partyId) &&
            (!params.search ||
              r.intake_identity
                .toLowerCase()
                .includes(params.search.toLowerCase())),
        );
    return {
      data: rows,
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: params.perPage,
        total: rows.length,
        from: rows.length ? 1 : null,
        to: rows.length || null,
      },
    };
  },
  detail: async () => detail,
  quotationsFor: async () => [],
  quotationScopes: async () => {
    throw new Error("not used here");
  },
  open: async ({ body, requestKey }) => {
    window.__cdWrites.push(
      `open:${body.party_id}:${body.intake_identity}:${requestKey}`,
    );
    return { case_id: 8, generation: 0, evidence_id: null, replayed: false };
  },
  addObservation: async ({ caseId, expectedGeneration, body, requestKey }) => {
    window.__cdWrites.push(
      `add:${caseId}:${expectedGeneration}:${body.source_kind}:${body.occurrence_precision}:${body.predecessor_id ?? "-"}:${requestKey}`,
    );
    if (fixture.stale && !failedOnce) {
      failedOnce = true;
      detail = {
        ...detail,
        case: { ...detail.case, generation: detail.case.generation + 1 },
      };
      throw Object.assign(new Error("stale"), {
        status: 409,
        body: { reason_code: "DECISION_CASE_STALE" },
      });
    }
    if (fixture.flaky && !failedOnce) {
      failedOnce = true;
      throw Object.assign(new Error("network"), { status: 503, body: {} });
    }
    const id = detail.observations.length + 1;
    detail = {
      ...detail,
      case: { ...detail.case, generation: detail.case.generation + 1 },
      observations: [
        ...detail.observations,
        obs(id, { factual_description: body.factual_description }),
      ],
    };
    return {
      case_id: caseId,
      generation: detail.case.generation,
      evidence_id: id,
      replayed: false,
    };
  },
};
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
const element = createElement(
  QueryClientProvider,
  { client },
  createElement(
    TooltipProvider,
    null,
    createElement(
      MemoryRouter,
      {
        initialEntries: [
          fixture.screen === "list"
            ? "/customer-decisions"
            : "/customer-decisions/7",
        ],
      },
      createElement(
        Routes,
        null,
        createElement(
          Route,
          {
            path: "/customer-decisions",
            element: createElement(
              "div",
              null,
              createElement(CustomerDecisionListScreen, { gateway }),
              createElement(Outlet),
            ),
          },
          createElement(Route, {
            path: ":case",
            element: createElement(CustomerDecisionCaseRoute, {
              gateway,
            }),
          }),
        ),
      ),
    ),
  ),
);
createRoot(document.getElementById("root")!).render(element);
