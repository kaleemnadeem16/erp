import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import { configureCoreApi } from "../../../../packages/core-api/src/mutator/fetch-client";
import { JobOperationScreen } from "../../../../packages/core-ui/src/features/jobs/job-operation-screen";
import { JobsListScreen } from "../../../../packages/core-ui/src/features/jobs/jobs-list-screen";
import { JobDetailScreen } from "../../../../packages/core-ui/src/features/jobs/job-detail-screen";
import { AppShell } from "../../../../packages/core-ui/src/layouts/app-shell";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";
import { useAppearanceStore } from "../../../../packages/core-ui/src/stores/appearance-store";
import { AppearanceProvider } from "../../../../packages/core-ui/src/components/theme/appearance-provider";

configureCoreApi({ baseUrl: "/api" });
useAppearanceStore
  .getState()
  .setUser({
    mode:
      new URLSearchParams(location.search).get("mode") === "dark"
        ? "dark"
        : "light",
  });
const duties =
  new URLSearchParams(location.search)
    .get("duties")
    ?.split(",")
    .filter(Boolean) ?? [];
useAuthStore.setState({
  user: {
    id: 1,
    name: "Operator",
    email: "operator@example.test",
    must_change_password: false,
    permissions: duties,
  },
});
createRoot(document.getElementById("root")!).render(
  <QueryClientProvider
    client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
  >
    <AppearanceProvider>
      <BrowserRouter>
        <Routes>
          <Route
            path="/job-operations"
            element={
              <AppShell>
                <JobOperationScreen />
              </AppShell>
            }
          />
          <Route
            path="/jobs"
            element={
              <AppShell>
                <JobsListScreen />
                <Outlet />
              </AppShell>
            }
          >
            <Route path=":id" element={<JobDetailScreen />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AppearanceProvider>
  </QueryClientProvider>,
);
