// REAL-BACKEND fixture (never imported by the application): mounts the production React screens, with their LIVE gateways and the generated client, on a page served from the
// real backend's own origin. Nothing is intercepted: every request reaches the actual Laravel application. Reusable for any journey: add a <Route> below.
// Configuration arrives in window.__rb: { baseUrl, token, permissions, path } (see tests/real-backend/mount.ts).
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { configureCoreApi, setAuthToken } from "../../../../packages/core-api/src";
import { CustomerAcceptanceRoute } from "../../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-routes";
import { HandoverPreparationRoute } from "../../../../packages/core-ui/src/features/handover-preparation/handover-preparation-routes";
import { HandoverReleaseRoute } from "../../../../packages/core-ui/src/features/handover-release/handover-release-routes";
import { JobDetailScreen } from "../../../../packages/core-ui/src/features/jobs/job-detail-screen";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

declare global {
  interface Window {
    __rb: { baseUrl: string; token: string; permissions: string[]; path: string; userId: number };
  }
}
const rb = window.__rb;
configureCoreApi({ baseUrl: rb.baseUrl });
setAuthToken(rb.token);
// The quotation-family screens are off in production builds whatever a person holds; this harness (and only this) turns the development presentation on, exactly as the unit fixtures do.
configureQuotationDevMode({ development: true, flag: "1", hostname: "localhost" });
useAuthStore.setState({
  user: { id: rb.userId, name: "Real backend user", email: "u@e2e.invalid", must_change_password: false, permissions: rb.permissions },
});

createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client: new QueryClient({ defaultOptions: { queries: { retry: false } } }) },
    createElement(
      TooltipProvider,
      null,
      createElement(
        MemoryRouter,
        { initialEntries: [rb.path] },
        createElement(
          Routes,
          null,
          createElement(Route, { path: "/customer-acceptances/:acceptance", element: createElement(CustomerAcceptanceRoute) }),
          createElement(Route, { path: "/customer-acceptances/:acceptance/prepare-handover", element: createElement(HandoverPreparationRoute) }),
          createElement(Route, { path: "/handover-plans/:plan", element: createElement(HandoverReleaseRoute) }),
          createElement(Route, { path: "/jobs/:id", element: createElement(JobDetailScreen, { presentation: "page" }) }),
        ),
      ),
    ),
  ),
);
