export { AppShell } from './layouts/app-shell';
export { ProtectedRoute } from './routes/protected-route';
export { LoginScreen } from './features/auth/login-screen';
export { DashboardScreen } from './features/dashboard/dashboard-screen';
export { useAuthStore } from './stores/auth-store';
export { useSession } from './hooks/use-session';
export { cn } from './lib/utils';

// Reusable list/detail/print foundation — see docs/architecture (frontend)
// and features/items for the reference implementation.
export * from './lib/list-query-state';
export * from './components/data-table';
export * from './components/print';
export { PageHeader, type PageHeaderProps, type PageHeaderBreadcrumb } from './components/page/page-header';
export { DetailPageLayout, DetailSection, type DetailSectionProps } from './components/detail/detail-page-layout';
export { DetailField, type DetailFieldProps } from './components/detail/detail-field';

// shadcn/ui primitives — copied in, not a dependency (docs/architecture/tech-stack.md).
export { Button, buttonVariants, type ButtonProps } from './components/ui/button';
export { Input } from './components/ui/input';
export { Label } from './components/ui/label';
export { Textarea } from './components/ui/textarea';
export { Badge, badgeVariants, type BadgeProps } from './components/ui/badge';
export { Skeleton } from './components/ui/skeleton';
export { Checkbox } from './components/ui/checkbox';
export {
  Select,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
} from './components/ui/select';
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
} from './components/ui/dropdown-menu';
export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
} from './components/ui/table';
export {
  Form,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
  FormField,
  useFormField,
} from './components/ui/form';

// Items — the reference implementation for this list/detail/print foundation.
export { ItemsListScreen } from './features/items/items-list-screen';
export { ItemDetailScreen } from './features/items/item-detail-screen';
export { ItemCreateScreen } from './features/items/item-create-screen';
export { ItemEditScreen } from './features/items/item-edit-screen';

export { WarehousesListScreen } from './features/warehouses/warehouses-list-screen';
export { WarehouseDetailScreen } from './features/warehouses/warehouse-detail-screen';
export { WarehouseCreateScreen } from './features/warehouses/warehouse-create-screen';
export { WarehouseEditScreen } from './features/warehouses/warehouse-edit-screen';
