import type { ReactNode } from "react";
import {
  Boxes,
  Calculator,
  ClipboardList,
  FileText,
  PackageCheck,
  History,
  LayoutDashboard,
  LogOut,
  Package,
  ScrollText,
  ShieldAlert,
  Tags,
  Warehouse,
} from "lucide-react";
import { NavLink, useNavigate } from "react-router";
import { useLogout } from "@erp/core-api";

import { Button } from "../components/ui/button";
import { useAuthStore } from "../stores/auth-store";
import { cn } from "../lib/utils";

const navItems = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard },
  { to: "/items", label: "Items", icon: Package },
  { to: "/warehouses", label: "Warehouses", icon: Warehouse },
  { to: "/categories", label: "Categories", icon: Tags },
  { to: "/item-suppliers", label: "Item suppliers", icon: Boxes },
  { to: "/purchase-orders", label: "Purchase Orders", icon: FileText },
  { to: "/goods-receipts", label: "Goods Receipts", icon: PackageCheck },
  { to: "/stock-moves", label: "Stock Ledger", icon: ScrollText },
  { to: "/physical-counts", label: "Physical Counts", icon: ClipboardList },
  { to: "/inventory-cutovers", label: "Inventory Cutovers", icon: History },
  {
    to: "/negative-stock-overrides",
    label: "Negative-Stock Overrides",
    icon: ShieldAlert,
  },
  {
    to: "/recalculation-requests",
    label: "Stock Recalculation",
    icon: Calculator,
  },
];

// Mobile-first: a fixed bottom tab bar below `md`, a persistent sidebar above it —
// both render the same `navItems`, only one is visible at a given width.
export function AppShell({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const logout = useLogout();

  function handleLogout() {
    logout.mutate(undefined, {
      onSuccess: () => {
        setUser(null);
        navigate("/login", { replace: true });
      },
    });
  }

  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground md:flex-row">
      <aside className="hidden w-56 shrink-0 flex-col border-r border-border p-4 md:flex">
        <div className="mb-6 text-lg font-semibold">ERP</div>
        <nav className="flex flex-1 flex-col gap-1">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end
              className={({ isActive }) =>
                cn(
                  "flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground",
                  isActive && "bg-accent text-accent-foreground",
                )
              }
            >
              <item.icon className="size-4" />
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="border-t border-border pt-4">
          <div className="mb-2 truncate text-sm text-muted-foreground">
            {user?.email}
          </div>
          <Button
            variant="outline"
            size="sm"
            className="w-full"
            onClick={handleLogout}
          >
            <LogOut className="size-4" />
            Log out
          </Button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 overflow-y-auto pb-16 md:pb-0">
        {children}
      </main>

      <nav className="fixed inset-x-0 bottom-0 flex overflow-x-auto border-t border-border bg-background md:hidden">
        {navItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end
            className={({ isActive }) =>
              cn(
                "flex min-w-20 flex-none flex-col items-center gap-1 py-2 text-xs text-muted-foreground hover:text-foreground",
                isActive && "text-foreground",
              )
            }
          >
            <item.icon className="size-5" />
            {item.label}
          </NavLink>
        ))}
        <button
          type="button"
          onClick={handleLogout}
          className="flex min-w-20 flex-none flex-col items-center gap-1 py-2 text-xs text-muted-foreground hover:text-foreground"
        >
          <LogOut className="size-5" />
          Log out
        </button>
      </nav>
    </div>
  );
}
