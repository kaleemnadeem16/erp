export { AppShell } from "./layouts/app-shell";
export { ProtectedRoute } from "./routes/protected-route";
export { PermissionRoute } from "./routes/permission-route";
export { LoginScreen } from "./features/auth/login-screen";
export { DashboardScreen } from "./features/dashboard/dashboard-screen";
export { useAuthStore } from "./stores/auth-store";
export { useSession } from "./hooks/use-session";
export { cn } from "./lib/utils";
export { useMediaQuery, MD_BREAKPOINT_QUERY } from "./lib/use-media-query";
export {
  useListViewPreference,
  type ListView,
} from "./lib/use-view-preference";
export { useColumnVisibilityPreference } from "./lib/use-column-visibility-preference";

// Reusable list/detail/print foundation — see docs/architecture (frontend)
// and features/items for the reference implementation.
export * from "./lib/list-query-state";
export { describeLoadError, isTransientFailure, shouldRetryQuery } from "./lib/api-errors";
export * from "./components/data-table";
export * from "./components/print";
export {
  PageHeader,
  type PageHeaderProps,
  type PageHeaderBreadcrumb,
  type PageHeaderPrimaryAction,
} from "./components/page/page-header";
export { MobileFab, type MobileFabProps } from "./components/page/mobile-fab";
export {
  RecordSheet,
  RecordSheetAside,
  type RecordSheetProps,
  type RecordSheetSize,
} from "./components/page/record-sheet";
export type { Presentation } from "./lib/presentation";
export { AppearanceProvider } from "./components/theme/appearance-provider";
export {
  THEMES,
  DEFAULT_APPEARANCE,
  resolveAppearance,
  type Appearance,
  type ColorMode,
  type ThemeId,
  type ThemeDefinition,
} from "./lib/appearance";
export { useAppearanceStore, useAppearance, useIsDarkMode } from "./stores/appearance-store";
export { AppearanceSettingsScreen } from "./features/settings/appearance-settings-screen";
export { AcceptInvitationScreen } from "./features/auth/accept-invitation-screen";
export { PrintingSettingsScreen } from "./features/settings/printing-settings-screen";
export { AccountSettingsScreen } from "./features/settings/account-settings-screen";
export { MembersSettingsScreen } from "./features/access-administration/members-settings-screen";
export { RolesSettingsScreen } from "./features/access-administration/roles-settings-screen";
export {
  usePrintSettingsStore,
  DEFAULT_PRINT_SETTINGS,
  type PrintSettings,
} from "./stores/print-settings-store";
export { OptionCards, type OptionCard, type OptionCardsProps } from "./components/ui/option-cards";
export {
  DetailPageLayout,
  DetailSection,
  type DetailSectionProps,
} from "./components/detail/detail-page-layout";
export {
  DetailField,
  type DetailFieldProps,
} from "./components/detail/detail-field";

