import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { QuotationCreateForm } from "../../../../packages/core-ui/src/features/quotations/quotation-create-screen";
import { QuotationEditForm } from "../../../../packages/core-ui/src/features/quotations/quotation-edit-screen";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";
configureCoreApi({ baseUrl: `${location.origin}/api` });
useAuthStore.getState().setUser({
  id: 7,
  name: "Test",
  email: "test@example.invalid",
  must_change_password: false,
  permissions: ["sales.quotation.prepare"],
});
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client },
    createElement(
      MemoryRouter,
      { initialEntries: [location.pathname] },
      createElement(
        Routes,
        null,
        createElement(Route, {
          path: "/quotations/new",
          element: createElement(QuotationCreateForm),
        }),
        createElement(Route, {
          path: "/quotations/12/edit",
          element: createElement(QuotationEditForm, { id: 12 }),
        }),
        createElement(Route, {
          path: "/quotations",
          element: createElement("p", null, "Quotation workspace"),
        }),
      ),
    ),
  ),
);
