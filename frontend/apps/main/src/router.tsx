import { BrowserRouter, Routes, Route } from 'react-router';
import { AppShell, DashboardScreen, LoginScreen, ProtectedRoute } from '@erp/core-ui';

export function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginScreen />} />
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <AppShell>
                <DashboardScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
      </Routes>
    </BrowserRouter>
  );
}
