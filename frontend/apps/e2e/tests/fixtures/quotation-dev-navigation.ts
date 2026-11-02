// Isolated browser fixture, never imported by the application bootstrap.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route, useLocation } from "react-router";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { QuotationsWorkspaceScreen } from "../../../../packages/core-ui/src/features/quotations/quotations-workspace-screen";
import { AppShell } from "../../../../packages/core-ui/src/layouts/app-shell";
import { AppearanceProvider } from "../../../../packages/core-ui/src/components/theme/appearance-provider";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";
const query = new URLSearchParams(location.search);
configureQuotationDevMode({
  development: query.get("build") === "dev",
  flag: query.get("flag") ?? undefined,
  hostname: location.hostname,
});
useAuthStore.setState({
  user: {
    id: 1,
    name: "Fixture User",
    email: "fixture@example.test",
    must_change_password: false,
    permissions:
      query.get("duty") === "none"
        ? []
        : [
            query.get("duty") === "view"
              ? "sales.quotation.view"
              : "sales.quotation.prepare",
          ],
  },
});
function Destination() {
  return createElement("h1", null, `Opened ${useLocation().pathname}`);
}
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client: new QueryClient() },
    createElement(
      AppearanceProvider,
      null,
      createElement(
        MemoryRouter,
        { initialEntries: ["/quotations"] },
        createElement(
          Routes,
          null,
          createElement(Route, {
            path: "/quotations",
            element: createElement(
              AppShell,
              null,
              createElement(QuotationsWorkspaceScreen),
            ),
          }),
          createElement(Route, {
            path: "/quotations/:revision",
            element: createElement(Destination),
          }),
          createElement(Route, {
            path: "/quotations/:revision/review",
            element: createElement(Destination),
          }),
        ),
      ),
    ),
  ),
);
