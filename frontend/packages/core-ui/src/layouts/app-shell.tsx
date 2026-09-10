import { useEffect, useRef, useState, type ReactNode } from "react";
import {
  Boxes,
  Calculator,
  ClipboardList,
  FileText,
  PackageCheck,
  History,
  LayoutDashboard,
  LogOut,
  MoreHorizontal,
  Package,
  ScrollText,
  ShieldAlert,
  Tags,
  Warehouse,
} from "lucide-react";
import { NavLink, useLocation, useNavigate } from "react-router";
import { useLogout } from "@erp/core-api";

import { Button } from "../components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "../components/ui/sheet";
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

// A native bottom tab bar only fits ~5 slots before it stops feeling native —
// this fixed 4-item primary set (+ "More") is a temporary stand-in. Once
// permissions are fully dynamic, the primary set should be computed per user
// (permission grants + actual visit frequency) instead of this fixed list.
const PRIMARY_PATHS = new Set([
  "/",
  "/items",
  "/purchase-orders",
  "/goods-receipts",
]);
const primaryNavItems = navItems.filter((item) => PRIMARY_PATHS.has(item.to));
const moreNavItems = navItems.filter((item) => !PRIMARY_PATHS.has(item.to));

// Mobile-first: a fixed bottom tab bar below `md`, a persistent sidebar above it —
// both render the same `navItems`, only one is visible at a given width.
export function AppShell({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const logout = useLogout();
  const [moreOpen, setMoreOpen] = useState(false);
  const mainRef = useRef<HTMLElement>(null);

  // `<main>` (not `window`) is the actual scroll container — React Router
  // doesn't reset it on navigation, so without this a route opened while
  // scrolled down on the previous screen stays scrolled down.
  useEffect(() => {
    mainRef.current?.scrollTo(0, 0);
  }, [pathname]);

  function handleLogout() {
    logout.mutate(undefined, {
      onSuccess: () => {
        setUser(null);
        navigate("/login", { replace: true });
      },
    });
  }

  return (
    <div className="flex h-dvh flex-col overflow-hidden bg-background text-foreground md:flex-row">
      <aside className="hidden w-56 shrink-0 flex-col overflow-y-auto border-r border-border p-4 md:flex">
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

      <main
        ref={mainRef}
        className="min-w-0 flex-1 overflow-y-auto pb-[calc(var(--bottom-nav-h)+env(safe-area-inset-bottom))] md:pb-0"
      >
        {children}
      </main>

      <nav
        className="fixed inset-x-0 bottom-0 z-30 flex justify-evenly border-t border-border bg-background md:hidden"
        style={{ paddingBottom: "env(safe-area-inset-bottom)" }}
      >
        {primaryNavItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end
            className={({ isActive }) =>
              cn(
                "flex min-h-14 min-w-11 flex-col items-center justify-center gap-0.5 px-2 py-2 text-muted-foreground hover:text-foreground",
                isActive && "text-foreground",
              )
            }
          >
            <item.icon className="size-5 shrink-0" />
            <span className="max-w-[72px] truncate text-[10px] leading-tight">
              {item.label}
            </span>
          </NavLink>
        ))}
        <button
          type="button"
          onClick={() => setMoreOpen(true)}
          className="flex min-h-14 min-w-11 flex-col items-center justify-center gap-0.5 px-2 py-2 text-muted-foreground hover:text-foreground"
        >
          <MoreHorizontal className="size-5 shrink-0" />
          <span className="text-[10px] leading-tight">More</span>
        </button>
      </nav>

      <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>More</SheetTitle>
          </SheetHeader>
          <nav className="flex flex-col gap-1 py-2">
            {moreNavItems.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end
                onClick={() => setMoreOpen(false)}
                className={({ isActive }) =>
                  cn(
                    "flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium",
                    isActive
                      ? "bg-accent text-accent-foreground"
                      : "text-foreground hover:bg-accent hover:text-accent-foreground",
                  )
                }
              >
                <item.icon className="size-5" />
                {item.label}
              </NavLink>
            ))}
            <button
              type="button"
              onClick={() => {
                setMoreOpen(false);
                handleLogout();
              }}
              className="flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium text-destructive hover:bg-accent"
            >
              <LogOut className="size-5" />
              Log out
            </button>
          </nav>
        </SheetContent>
      </Sheet>
    </div>
  );
}
