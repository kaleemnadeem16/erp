import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Outlet, Route, Routes, useLocation } from "react-router";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { QuotationRecord } from "../../../../packages/core-ui/src/features/quotations/quotation-detail-screen";

configureCoreApi({ baseUrl: `${location.origin}/api` });
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
function ListFixture() {
  const location = useLocation();
  return createElement(
    "main",
    null,
    createElement("p", { "data-testid": "list" }, "List remains mounted"),
    createElement(
      "output",
      { "data-testid": "route" },
      `${location.pathname}${location.search}`,
    ),
    createElement(Outlet),
  );
}
const params = new URLSearchParams(location.search);
const props = {
  mayDetail: params.get("fixtureDuty") !== "prepare",
  mayReview: params.get("fixtureDuty") !== "view",
  presentation: "sheet" as const,
};
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
        createElement(
          Route,
          { path: "/quotations", element: createElement(ListFixture) },
          createElement(Route, {
            path: ":revision",
            element: createElement(QuotationRecord, {
              ...props,
              review: false,
            }),
          }),
          createElement(Route, {
            path: ":revision/review",
            element: createElement(QuotationRecord, { ...props, review: true }),
          }),
        ),
      ),
    ),
  ),
);
