// Standalone intercepted fixture: never imported by application routes or used for permission handout.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, useLocation } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { ScreenTemplate } from "../../../../packages/core-ui/src/components/page/screen-template";
import {
  FamilyAccessWorkspace,
  QuotationFamilyAccessPanel,
} from "../../../../packages/core-ui/src/features/quotations/quotation-family-access-panel";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

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
