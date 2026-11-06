// Standalone intercepted fixture: never imported by application routes or used for permission handout.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Routes, Route, useLocation } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { ScreenTemplate } from "../../../../packages/core-ui/src/components/page/screen-template";
import {
  FamilyAccessWorkspace,
  QuotationFamilyAccessPanel,
} from "../../../../packages/core-ui/src/features/quotations/quotation-family-access-panel";
import { QuotationDetailScreen } from "../../../../packages/core-ui/src/features/quotations/quotation-detail-screen";
import { QuotationListPanel } from "../../../../packages/core-ui/src/features/quotations/quotation-list-panel";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

const entry = new URLSearchParams(location.search).get("entry");
if (entry === "record" || entry === "sheet")
  configureQuotationDevMode({
    development: true,
    flag: "1",
    hostname: "localhost",
  });
configureCoreApi({ baseUrl: `${location.origin}/api` });
useAuthStore.setState({
  user: {
    id: 42,
    name: "Synthetic governor",
    email: "governor@fixture.test",
    roles: [],
    permissions: [
      "sales.quotation.view",
      "sales.quotation.prepare",
      "sales.quotation.assign",
      "sales.quotation.access.delegate",
      "sales.quotation.access.revoke",
    ],
  },
});
const client = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});
function Fixture() {
  const current = useLocation();
  if (entry === "record" || entry === "sheet")
    return createElement(
      "div",
      null,
      createElement(
        "button",
        {
          onClick: () =>
            void client.invalidateQueries({
              queryKey: ["quotation-family-access"],
            }),
        },
        "Refresh fixture authority",
      ),
      entry === "sheet"
        ? createElement(QuotationListPanel, {
            revisionId: 12,
            onClose: () => {},
          })
        : createElement(
            Routes,
            null,
            createElement(Route, {
              path: "/quotations/:revision",
              element: createElement(QuotationDetailScreen),
            }),
          ),
    );
  const publicEntry =
    new URLSearchParams(location.search).get("entry") === "public";
  return createElement(
    ScreenTemplate,
    { kind: "detail", className: "space-y-4 p-4" },
    createElement(
      "h1",
      { className: "text-xl font-semibold" },
      "Quotation access fixture",
    ),
    createElement(
      "output",
      { "data-testid": "location", className: "sr-only" },
      current.search,
    ),
    publicEntry
      ? createElement(QuotationFamilyAccessPanel, { familyId: 11 })
      : createElement(FamilyAccessWorkspace, { familyId: 11 }),
  );
}
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client },
    createElement(
      MemoryRouter,
      { initialEntries: [location.pathname + location.search] },
      createElement(Fixture),
    ),
  ),
);
