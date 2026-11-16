// Test-only host. Production imports the same screens through the protected app router.
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import { configureCoreApi } from "../../../../packages/core-api/src/mutator/fetch-client";
import { JobsListScreen } from "../../../../packages/core-ui/src/features/jobs/jobs-list-screen";
import { JobDetailScreen } from "../../../../packages/core-ui/src/features/jobs/job-detail-screen";
import { AppShell } from "../../../../packages/core-ui/src/layouts/app-shell";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";
import { AppearanceProvider } from "../../../../packages/core-ui/src/components/theme/appearance-provider";
configureCoreApi({ baseUrl: "/api" });
useAuthStore.setState({
  user: {
    id: 1,
    name: "Reader",
    email: "reader@example.test",
    must_change_password: false,
    permissions: location.search.includes("denied=1") ? [] : ["job.view"],
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
