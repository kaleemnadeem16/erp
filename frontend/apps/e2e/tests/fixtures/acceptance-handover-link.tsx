// Standalone fixture: never imported by application routes. Scripted in-memory gateway, no network.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes, useLocation } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AcceptanceHandoverPanel } from "../../../../packages/core-ui/src/features/handover-release/handover-release-screen";
import type {
  HandoverNavigationGateway,
  HandoverStatus,
} from "../../../../packages/core-ui/src/features/handover-release/handover-release-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface AhlFixture {
  permissions: string[];
  /** What the server says about this acceptance's handover. */
  status?: Partial<HandoverStatus>;
  /** The accepted line is a service item (preparation is offered for these only). */
  serviceItem?: boolean;
  /** The status read fails with this HTTP status on the first call. */
  failure?: number;
}
declare global {
  interface Window {
    __ahl: AhlFixture;
    __ahlCalls: string[];
  }
}
const fixture = window.__ahl;
window.__ahlCalls = [];
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
let calls = 0;
const gateway: HandoverNavigationGateway = {
  status: async (acceptanceId) => {
    calls += 1;
    window.__ahlCalls.push(`status:${acceptanceId}`);
    if (fixture.failure && calls === 1)
      throw Object.assign(new Error("failed"), { status: fixture.failure, body: {} });
    return {
      acceptance_id: acceptanceId,
      prepared: true,
      plan_id: 12,
      stage_id: 9,
      release_performed: false,
      ...fixture.status,
    };
  },
};
function Target() {
  return createElement("p", { id: "target" }, `Opened ${useLocation().pathname}`);
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
        { initialEntries: ["/customer-acceptances/55"] },
        createElement(
          Routes,
          null,
          createElement(Route, {
            path: "/customer-acceptances/:id",
            element: createElement(
              "div",
              null,
              createElement("h1", null, "Customer acceptance 55"),
              createElement(AcceptanceHandoverPanel, { acceptanceId: 55, gateway, serviceItem: fixture.serviceItem ?? false }),
            ),
          }),
          createElement(Route, { path: "/customer-acceptances/:id/prepare-handover", element: createElement(Target) }),
          createElement(Route, { path: "/handover-plans/:plan", element: createElement(Target) }),
        ),
      ),
    ),
  ),
);
