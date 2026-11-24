// Standalone fixture: never imported by application routes. Scripted in-memory gateway, no network.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes, useLocation } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { HandoverPreparationScreen } from "../../../../packages/core-ui/src/features/handover-preparation/handover-preparation-screen";
import type {
  HandoverPreparationGateway,
  PreparationInputs,
} from "../../../../packages/core-ui/src/features/handover-preparation/handover-preparation-contract";
import type { HandoverNavigationGateway } from "../../../../packages/core-ui/src/features/handover-release/handover-release-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface HpFixture {
  permissions: string[];
  /** A handover already exists when the page loads. */
  prepared?: boolean;
  noContacts?: boolean;
  noSites?: boolean;
  /** The first N prepare calls are refused with this status/reason; later calls succeed. */
  prepareRefusal?: { status: number; reason?: string; times?: number };
  /** The options read fails with this HTTP status. */
  optionsFailure?: number;
  /** The review (preview) call is refused with this status. */
  previewFailure?: number;
  /** Someone else prepares the handover between this person's review and confirm: the prepare is refused as already claimed and the status then shows it. */
  preparedMeanwhile?: boolean;
}
declare global {
  interface Window {
    __hp: HpFixture;
    __hpCalls: string[];
  }
}
const fixture = window.__hp;
window.__hpCalls = [];
configureQuotationDevMode({ development: true, flag: "1", hostname: "localhost" });
useAuthStore.setState({
  user: {
    id: 42,
    name: "Synthetic user",
    email: "u@fixture.test",
    must_change_password: false,
    permissions: fixture.permissions,
  },
});

let preparedNow = fixture.prepared ?? false;
let prepareCalls = 0;
let tokens = 0;
const CONTACTS = [
  { id: 5, name: "Amina Khan", email: "amina@example.test", phone: null },
  { id: 6, name: "Bilal Raza", email: null, phone: "+92 300 0000000" },
];
const SITES = [{ id: 8, name: "Karachi plant", address_line1: "12 Port Road", city: "Karachi", country_code: "PK" }];

const refused = (status: number, reason?: string) =>
  Object.assign(new Error("refused"), { status, body: reason ? { reason_code: reason } : {} });

function missingFor(i: PreparationInputs): string[] {
  const m: string[] = [];
  if (i.locationKind === "UNKNOWN") m.push("SERVICE_LOCATION_REQUIRED");
  if (i.contactId === null) m.push("SERVICE_CONTACT_REQUIRED");
  if (i.locationKind === "ON_SITE" && i.siteId === null) m.push("SERVICE_SITE_REQUIRED");
  return m;
}

const navigation: HandoverNavigationGateway = {
  status: async (acceptanceId) => {
    window.__hpCalls.push(`status:${acceptanceId}`);
    return {
      acceptance_id: acceptanceId,
      prepared: preparedNow,
      plan_id: preparedNow ? 12 : null,
      stage_id: preparedNow ? 9 : null,
      release_performed: false,
    };
  },
};
const gateway: HandoverPreparationGateway = {
  options: async (acceptanceId) => {
    window.__hpCalls.push(`options:${acceptanceId}`);
    if (fixture.optionsFailure) throw refused(fixture.optionsFailure);
    return {
      acceptance_id: acceptanceId,
      contacts: fixture.noContacts ? [] : CONTACTS,
      sites: fixture.noSites ? [] : SITES,
    };
  },
  preview: async (acceptanceId, inputs) => {
    window.__hpCalls.push(`preview:${acceptanceId}:${JSON.stringify(inputs)}`);
    if (fixture.previewFailure) throw refused(fixture.previewFailure);
    const missing = missingFor(inputs);
    tokens += 1;
    const contact = CONTACTS.find((c) => c.id === inputs.contactId) ?? null;
    const site = SITES.find((s) => s.id === inputs.siteId) ?? null;
    return {
      acceptance_id: acceptanceId,
      inputs: {
        path: "SERVICE_JOB",
        location_kind: inputs.locationKind,
        contact_id: inputs.contactId,
        site_id: inputs.siteId,
        instructions: inputs.instructions,
      },
      operational: { contact, site },
      readiness: {
        status: "NOT_READY",
        preparation_ready: missing.length === 0,
        missing,
        next_actions: missing.map((fact) => ({ fact, owner: "Sales", action: `Server action for ${fact}.` })),
      },
      preview_token: `token-${tokens}`,
      expires_in_seconds: 900,
    };
  },
  prepare: async ({ acceptanceId, inputs, previewToken, requestKey }) => {
    prepareCalls += 1;
    window.__hpCalls.push(`prepare:${acceptanceId}:${previewToken}:${requestKey}:${JSON.stringify(inputs)}`);
    if (fixture.preparedMeanwhile) {
      preparedNow = true;
      throw refused(409, "SCOPE_ALREADY_CLAIMED");
    }
    const r = fixture.prepareRefusal;
    if (r && prepareCalls <= (r.times ?? 1)) throw refused(r.status, r.reason);
    return { plan_id: 12, case_id: 3, replayed: prepareCalls > 1 };
  },
};

function PlanPage() {
  return createElement("p", { id: "plan-page" }, `Plan page ${useLocation().pathname}`);
}
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client: new QueryClient({ defaultOptions: { queries: { retry: false } } }) },
    createElement(
      TooltipProvider,
      null,
      createElement(
        MemoryRouter,
        { initialEntries: ["/customer-acceptances/55/prepare-handover"] },
        createElement(
          Routes,
          null,
          createElement(Route, {
            path: "/customer-acceptances/:id/prepare-handover",
            element: createElement(HandoverPreparationScreen, { acceptanceId: 55, gateway, navigation }),
          }),
          createElement(Route, { path: "/handover-plans/:plan", element: createElement(PlanPage) }),
          createElement(Route, { path: "/customer-acceptances/:id", element: createElement("h1", null, "Acceptance page") }),
        ),
      ),
    ),
  ),
);
