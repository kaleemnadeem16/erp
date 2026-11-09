// Standalone intercepted fixture: mounts the high-impact detail screens against faked API replies. Never imported by the app.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Routes, Route } from "react-router";
import { QueryClientProvider } from "@tanstack/react-query";
import { configureCoreApi } from "../../../../packages/core-api/src/index";
import { createAppQueryClient } from "../../../../packages/core-ui/src/lib/query-client";
import { InventoryCutoverDetailScreen } from "../../../../packages/core-ui/src/features/inventory-cutovers/inventory-cutover-detail-screen";
import { PhysicalCountDetailScreen } from "../../../../packages/core-ui/src/features/physical-counts/physical-count-detail-screen";
import { RecalculationRequestDetailScreen } from "../../../../packages/core-ui/src/features/recalculation-requests/recalculation-request-detail-screen";
import { MaterialPreviewScreen } from "../../../../packages/core-ui/src/features/material-preview/material-preview-screen";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

configureCoreApi({ baseUrl: `${location.origin}/api` });
const permissions = new URLSearchParams(location.search).get("permissions")?.split(",") ?? [
  "inventory.cutover.approve",
  "inventory.cutover.commit",
  "inventory.cutover.finalise",
  "inventory.physical_count.create",
  "inventory.physical_count.post",
  "inventory.stock.view",
  "inventory.recalculation.execute",
  "estimating.material_preview.run",
];
useAuthStore.setState({
  user: { id: 42, name: "Fixture user", email: "fixture@example.test", roles: [], permissions } as never,
});
const client = createAppQueryClient();
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
        createElement(Route, { path: "/inventory-cutovers/:id", element: createElement(InventoryCutoverDetailScreen, { presentation: "page" }) }),
        createElement(Route, { path: "/physical-counts/:id", element: createElement(PhysicalCountDetailScreen, { presentation: "page" }) }),
        createElement(Route, { path: "/recalculation-requests/:id", element: createElement(RecalculationRequestDetailScreen, { presentation: "page" }) }),
        createElement(Route, { path: "/material-preview", element: createElement(MaterialPreviewScreen) }),
      ),
    ),
  ),
);
