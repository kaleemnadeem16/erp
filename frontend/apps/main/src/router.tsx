import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import {
  AppShell,
  AcceptInvitationScreen,
  ApplicationRateCreateScreen,
  ApplicationRateEditScreen,
  ApplicationRatesListScreen,
  AssemblyCreateScreen,
  AssemblyEditScreen,
  AssembliesListScreen,
  AssemblyGroupCreateScreen,
  AssemblyGroupEditScreen,
  AssemblyGroupsListScreen,
  CategoriesListScreen,
  CategoryCreateScreen,
  CategoryDetailScreen,
  CategoryEditScreen,
  DashboardScreen,
  EstimatingSettingsScreen,
  ApprovalsInboxScreen,
  ApprovalsSettingsScreen,
  MaterialSelectionSettingsScreen,
  CompanySettingsScreen,
  NumberingEditorScreen,
  NumberingSettingsScreen,
  MaterialVarianceSettingsScreen,
  FormulationCreateScreen,
  FormulationEditScreen,
  FormulationsListScreen,
  GoodsReceiptCreateScreen,
  GoodsReceiptEditScreen,
  SuppliersListScreen,
  SupplierDetailScreen,
  SupplierFormScreen,
  CustomFieldsSettingsScreen,
  GoodsReceiptDetailScreen,
  GoodsReceiptsListScreen,
  InventoryCutoverCreateScreen,
  InventoryCutoverDetailScreen,
  InventoryCutoversListScreen,
  InventoryOverviewScreen,
  ItemCreateScreen,
  ItemDetailScreen,
  ItemEditScreen,
  ItemQuantityUnitsListScreen,
  ItemSupplierCreateScreen,
  ItemSupplierDetailScreen,
  ItemSupplierEditScreen,
  ItemSuppliersListScreen,
  ItemsListScreen,
  LoginScreen,
  PasswordChangeRequiredScreen,
  MaterialPreviewScreen,
  MembersSettingsScreen,
  NegativeStockOverrideCreateScreen,
  NegativeStockOverrideDetailScreen,
  NegativeStockOverridesListScreen,
  PhysicalCountCreateScreen,
  PhysicalCountDetailScreen,
  PhysicalCountsListScreen,
  StockScrapDetailScreen,
  StockScrapFormScreen,
  StockScrapsListScreen,
  SupplierReturnDetailScreen,
  SupplierReturnFormScreen,
  SupplierReturnsListScreen,
  PurchaseOrdersListScreen,
  PurchaseOrderDetailScreen,
  PurchaseOrderCreateScreen,
  PurchaseOrderEditScreen,
  InventorySettingsScreen,
  AccountSettingsScreen,
  AppearanceSettingsScreen,
  PrintingSettingsScreen,
  ProtectedRoute,
  PermissionRoute,
  PurchasingSettingsScreen,
  RateDimensionCreateScreen,
  RateDimensionEditScreen,
  RateDimensionsListScreen,
  RecalculationRequestCreateScreen,
  RecalculationRequestDetailScreen,
  OrganisationSettingsScreen,
  RecalculationRequestsListScreen,
  ResourceRatesListScreen,
  RolesSettingsScreen,
  StockLedgerScreen,
  StockMoveDetailScreen,
  WarehouseCreateScreen,
  WarehouseDetailScreen,
  WarehouseEditScreen,
  WarehousesListScreen,
} from "@erp/core-ui";
import { presentation, purchaseOrderOptionsStyle } from "./presentation";

const purchaseOrderCreateAsSheet = presentation.purchaseOrderCreate === "sheet";
const purchaseOrderDetailAsSheet = presentation.purchaseOrderDetail === "sheet";
const purchaseOrderEditAsSheet = presentation.purchaseOrderEdit === "sheet";
const supplierCreateAsSheet = presentation.supplierCreate === "sheet";
const supplierDetailAsSheet = presentation.supplierDetail === "sheet";
const supplierEditAsSheet = presentation.supplierEdit === "sheet";
const goodsReceiptCreateAsSheet = presentation.goodsReceiptCreate === "sheet";
const goodsReceiptDetailAsSheet = presentation.goodsReceiptDetail === "sheet";
const goodsReceiptEditAsSheet = presentation.goodsReceiptEdit === "sheet";
const itemSupplierCreateAsSheet = presentation.itemSupplierCreate === "sheet";
const itemSupplierDetailAsSheet = presentation.itemSupplierDetail === "sheet";
const itemSupplierEditAsSheet = presentation.itemSupplierEdit === "sheet";

