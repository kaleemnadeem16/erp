import { BrowserRouter, Routes, Route, Outlet } from "react-router";
import {
  AppShell,
  QuotationsWorkspaceScreen,
  QuotationListRoute,
  QuotationApprovalRoute,
  QuotationIssueRoute,
  QuotationDocumentRoute,
  QuotationAuthorityHistoryRoute,
  QuotationCreateScreen,
  QuotationEditScreen,
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
  DiscountAuthoritySettingsScreen,
  DiscountAuthorityReferencesScreen,
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
  CustomersListScreen,
  ExchangeRatesListScreen,
  ExchangeRateFormScreen,
  ChartOfAccountsListScreen,
  ChartOfAccountFormScreen,
  CustomerDetailScreen,
  CustomerFormScreen,
  PartiesListScreen,
  PartyDetailScreen,
  PartyFormScreen,
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
  ResourceRateFormScreen,
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
const physicalCountCreateAsSheet = presentation.physicalCountCreate === "sheet";
const physicalCountDetailAsSheet = presentation.physicalCountDetail === "sheet";
const inventoryCutoverCreateAsSheet = presentation.inventoryCutoverCreate === "sheet";
const inventoryCutoverDetailAsSheet = presentation.inventoryCutoverDetail === "sheet";
const negativeStockOverrideCreateAsSheet = presentation.negativeStockOverrideCreate === "sheet";
const negativeStockOverrideDetailAsSheet = presentation.negativeStockOverrideDetail === "sheet";
const recalculationRequestCreateAsSheet = presentation.recalculationRequestCreate === "sheet";
const recalculationRequestDetailAsSheet = presentation.recalculationRequestDetail === "sheet";
const exchangeRateCreateAsSheet = presentation.exchangeRateCreate === "sheet";
const exchangeRateDetailAsSheet = presentation.exchangeRateDetail === "sheet";
const resourceRateCreateAsSheet = presentation.resourceRateCreate === "sheet";
const resourceRateDetailAsSheet = presentation.resourceRateDetail === "sheet";
const rateDimensionCreateAsSheet = presentation.rateDimensionCreate === "sheet";
const rateDimensionEditAsSheet = presentation.rateDimensionEdit === "sheet";
const applicationRateCreateAsSheet = presentation.applicationRateCreate === "sheet";
const applicationRateEditAsSheet = presentation.applicationRateEdit === "sheet";
const formulationCreateAsSheet = presentation.formulationCreate === "sheet";
const formulationEditAsSheet = presentation.formulationEdit === "sheet";
const assemblyGroupCreateAsSheet = presentation.assemblyGroupCreate === "sheet";
const assemblyGroupEditAsSheet = presentation.assemblyGroupEdit === "sheet";
const assemblyCreateAsSheet = presentation.assemblyCreate === "sheet";
const assemblyEditAsSheet = presentation.assemblyEdit === "sheet";
const chartOfAccountCreateAsSheet = presentation.chartOfAccountCreate === "sheet";
const chartOfAccountDetailAsSheet = presentation.chartOfAccountDetail === "sheet";
const customerCreateAsSheet = presentation.customerCreate === "sheet";
const customerDetailAsSheet = presentation.customerDetail === "sheet";
const customerEditAsSheet = presentation.customerEdit === "sheet";
const partyCreateAsSheet = presentation.partyCreate === "sheet";
const partyDetailAsSheet = presentation.partyDetail === "sheet";
const partyEditAsSheet = presentation.partyEdit === "sheet";
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
        <Route path="/quotations/new" element={<ProtectedRoute><AppShell><QuotationCreateScreen /></AppShell></ProtectedRoute>} />
        <Route path="/quotations/:revision/edit" element={<ProtectedRoute><AppShell><QuotationEditScreen /></AppShell></ProtectedRoute>} />
        <Route path="/quotations" element={<ProtectedRoute><AppShell><QuotationsWorkspaceScreen /></AppShell></ProtectedRoute>} />
        {/* Gated: every screen below denies before any request while QUOTATION_UI_ENABLED is false (production); only the local dev override opens them. */}
        <Route path="/quotations/all" element={<ProtectedRoute><AppShell><QuotationListRoute /></AppShell></ProtectedRoute>} />
        <Route path="/quotations/:revision/approval" element={<ProtectedRoute><AppShell><QuotationApprovalRoute /></AppShell></ProtectedRoute>} />
        <Route path="/quotations/:revision/issue" element={<ProtectedRoute><AppShell><QuotationIssueRoute /></AppShell></ProtectedRoute>} />
        <Route path="/quotations/:revision/discount-authority-history" element={<ProtectedRoute><AppShell><QuotationAuthorityHistoryRoute /></AppShell></ProtectedRoute>} />
        <Route path="/quotations/:revision/document" element={<ProtectedRoute><AppShell><QuotationDocumentRoute /></AppShell></ProtectedRoute>} />
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
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {rateDimensionCreateAsSheet && (
            <Route path="new" element={<RateDimensionCreateScreen presentation="sheet" />} />
          )}
          {rateDimensionEditAsSheet && (
            <Route path=":id/edit" element={<RateDimensionEditScreen presentation="sheet" />} />
          )}
        </Route>
        {!rateDimensionCreateAsSheet && (
          <Route
            path="/rate-dimensions/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <RateDimensionCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!rateDimensionEditAsSheet && (
          <Route
            path="/rate-dimensions/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <RateDimensionEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/application-rates"
          element={
            <ProtectedRoute>
              <AppShell>
                <ApplicationRatesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {applicationRateCreateAsSheet && (
            <Route path="new" element={<ApplicationRateCreateScreen presentation="sheet" />} />
          )}
          {applicationRateEditAsSheet && (
            <Route path=":id/edit" element={<ApplicationRateEditScreen presentation="sheet" />} />
          )}
        </Route>
        {!applicationRateCreateAsSheet && (
          <Route
            path="/application-rates/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ApplicationRateCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!applicationRateEditAsSheet && (
          <Route
            path="/application-rates/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ApplicationRateEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/formulations"
          element={
            <ProtectedRoute>
              <AppShell>
                <FormulationsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {formulationCreateAsSheet && (
            <Route path="new" element={<FormulationCreateScreen presentation="sheet" />} />
          )}
          {formulationEditAsSheet && (
            <Route path=":id/edit" element={<FormulationEditScreen presentation="sheet" />} />
          )}
        </Route>
        {!formulationCreateAsSheet && (
          <Route
            path="/formulations/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <FormulationCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!formulationEditAsSheet && (
          <Route
            path="/formulations/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <FormulationEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/assembly-groups"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssemblyGroupsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {assemblyGroupCreateAsSheet && (
            <Route path="new" element={<AssemblyGroupCreateScreen presentation="sheet" />} />
          )}
          {assemblyGroupEditAsSheet && (
            <Route path=":id/edit" element={<AssemblyGroupEditScreen presentation="sheet" />} />
          )}
        </Route>
        {!assemblyGroupCreateAsSheet && (
          <Route
            path="/assembly-groups/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <AssemblyGroupCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!assemblyGroupEditAsSheet && (
          <Route
            path="/assembly-groups/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <AssemblyGroupEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/assemblies"
          element={
            <ProtectedRoute>
              <AppShell>
                <AssembliesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {assemblyCreateAsSheet && (
            <Route path="new" element={<AssemblyCreateScreen presentation="sheet" />} />
          )}
          {assemblyEditAsSheet && (
            <Route path=":id/edit" element={<AssemblyEditScreen presentation="sheet" />} />
          )}
        </Route>
        {!assemblyCreateAsSheet && (
          <Route
            path="/assemblies/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <AssemblyCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!assemblyEditAsSheet && (
          <Route
            path="/assemblies/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <AssemblyEditScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/resource-rates"
          element={
            <ProtectedRoute>
              <AppShell>
                <ResourceRatesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {resourceRateCreateAsSheet && (
            <Route path="new" element={<ResourceRateFormScreen presentation="sheet" />} />
          )}
          {resourceRateDetailAsSheet && (
            <Route path=":id" element={<ResourceRateFormScreen presentation="sheet" />} />
          )}
        </Route>
        {!resourceRateCreateAsSheet && (
          <Route
            path="/resource-rates/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ResourceRateFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!resourceRateDetailAsSheet && (
          <Route
            path="/resource-rates/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ResourceRateFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
          path="/customers"
          element={
            <ProtectedRoute>
              <AppShell>
                <CustomersListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {customerCreateAsSheet && (
            <Route path="new" element={<CustomerFormScreen presentation="sheet" />} />
          )}
          {customerDetailAsSheet && (
            <Route path=":id" element={<CustomerDetailScreen presentation="sheet" />} />
          )}
          {customerEditAsSheet && (
            <Route path=":id/edit" element={<CustomerFormScreen presentation="sheet" />} />
          )}
        </Route>
        {!customerCreateAsSheet && (
          <Route
            path="/customers/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CustomerFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!customerDetailAsSheet && (
          <Route
            path="/customers/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CustomerDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!customerEditAsSheet && (
          <Route
            path="/customers/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <CustomerFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/parties"
          element={
            <ProtectedRoute>
              <AppShell>
                <PartiesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {partyCreateAsSheet && (
            <Route path="new" element={<PartyFormScreen presentation="sheet" />} />
          )}
          {partyDetailAsSheet && (
            <Route path=":id" element={<PartyDetailScreen presentation="sheet" />} />
          )}
          {partyEditAsSheet && (
            <Route path=":id/edit" element={<PartyFormScreen presentation="sheet" />} />
          )}
        </Route>
        {!partyCreateAsSheet && (
          <Route
            path="/parties/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PartyFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!partyDetailAsSheet && (
          <Route
            path="/parties/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PartyDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!partyEditAsSheet && (
          <Route
            path="/parties/:id/edit"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PartyFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/settings/exchange-rates"
          element={
            <ProtectedRoute>
              <AppShell>
                <ExchangeRatesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {exchangeRateCreateAsSheet && (
            <Route path="new" element={<ExchangeRateFormScreen presentation="sheet" />} />
          )}
          {exchangeRateDetailAsSheet && (
            <Route path=":id" element={<ExchangeRateFormScreen presentation="sheet" />} />
          )}
        </Route>
        {!exchangeRateCreateAsSheet && (
          <Route
            path="/settings/exchange-rates/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ExchangeRateFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!exchangeRateDetailAsSheet && (
          <Route
            path="/settings/exchange-rates/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ExchangeRateFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/settings/chart-of-accounts"
          element={
            <ProtectedRoute>
              <AppShell>
                <ChartOfAccountsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {chartOfAccountCreateAsSheet && (
            <Route path="new" element={<ChartOfAccountFormScreen presentation="sheet" />} />
          )}
          {chartOfAccountDetailAsSheet && (
            <Route path=":id" element={<ChartOfAccountFormScreen presentation="sheet" />} />
          )}
        </Route>
        {!chartOfAccountCreateAsSheet && (
          <Route
            path="/settings/chart-of-accounts/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ChartOfAccountFormScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!chartOfAccountDetailAsSheet && (
          <Route
            path="/settings/chart-of-accounts/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <ChartOfAccountFormScreen presentation="page" />
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
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {physicalCountCreateAsSheet && (
            <Route path="new" element={<PhysicalCountCreateScreen presentation="sheet" />} />
          )}
          {physicalCountDetailAsSheet && (
            <Route path=":id" element={<PhysicalCountDetailScreen presentation="sheet" />} />
          )}
        </Route>
        {!physicalCountCreateAsSheet && (
          <Route
            path="/physical-counts/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PhysicalCountCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!physicalCountDetailAsSheet && (
          <Route
            path="/physical-counts/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <PhysicalCountDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {inventoryCutoverCreateAsSheet && (
            <Route path="new" element={<InventoryCutoverCreateScreen presentation="sheet" />} />
          )}
          {inventoryCutoverDetailAsSheet && (
            <Route path=":id" element={<InventoryCutoverDetailScreen presentation="sheet" />} />
          )}
        </Route>
        {!inventoryCutoverCreateAsSheet && (
          <Route
            path="/inventory-cutovers/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <InventoryCutoverCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!inventoryCutoverDetailAsSheet && (
          <Route
            path="/inventory-cutovers/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <InventoryCutoverDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/negative-stock-overrides"
          element={
            <ProtectedRoute>
              <AppShell>
                <NegativeStockOverridesListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {negativeStockOverrideCreateAsSheet && (
            <Route path="new" element={<NegativeStockOverrideCreateScreen presentation="sheet" />} />
          )}
          {negativeStockOverrideDetailAsSheet && (
            <Route path=":id" element={<NegativeStockOverrideDetailScreen presentation="sheet" />} />
          )}
        </Route>
        {!negativeStockOverrideCreateAsSheet && (
          <Route
            path="/negative-stock-overrides/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <NegativeStockOverrideCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!negativeStockOverrideDetailAsSheet && (
          <Route
            path="/negative-stock-overrides/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <NegativeStockOverrideDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        <Route
          path="/recalculation-requests"
          element={
            <ProtectedRoute>
              <AppShell>
                <RecalculationRequestsListScreen />
                <Outlet />
              </AppShell>
            </ProtectedRoute>
          }
        >
          {recalculationRequestCreateAsSheet && (
            <Route path="new" element={<RecalculationRequestCreateScreen presentation="sheet" />} />
          )}
          {recalculationRequestDetailAsSheet && (
            <Route path=":id" element={<RecalculationRequestDetailScreen presentation="sheet" />} />
          )}
        </Route>
        {!recalculationRequestCreateAsSheet && (
          <Route
            path="/recalculation-requests/new"
            element={
              <ProtectedRoute>
                <AppShell>
                  <RecalculationRequestCreateScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
        {!recalculationRequestDetailAsSheet && (
          <Route
            path="/recalculation-requests/:id"
            element={
              <ProtectedRoute>
                <AppShell>
                  <RecalculationRequestDetailScreen presentation="page" />
                </AppShell>
              </ProtectedRoute>
            }
          />
        )}
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
          path="/settings/discount-authority"
          element={
            <ProtectedRoute>
              <AppShell>
                <PermissionRoute permission="sales.financial.configure">
                  <DiscountAuthoritySettingsScreen />
                </PermissionRoute>
              </AppShell>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/discount-authority/references"
          element={
            <ProtectedRoute>
              <AppShell>
                {/* Proposing and approving are separate duties; the screen checks either and the server checks each action. */}
                <DiscountAuthorityReferencesScreen />
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
