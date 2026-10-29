// Standalone fixture only: never imported by the application or its route registry.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { QuotationRecord } from "../../../../packages/core-ui/src/features/quotations/quotation-detail-screen";

configureCoreApi({ baseUrl: `${location.origin}/api` });
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
const duty = new URLSearchParams(location.search).get("fixtureDuty");
const props = { mayDetail: duty !== "prepare", mayReview: duty !== "view" };
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client },
    createElement(
      MemoryRouter,
      { initialEntries: [location.pathname + location.search] },
      createElement(
        Routes,
        null,
        createElement(Route, {
          path: "/quotations/:revision",
          element: createElement(QuotationRecord, { ...props, review: false }),
        }),
        createElement(Route, {
          path: "/quotations/:revision/review",
          element: createElement(QuotationRecord, { ...props, review: true }),
        }),
      ),
    ),
  ),
);
