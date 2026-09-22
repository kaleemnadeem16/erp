import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import {
  AppShell,
  CategoriesListScreen,
  CategoryCreateScreen,
  CategoryDetailScreen,
  CategoryEditScreen,
  DashboardScreen,
  GoodsReceiptCreateScreen,
  GoodsReceiptDetailScreen,
  GoodsReceiptsListScreen,
  InventoryCutoverCreateScreen,
  InventoryCutoverDetailScreen,
  InventoryCutoversListScreen,
  InventoryOverviewScreen,
  ItemCreateScreen,
  ItemDetailScreen,
  ItemEditScreen,
  ItemSupplierCreateScreen,
  ItemSupplierDetailScreen,
  ItemSupplierEditScreen,
  ItemSuppliersListScreen,
  ItemsListScreen,
  LoginScreen,
  NegativeStockOverrideCreateScreen,
  NegativeStockOverrideDetailScreen,
  NegativeStockOverridesListScreen,
  PhysicalCountCreateScreen,
  PhysicalCountDetailScreen,
  PhysicalCountsListScreen,
  PurchaseOrdersListScreen,
  PurchaseOrderDetailScreen,
  PurchaseOrderCreateScreen,
  PurchaseOrderEditScreen,
  InventorySettingsScreen,
  AccountSettingsScreen,
  AppearanceSettingsScreen,
  PrintingSettingsScreen,
  ProtectedRoute,
  PurchasingSettingsScreen,
  RecalculationRequestCreateScreen,
  RecalculationRequestDetailScreen,
  RecalculationRequestsListScreen,
  StockLedgerScreen,
  StockMoveDetailScreen,
  WarehouseCreateScreen,
  WarehouseDetailScreen,
  WarehouseEditScreen,
  WarehousesListScreen,
} from "@erp/core-ui";
import { presentation } from "./presentation";

const purchaseOrderCreateAsSheet = presentation.purchaseOrderCreate === "sheet";
const purchaseOrderDetailAsSheet = presentation.purchaseOrderDetail === "sheet";

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
        {/* Sheet presentations are nested so the list stays mounted (and visible,
            dimmed) behind the side sheet the child route renders through
            <Outlet />. Which screens are sheets: ./presentation.ts */}
        <Route
          path="/purchase-orders"
          element={
            <ProtectedRoute>
              <AppShell>
                <PurchaseOrdersListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {purchaseOrderCreateAsSheet && (
            <Route path="new" element={<PurchaseOrderCreateScreen presentation="sheet" />} />
          )}
          {purchaseOrderDetailAsSheet && (
            <Route path=":id" element={<PurchaseOrderDetailScreen presentation="sheet" />} />
          )}
        </Route>
        {!purchaseOrderCreateAsSheet && (
          <Route
            path="/purchase-orders/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PurchaseOrderCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/goods-receipts"
          element={
            <ProtectedRoute>
              <AppShell>
                <GoodsReceiptsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/goods-receipts/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <GoodsReceiptCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/goods-receipts/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <GoodsReceiptDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        {!purchaseOrderDetailAsSheet && (
          <Route
            path="/purchase-orders/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PurchaseOrderDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/purchase-orders/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <PurchaseOrderEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/account"
          element={
            <ProtectedRoute>
              <AppShell>
                <AccountSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/appearance"
          element={
            <ProtectedRoute>
              <AppShell>
                <AppearanceSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/printing"
          element={
            <ProtectedRoute>
              <AppShell>
                <PrintingSettingsScreen />
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
        <Route
          path="/inventory-cutovers"
          element={
            <ProtectedRoute>
              <AppShell>
                <InventoryCutoversListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/inventory-cutovers/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <InventoryCutoverCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/inventory-cutovers/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <InventoryCutoverDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/negative-stock-overrides"
          element={
            <ProtectedRoute>
              <AppShell>
                <NegativeStockOverridesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/negative-stock-overrides/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <NegativeStockOverrideCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/negative-stock-overrides/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <NegativeStockOverrideDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/recalculation-requests"
          element={
            <ProtectedRoute>
              <AppShell>
                <RecalculationRequestsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/recalculation-requests/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <RecalculationRequestCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/recalculation-requests/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <RecalculationRequestDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/purchasing"
          element={
            <ProtectedRoute>
              <AppShell>
                <PurchasingSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/inventory"
          element={
            <ProtectedRoute>
              <AppShell>
                <InventorySettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/inventory-overview"
          element={
            <ProtectedRoute>
              <AppShell>
                <InventoryOverviewScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
      </Routes>
    </BrowserRouter>
  );
}