export function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginScreen />} />
        <Route
          path="/change-password"
          element={<PasswordChangeRequiredScreen />}
        />
        <Route path="/accept-invitation" element={<AcceptInvitationScreen />} />
        <Route
          path="/reset-password"
          element={<AcceptInvitationScreen mode="reset" />}
        />
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
          path="/item-quantity-units"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemQuantityUnitsListScreen />
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
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {itemSupplierCreateAsSheet && (
            <Route
              path="new"
              element={<ItemSupplierCreateScreen presentation="sheet" />}
            />
          )}
          {itemSupplierDetailAsSheet && (
            <Route
              path=":id"
              element={<ItemSupplierDetailScreen presentation="sheet" />}
            />
          )}
          {itemSupplierEditAsSheet && (
            <Route
              path=":id/edit"
              element={<ItemSupplierEditScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!itemSupplierCreateAsSheet && (
          <Route
            path="/item-suppliers/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemSupplierCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!itemSupplierDetailAsSheet && (
          <Route
            path="/item-suppliers/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemSupplierDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!itemSupplierEditAsSheet && (
          <Route
            path="/item-suppliers/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemSupplierEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
          path="/rate-dimensions"
          element={
            <ProtectedRoute>
              <AppShell>
                <RateDimensionsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/rate-dimensions/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <RateDimensionCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/rate-dimensions/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <RateDimensionEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/application-rates"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApplicationRatesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/application-rates/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApplicationRateCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/application-rates/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApplicationRateEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/formulations"
          element={
            <ProtectedRoute>
              <AppShell>
                <FormulationsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/formulations/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <FormulationCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/formulations/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <FormulationEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assembly-groups"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyGroupsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assembly-groups/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyGroupCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assembly-groups/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyGroupEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assemblies"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssembliesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assemblies/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyCreateScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assemblies/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyEditScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/resource-rates"
          element={
            <ProtectedRoute>
              <AppShell>
                <ResourceRatesListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/material-preview"
          element={
            <ProtectedRoute>
              <AppShell>
                <MaterialPreviewScreen />
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
            <Route
              path="new"
              element={
                <PurchaseOrderCreateScreen
                  presentation="sheet"
                  optionsStyle={purchaseOrderOptionsStyle}
                />
              }
            />
          )}
          {purchaseOrderDetailAsSheet && (
            <Route
              path=":id"
              element={<PurchaseOrderDetailScreen presentation="sheet" />}
            />
          )}
          {purchaseOrderEditAsSheet && (
            <Route
              path=":id/edit"
              element={
                <PurchaseOrderEditScreen
                  presentation="sheet"
                  optionsStyle={purchaseOrderOptionsStyle}
                />
              }
            />
          )}
        </Route>
        {!purchaseOrderCreateAsSheet && (
          <Route
            path="/purchase-orders/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PurchaseOrderCreateScreen
                    presentation="page"
                    optionsStyle={purchaseOrderOptionsStyle}
                  />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/suppliers"
          element={
            <ProtectedRoute>
              <AppShell>
                <SuppliersListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {supplierCreateAsSheet && (
            <Route
              path="new"
              element={<SupplierFormScreen presentation="sheet" />}
            />
          )}
          {supplierDetailAsSheet && (
            <Route
              path=":id"
              element={<SupplierDetailScreen presentation="sheet" />}
            />
          )}
          {supplierEditAsSheet && (
            <Route
              path=":id/edit"
              element={<SupplierFormScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!supplierCreateAsSheet && (
          <Route
            path="/suppliers/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!supplierDetailAsSheet && (
          <Route
            path="/suppliers/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!supplierEditAsSheet && (
          <Route
            path="/suppliers/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/settings/custom-fields"
          element={
            <ProtectedRoute>
              <AppShell>
                <CustomFieldsSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/goods-receipts"
          element={
            <ProtectedRoute>
              <AppShell>
                <GoodsReceiptsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {goodsReceiptCreateAsSheet && (
            <Route
              path="new"
              element={<GoodsReceiptCreateScreen presentation="sheet" />}
            />
          )}
          {goodsReceiptDetailAsSheet && (
            <Route
              path=":id"
              element={<GoodsReceiptDetailScreen presentation="sheet" />}
            />
          )}
          {goodsReceiptEditAsSheet && (
            <Route
              path=":id/edit"
              element={<GoodsReceiptEditScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!goodsReceiptCreateAsSheet && (
          <Route
            path="/goods-receipts/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <GoodsReceiptCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!goodsReceiptDetailAsSheet && (
          <Route
            path="/goods-receipts/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <GoodsReceiptDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!goodsReceiptEditAsSheet && (
          <Route
            path="/goods-receipts/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <GoodsReceiptEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
        {!purchaseOrderEditAsSheet && (
          <Route
            path="/purchase-orders/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PurchaseOrderEditScreen
                    presentation="page"
                    optionsStyle={purchaseOrderOptionsStyle}
                  />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
          path="/settings/members"
          element={
            <ProtectedRoute>
              <AppShell>
                <PermissionRoute permission="administration.member.view">
                  <MembersSettingsScreen />
                </PermissionRoute>
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/roles"
          element={
            <ProtectedRoute>
              <AppShell>
                <PermissionRoute permission="administration.role.view">
                  <RolesSettingsScreen />
                </PermissionRoute>
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/organisation"
          element={
            <ProtectedRoute>
              <AppShell>
                <PermissionRoute permission="administration.organisation.view">
                  <OrganisationSettingsScreen />
                </PermissionRoute>
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
          path="/supplier-returns"
          element={
            <ProtectedRoute>
              <AppShell>
                <SupplierReturnsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/supplier-returns/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <SupplierReturnFormScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/supplier-returns/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <SupplierReturnFormScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/supplier-returns/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <SupplierReturnDetailScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-scraps"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockScrapsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-scraps/new"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockScrapFormScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-scraps/:id/edit"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockScrapFormScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/stock-scraps/:id"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockScrapDetailScreen />
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
          path="/settings/estimating"
          element={
            <ProtectedRoute>
              <AppShell>
                <EstimatingSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/material-selection"
          element={
            <ProtectedRoute>
              <AppShell>
                <MaterialSelectionSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/company"
          element={
            <ProtectedRoute>
              <AppShell>
                <CompanySettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        {/* The editor is a child route so the list stays mounted (dimmed) behind its side sheet. */}
        <Route
          path="/settings/document-numbering"
          element={
            <ProtectedRoute>
              <AppShell>
                <NumberingSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        >
          <Route path=":type" element={<NumberingEditorScreen />} />
        </Route>
        <Route
          path="/settings/material-variance"
          element={
            <ProtectedRoute>
              <AppShell>
                <MaterialVarianceSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/approvals"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApprovalsSettingsScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/approvals"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApprovalsInboxScreen />
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
