import { BrowserRouter, Routes, Route } from 'react-router';
import {
  AppShell,
  CategoriesListScreen,
  CategoryCreateScreen,
  CategoryDetailScreen,
  CategoryEditScreen,
  DashboardScreen,
  ItemCreateScreen,
  ItemDetailScreen,
  ItemEditScreen,
  ItemSupplierCreateScreen,
  ItemSupplierDetailScreen,
  ItemSupplierEditScreen,
  ItemSuppliersListScreen,
  ItemsListScreen,
  LoginScreen,
  PhysicalCountCreateScreen,
  PhysicalCountDetailScreen,
  PhysicalCountsListScreen,
  ProtectedRoute,
  StockLedgerScreen,
  StockMoveDetailScreen,
  WarehouseCreateScreen,
  WarehouseDetailScreen,
  WarehouseEditScreen,
  WarehousesListScreen,
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
        <Route
          path="/warehouses"
          element={
            <ProtectedRoute>
              <AppShell>
                <WarehousesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/warehouses/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <WarehouseCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/warehouses/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <WarehouseDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/warehouses/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <WarehouseEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/categories"
          element={
            <ProtectedRoute>
              <AppShell>
                <CategoriesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/categories/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <CategoryCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/categories/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <CategoryDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/categories/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <CategoryEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/item-suppliers"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemSuppliersListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/item-suppliers/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemSupplierCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/item-suppliers/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemSupplierDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/item-suppliers/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemSupplierEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-moves"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockLedgerScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-moves/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockMoveDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/physical-counts"
          element={
            <ProtectedRoute>
              <AppShell>
                <PhysicalCountsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/physical-counts/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <PhysicalCountCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/physical-counts/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <PhysicalCountDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
      </Routes>
    </BrowserRouter>
  );
}
