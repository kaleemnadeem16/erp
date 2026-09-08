import { BrowserRouter, Routes, Route } from 'react-router';
import {
  AppShell,
  DashboardScreen,
  ItemCreateScreen,
  ItemDetailScreen,
  ItemEditScreen,
  ItemsListScreen,
  LoginScreen,
  ProtectedRoute,
} from '@erp/core-ui';

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
        <Route
          path="/items"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/items/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/items/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/items/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
      </Routes>
    </BrowserRouter>
  );
}
