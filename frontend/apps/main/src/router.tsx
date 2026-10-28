import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import {
  AppShell,
  QuotationsWorkspaceScreen,
  QuotationDetailScreen,
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
  PackConversionDetailScreen,
  PackConversionFormScreen,
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
  LeadDetailScreen,
  LeadFormScreen,
  LeadsListScreen,
  OpportunitiesListScreen,
  OpportunityDetailScreen,
  OpportunityFormScreen,
  SalesStagesScreen,
  StageDetailScreen,
  StageFormScreen,
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
  SettingsIndexScreen,
  NumbersAndRoundingScreen,
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
const opportunityCreateAsSheet = presentation.opportunityCreate === "sheet";
const opportunityDetailAsSheet = presentation.opportunityDetail === "sheet";
const opportunityEditAsSheet = presentation.opportunityEdit === "sheet";
const leadCreateAsSheet = presentation.leadCreate === "sheet";
const leadDetailAsSheet = presentation.leadDetail === "sheet";
const stageCreateAsSheet = presentation.stageCreate === "sheet";
const stageDetailAsSheet = presentation.stageDetail === "sheet";
const stageEditAsSheet = presentation.stageEdit === "sheet";
const packCreateAsSheet = presentation.packConversionCreate === "sheet";
const packDetailAsSheet = presentation.packConversionDetail === "sheet";
const packEditAsSheet = presentation.packConversionEdit === "sheet";
const supplierEditAsSheet = presentation.supplierEdit === "sheet";
const goodsReceiptCreateAsSheet = presentation.goodsReceiptCreate === "sheet";
const goodsReceiptDetailAsSheet = presentation.goodsReceiptDetail === "sheet";
const goodsReceiptEditAsSheet = presentation.goodsReceiptEdit === "sheet";
const itemSupplierCreateAsSheet = presentation.itemSupplierCreate === "sheet";
const itemSupplierDetailAsSheet = presentation.itemSupplierDetail === "sheet";
const itemSupplierEditAsSheet = presentation.itemSupplierEdit === "sheet";
const itemCreateAsSheet = presentation.itemCreate === "sheet";
const itemDetailAsSheet = presentation.itemDetail === "sheet";
const itemEditAsSheet = presentation.itemEdit === "sheet";
const supplierReturnCreateAsSheet = presentation.supplierReturnCreate === "sheet";
const supplierReturnDetailAsSheet = presentation.supplierReturnDetail === "sheet";
const supplierReturnEditAsSheet = presentation.supplierReturnEdit === "sheet";
const stockScrapCreateAsSheet = presentation.stockScrapCreate === "sheet";
const stockScrapDetailAsSheet = presentation.stockScrapDetail === "sheet";
const stockScrapEditAsSheet = presentation.stockScrapEdit === "sheet";
const categoryCreateAsSheet = presentation.categoryCreate === "sheet";
const categoryDetailAsSheet = presentation.categoryDetail === "sheet";
const categoryEditAsSheet = presentation.categoryEdit === "sheet";
const warehouseCreateAsSheet = presentation.warehouseCreate === "sheet";
const warehouseDetailAsSheet = presentation.warehouseDetail === "sheet";
const warehouseEditAsSheet = presentation.warehouseEdit === "sheet";

export function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/quotations" element={<ProtectedRoute><AppShell><QuotationsWorkspaceScreen /></AppShell></ProtectedRoute>} />
        <Route
          path="/quotations/:revision"
          element={<ProtectedRoute><AppShell><QuotationDetailScreen /></AppShell></ProtectedRoute>}
        />
        <Route
          path="/quotations/:revision/review"
          element={<ProtectedRoute><AppShell><QuotationDetailScreen review /></AppShell></ProtectedRoute>}
        />
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
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {itemCreateAsSheet && (
            <Route
              path="new"
              element={<ItemCreateScreen presentation="sheet" />}
            />
          )}
          {itemDetailAsSheet && (
            <Route
              path=":id"
              element={<ItemDetailScreen presentation="sheet" />}
            />
          )}
          {itemEditAsSheet && (
            <Route
              path=":id/edit"
              element={<ItemEditScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!itemCreateAsSheet && (
          <Route
            path="/items/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!itemDetailAsSheet && (
          <Route
            path="/items/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!itemEditAsSheet && (
          <Route
            path="/items/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ItemEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/item-quantity-units"
          element={
            <ProtectedRoute>
              <AppShell>
                <ItemQuantityUnitsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {packCreateAsSheet && <Route path="new" element={<PackConversionFormScreen presentation="sheet" />} />}
          {packDetailAsSheet && <Route path=":id" element={<PackConversionDetailScreen presentation="sheet" />} />}
          {packEditAsSheet && <Route path=":id/edit" element={<PackConversionFormScreen presentation="sheet" />} />}
        </Route>
        {!packCreateAsSheet && (
          <Route
            path="/item-quantity-units/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PackConversionFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!packDetailAsSheet && (
          <Route
            path="/item-quantity-units/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PackConversionDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!packEditAsSheet && (
          <Route
            path="/item-quantity-units/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PackConversionFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/warehouses"
          element={
            <ProtectedRoute>
              <AppShell>
                <WarehousesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {warehouseCreateAsSheet && (
            <Route
              path="new"
              element={<WarehouseCreateScreen presentation="sheet" />}
            />
          )}
          {warehouseDetailAsSheet && (
            <Route
              path=":id"
              element={<WarehouseDetailScreen presentation="sheet" />}
            />
          )}
          {warehouseEditAsSheet && (
            <Route
              path=":id/edit"
              element={<WarehouseEditScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!warehouseCreateAsSheet && (
          <Route
            path="/warehouses/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <WarehouseCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!warehouseDetailAsSheet && (
          <Route
            path="/warehouses/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <WarehouseDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!warehouseEditAsSheet && (
          <Route
            path="/warehouses/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <WarehouseEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/categories"
          element={
            <ProtectedRoute>
              <AppShell>
                <CategoriesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {categoryCreateAsSheet && (
            <Route
              path="new"
              element={<CategoryCreateScreen presentation="sheet" />}
            />
          )}
          {categoryDetailAsSheet && (
            <Route
              path=":id"
              element={<CategoryDetailScreen presentation="sheet" />}
            />
          )}
          {categoryEditAsSheet && (
            <Route
              path=":id/edit"
              element={<CategoryEditScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!categoryCreateAsSheet && (
          <Route
            path="/categories/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CategoryCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!categoryDetailAsSheet && (
          <Route
            path="/categories/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CategoryDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!categoryEditAsSheet && (
          <Route
            path="/categories/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CategoryEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
          path="/settings"
          element={
            <ProtectedRoute>
              <AppShell>
                <SettingsIndexScreen />
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/numbers"
          element={
            <ProtectedRoute>
              <AppShell>
                <NumbersAndRoundingScreen />
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
        >
          {supplierReturnCreateAsSheet && (
            <Route
              path="new"
              element={<SupplierReturnFormScreen presentation="sheet" />}
            />
          )}
          {supplierReturnDetailAsSheet && (
            <Route
              path=":id"
              element={<SupplierReturnDetailScreen presentation="sheet" />}
            />
          )}
          {supplierReturnEditAsSheet && (
            <Route
              path=":id/edit"
              element={<SupplierReturnFormScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!supplierReturnCreateAsSheet && (
          <Route
            path="/supplier-returns/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierReturnFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!supplierReturnDetailAsSheet && (
          <Route
            path="/supplier-returns/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierReturnDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!supplierReturnEditAsSheet && (
          <Route
            path="/supplier-returns/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <SupplierReturnFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/stock-scraps"
          element={
            <ProtectedRoute>
              <AppShell>
                <StockScrapsListScreen />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {stockScrapCreateAsSheet && (
            <Route
              path="new"
              element={<StockScrapFormScreen presentation="sheet" />}
            />
          )}
          {stockScrapDetailAsSheet && (
            <Route
              path=":id"
              element={<StockScrapDetailScreen presentation="sheet" />}
            />
          )}
          {stockScrapEditAsSheet && (
            <Route
              path=":id/edit"
              element={<StockScrapFormScreen presentation="sheet" />}
            />
          )}
        </Route>
        {!stockScrapCreateAsSheet && (
          <Route
            path="/stock-scraps/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StockScrapFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!stockScrapDetailAsSheet && (
          <Route
            path="/stock-scraps/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StockScrapDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!stockScrapEditAsSheet && (
          <Route
            path="/stock-scraps/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StockScrapFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/opportunities"
          element={
            <ProtectedRoute>
              <AppShell>
                <OpportunitiesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {opportunityCreateAsSheet && <Route path="new" element={<OpportunityFormScreen presentation="sheet" />} />}
          {opportunityDetailAsSheet && <Route path=":id" element={<OpportunityDetailScreen presentation="sheet" />} />}
          {opportunityEditAsSheet && <Route path=":id/edit" element={<OpportunityFormScreen presentation="sheet" />} />}
        </Route>
        {!opportunityCreateAsSheet && (
          <Route
            path="/opportunities/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <OpportunityFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!opportunityEditAsSheet && (
          <Route
            path="/opportunities/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <OpportunityFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!opportunityDetailAsSheet && (
          <Route
            path="/opportunities/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <OpportunityDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/leads"
          element={
            <ProtectedRoute>
              <AppShell>
                <LeadsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {leadCreateAsSheet && <Route path="new" element={<LeadFormScreen presentation="sheet" />} />}
          {leadDetailAsSheet && <Route path=":id" element={<LeadDetailScreen presentation="sheet" />} />}
        </Route>
        {!leadCreateAsSheet && (
          <Route
            path="/leads/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <LeadFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!leadDetailAsSheet && (
          <Route
            path="/leads/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <LeadDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/sales-stages"
          element={
            <ProtectedRoute>
              <AppShell>
                <SalesStagesScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {stageCreateAsSheet && <Route path="new" element={<StageFormScreen presentation="sheet" />} />}
          {stageDetailAsSheet && <Route path=":id" element={<StageDetailScreen presentation="sheet" />} />}
          {stageEditAsSheet && <Route path=":id/edit" element={<StageFormScreen presentation="sheet" />} />}
        </Route>
        {!stageCreateAsSheet && (
          <Route
            path="/sales-stages/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StageFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!stageDetailAsSheet && (
          <Route
            path="/sales-stages/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StageDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!stageEditAsSheet && (
          <Route
            path="/sales-stages/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <StageFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