// shadcn/ui primitives — copied in, not a dependency (docs/architecture/tech-stack.md).
export {
  Button,
  buttonVariants,
  type ButtonProps,
} from "./components/ui/button";
export { Input } from "./components/ui/input";
export { Label } from "./components/ui/label";
export { Textarea } from "./components/ui/textarea";
export { Badge, badgeVariants, type BadgeProps } from "./components/ui/badge";
export { Skeleton } from "./components/ui/skeleton";
export { Checkbox } from "./components/ui/checkbox";
export {
  Select,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
} from "./components/ui/select";
export {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuCheckboxItem,
  DropdownMenuRadioItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuGroup,
  DropdownMenuPortal,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuRadioGroup,
} from "./components/ui/dropdown-menu";
export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
} from "./components/ui/table";
export {
  Form,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
  FormField,
  useFormField,
} from "./components/ui/form";
export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
} from "./components/ui/sheet";
export {
  Dialog,
  DialogTrigger,
  DialogClose,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "./components/ui/dialog";

// Items — the reference implementation for this list/detail/print foundation.
export { ItemsListScreen } from "./features/items/items-list-screen";
export { ItemDetailScreen } from "./features/items/item-detail-screen";
export { ItemCreateScreen } from "./features/items/item-create-screen";
export { ItemEditScreen } from "./features/items/item-edit-screen";
export { ItemQuantityUnitsListScreen } from "./features/item-quantity-units/item-quantity-units-list-screen";

export { WarehousesListScreen } from "./features/warehouses/warehouses-list-screen";
export { WarehouseDetailScreen } from "./features/warehouses/warehouse-detail-screen";
export { WarehouseCreateScreen } from "./features/warehouses/warehouse-create-screen";
export { WarehouseEditScreen } from "./features/warehouses/warehouse-edit-screen";
export { CategoriesListScreen } from "./features/categories/categories-list-screen";
export { CategoryDetailScreen } from "./features/categories/category-detail-screen";
export { CategoryCreateScreen } from "./features/categories/category-create-screen";
export { CategoryEditScreen } from "./features/categories/category-edit-screen";
export { ItemSuppliersListScreen } from "./features/item-suppliers/item-suppliers-list-screen";
export { ItemSupplierDetailScreen } from "./features/item-suppliers/item-supplier-detail-screen";
export { ItemSupplierCreateScreen } from "./features/item-suppliers/item-supplier-create-screen";
export { ItemSupplierEditScreen } from "./features/item-suppliers/item-supplier-edit-screen";
export { RateDimensionsListScreen } from "./features/rate-dimensions/rate-dimensions-list-screen";
export { RateDimensionCreateScreen } from "./features/rate-dimensions/rate-dimension-create-screen";
export { RateDimensionEditScreen } from "./features/rate-dimensions/rate-dimension-edit-screen";
export { ApplicationRatesListScreen } from "./features/application-rates/application-rates-list-screen";
export { ApplicationRateCreateScreen } from "./features/application-rates/application-rate-create-screen";
export { ApplicationRateEditScreen } from "./features/application-rates/application-rate-edit-screen";
export { FormulationsListScreen } from "./features/formulations/formulations-list-screen";
export { FormulationCreateScreen } from "./features/formulations/formulation-create-screen";
export { FormulationEditScreen } from "./features/formulations/formulation-edit-screen";
export { AssemblyGroupsListScreen } from "./features/assembly-groups/assembly-groups-list-screen";
export { AssemblyGroupCreateScreen } from "./features/assembly-groups/assembly-group-create-screen";
export { AssemblyGroupEditScreen } from "./features/assembly-groups/assembly-group-edit-screen";
export { AssembliesListScreen } from "./features/assemblies/assemblies-list-screen";
export { AssemblyCreateScreen } from "./features/assemblies/assembly-create-screen";
export { AssemblyEditScreen } from "./features/assemblies/assembly-edit-screen";
export { MaterialPreviewScreen } from "./features/material-preview/material-preview-screen";
export { EstimatingSettingsScreen } from "./features/settings/estimating-settings-screen";
export { ResourceRatesListScreen } from "./features/resource-rates/resource-rates-list-screen";
export { StockLedgerScreen } from "./features/stock-ledger/stock-ledger-screen";
export { StockMoveDetailScreen } from "./features/stock-ledger/stock-move-detail-screen";
export { PhysicalCountsListScreen } from "./features/physical-counts/physical-counts-list-screen";
export { PhysicalCountDetailScreen } from "./features/physical-counts/physical-count-detail-screen";
export { PhysicalCountCreateScreen } from "./features/physical-counts/physical-count-create-screen";
export { InventoryCutoversListScreen } from "./features/inventory-cutovers/inventory-cutovers-list-screen";
export { InventoryCutoverDetailScreen } from "./features/inventory-cutovers/inventory-cutover-detail-screen";
export { InventoryCutoverCreateScreen } from "./features/inventory-cutovers/inventory-cutover-create-screen";
export { NegativeStockOverridesListScreen } from "./features/negative-stock-overrides/negative-stock-overrides-list-screen";
export { NegativeStockOverrideDetailScreen } from "./features/negative-stock-overrides/negative-stock-override-detail-screen";
export { NegativeStockOverrideCreateScreen } from "./features/negative-stock-overrides/negative-stock-override-create-screen";
export { RecalculationRequestsListScreen } from "./features/recalculation-requests/recalculation-requests-list-screen";
export { RecalculationRequestDetailScreen } from "./features/recalculation-requests/recalculation-request-detail-screen";
export { RecalculationRequestCreateScreen } from "./features/recalculation-requests/recalculation-request-create-screen";
export { PurchaseOrdersListScreen } from "./features/purchase-orders/purchase-orders-list-screen";
export { PurchaseOrderDetailScreen } from "./features/purchase-orders/purchase-order-detail-screen";
export { PurchaseOrderCreateScreen } from "./features/purchase-orders/purchase-order-create-screen";
export { PurchaseOrderEditScreen } from "./features/purchase-orders/purchase-order-edit-screen";
export { SupplierSiteDialog } from "./features/purchase-orders/supplier-site-form";
export { GoodsReceiptsListScreen } from "./features/goods-receipts/goods-receipts-list-screen";
export { GoodsReceiptCreateScreen } from "./features/goods-receipts/goods-receipt-create-screen";
export { GoodsReceiptDetailScreen } from "./features/goods-receipts/goods-receipt-detail-screen";

export { PurchasingSettingsScreen } from "./features/settings/purchasing-settings-screen";
export { InventorySettingsScreen } from "./features/settings/inventory-settings-screen";

export { InventoryOverviewScreen } from "./features/inventory-overview/inventory-overview-screen";
