import { useEffect, useRef, useState, type ReactNode } from "react";
import {
  Boxes,
  Calculator,
  ClipboardList,
  FileText,
  PackageCheck,
  History,
  Layers,
  LayoutDashboard,
  LogOut,
  MoreHorizontal,
  Moon,
  Package,
  Palette,
  Percent,
  Pin,
  PinOff,
  Printer,
  Ruler,
  ScrollText,
  Settings,
  ShieldAlert,
  SlidersHorizontal,
  Sun,
  Tags,
  User,
  Warehouse,
} from "lucide-react";
import { NavLink, useLocation, useNavigate } from "react-router";
import { useLogout } from "@erp/core-api";

import { Button } from "../components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../components/ui/dropdown-menu";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "../components/ui/sheet";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "../components/ui/tooltip";
import { getInitials } from "../lib/initials";
import { useAuthStore } from "../stores/auth-store";
import { useAppearanceStore, useIsDarkMode } from "../stores/appearance-store";
import { cn } from "../lib/utils";
import {
  recordNavVisit,
  resolveNavPath,
  useNavUsagePrefs,
} from "../lib/use-nav-usage";

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutDashboard;
}

interface NavGroup {
  /** null renders with no header — used only for the standalone Dashboard entry. */
  label: string | null;
  items: NavItem[];
}

// Grouped by domain so the desktop sidebar reads as a handful of sections
// instead of one flat list of 15 links — see docs/architecture/user-interface.md
// for the fuller rationale. Settings is deliberately NOT one of these groups:
// it's cross-cutting configuration, not a workflow module, and lives only in
// the top-bar user/settings menus (see SETTINGS_MENU below) plus the mobile
// "More" sheet, which has no separate top bar to hold it.
const navGroups: NavGroup[] = [
  { label: null, items: [{ to: "/", label: "Dashboard", icon: LayoutDashboard }] },
  {
    label: "Purchasing",
    items: [
      { to: "/purchase-orders", label: "Purchase Orders", icon: FileText },
      { to: "/goods-receipts", label: "Goods Receipts", icon: PackageCheck },
      { to: "/item-suppliers", label: "Item Suppliers", icon: Boxes },
    ],
  },
  {
    label: "Inventory",
    items: [
      { to: "/items", label: "Items", icon: Package },
      { to: "/categories", label: "Categories", icon: Tags },
      { to: "/warehouses", label: "Warehouses", icon: Warehouse },
      { to: "/inventory-overview", label: "Inventory Overview", icon: Layers },
      { to: "/stock-moves", label: "Stock Ledger", icon: ScrollText },
    ],
  },
  {
    label: "Stock Operations",
    items: [
      { to: "/physical-counts", label: "Physical Counts", icon: ClipboardList },
      { to: "/inventory-cutovers", label: "Inventory Cutovers", icon: History },
      { to: "/negative-stock-overrides", label: "Negative-Stock Overrides", icon: ShieldAlert },
      { to: "/recalculation-requests", label: "Stock Recalculation", icon: Calculator },
    ],
  },
  {
    label: "Estimating",
    items: [
      { to: "/rate-dimensions", label: "Rate Dimensions", icon: Ruler },
      { to: "/application-rates", label: "Application Rates", icon: Percent },
    ],
  },
];

// Not rendered in the desktop sidebar (see navGroups above) — it exists so
// Settings routes still resolve a top-bar title via navItemsByPath, and so
// the mobile "More" sheet (which has no separate header) can still reach
// them via moreNavGroups.
const settingsNavGroup: NavGroup = {
  label: "Settings",
  items: [
    { to: "/settings/purchasing", label: "Purchasing Settings", icon: Settings },
    { to: "/settings/inventory", label: "Inventory Settings", icon: SlidersHorizontal },
    { to: "/settings/account", label: "Account", icon: User },
    { to: "/settings/appearance", label: "Appearance", icon: Palette },
    { to: "/settings/printing", label: "Printing", icon: Printer },
  ],
};

const allNavGroups: NavGroup[] = [...navGroups, settingsNavGroup];

// The top-bar Settings menu — split by *who it's for*, not by module, since
// that's the question a person actually has ("is this mine or the
// company's?"): Purchasing/Inventory/Printing apply to everyone in the
// company; Account/Appearance are this person's own.
const SETTINGS_MENU: { label: string; items: NavItem[] }[] = [
  {
    label: "Company",
    items: [
      { to: "/settings/purchasing", label: "Purchasing", icon: Settings },
      { to: "/settings/inventory", label: "Inventory", icon: SlidersHorizontal },
      { to: "/settings/printing", label: "Printing", icon: Printer },
    ],
  },
  {
    label: "You",
    items: [
      { to: "/settings/account", label: "Account", icon: User },
      { to: "/settings/appearance", label: "Appearance", icon: Palette },
    ],
  },
];

const navItems = allNavGroups.flatMap((group) => group.items);
const navPaths = navItems.map((item) => item.to);
const navItemsByPath = new Map(navItems.map((item) => [item.to, item]));

// navGroups[0] is always the standalone Dashboard entry declared above —
// restNavGroups (Purchasing/Inventory/Stock Operations) is exactly what the
// desktop sidebar renders; Settings is never in it.
const [dashboardGroup, ...restNavGroups] = navGroups as [NavGroup, ...NavGroup[]];

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
// Mobile's "More" sheet groups the same way the desktop sidebar does, minus
// whatever groups' whole membership is already covered by the primary tabs —
// plus Settings, which has no other way in on mobile (no top bar there).
const moreNavGroups = allNavGroups
  .map((group) => ({ ...group, items: group.items.filter((item) => !PRIMARY_PATHS.has(item.to)) }))
  .filter((group) => group.items.length > 0);

const MAX_QUICK_LINKS = 4;

const SIDEBAR_LINK_CLASS = ({ isActive }: { isActive: boolean }) =>
  cn(
    "flex min-w-0 flex-1 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground",
    isActive && "bg-nav-active text-nav-active-foreground hover:bg-nav-active hover:text-nav-active-foreground",
  );

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
  const usagePrefs = useNavUsagePrefs(pathname);
  const isDark = useIsDarkMode();
  const setAppearance = useAppearanceStore((state) => state.setUser);

  // `<main>` (not `window`) is the actual scroll container — React Router
  // doesn't reset it on navigation, so without this a route opened while
  // scrolled down on the previous screen stays scrolled down.
  useEffect(() => {
    mainRef.current?.scrollTo(0, 0);
    const ownerPath = resolveNavPath(pathname, navPaths);
    if (ownerPath) recordNavVisit(ownerPath);
  }, [pathname]);

  function handleLogout() {
    logout.mutate(undefined, {
      onSuccess: () => {
        setUser(null);
        navigate("/login", { replace: true });
      },
    });
  }

  const activeNavPath = resolveNavPath(pathname, navPaths);
  const activeNavItem = activeNavPath ? navItemsByPath.get(activeNavPath) : undefined;
  const quickLinkPaths = usagePrefs.quickLinks(["/"], MAX_QUICK_LINKS);

  function renderNavItem(item: NavItem) {
    return (
      <div key={item.to} className="group/navitem relative flex items-center">
        <NavLink to={item.to} end={item.to === "/"} className={SIDEBAR_LINK_CLASS}>
          <item.icon className="size-4 shrink-0" />
          <span className="truncate">{item.label}</span>
        </NavLink>
        <button
          type="button"
          onClick={() => usagePrefs.togglePin(item.to)}
          className="absolute right-2 rounded p-1 text-muted-foreground opacity-0 hover:bg-background hover:text-foreground focus-visible:opacity-100 group-hover/navitem:opacity-100"
          aria-label={usagePrefs.isPinned(item.to) ? `Unpin ${item.label}` : `Pin ${item.label}`}
        >
          {usagePrefs.isPinned(item.to) ? (
            <PinOff className="size-3.5" />
          ) : (
            <Pin className="size-3.5" />
          )}
        </button>
      </div>
    );
  }

  return (
    <TooltipProvider delayDuration={300}>
      <div className="flex h-dvh flex-col overflow-hidden bg-background text-foreground md:flex-row">
      <a
        href="#main-content"
        className="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:left-4 focus-visible:top-4 focus-visible:z-50 focus-visible:rounded-md focus-visible:bg-background focus-visible:px-4 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:ring-2 focus-visible:ring-ring"
      >
        Skip to content
      </a>
      <aside className="hidden w-60 shrink-0 flex-col overflow-y-auto border-r border-border p-4 md:flex">
        <div className="mb-6 text-lg font-semibold">ERP</div>
        <nav className="flex flex-col">
          <div className="flex flex-col gap-1">
            {dashboardGroup.items.map(renderNavItem)}
          </div>

          {quickLinkPaths.length > 0 && (
            <div className="mt-4 flex flex-col gap-1">
              <div className="px-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Quick Access
              </div>
              {quickLinkPaths.flatMap((path) => {
                const item = navItemsByPath.get(path);
                return item ? [renderNavItem(item)] : [];
              })}
            </div>
          )}

          {restNavGroups.map((group, index) => (
            <div key={group.label ?? `group-${index}`} className="mt-4 flex flex-col gap-1">
              {group.label && (
                <div className="px-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                  {group.label}
                </div>
              )}
              {group.items.map(renderNavItem)}
            </div>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <div className="hidden shrink-0 items-center justify-between gap-2 border-b border-border bg-background px-4 py-1.5 md:flex">
          <div className="flex min-w-0 items-center gap-1.5 text-sm font-medium text-foreground">
            {activeNavItem && (
              <>
                <activeNavItem.icon className="size-4 text-muted-foreground" />
                <span className="truncate">{activeNavItem.label}</span>
              </>
            )}
          </div>

          <div className="flex shrink-0 items-center gap-1">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={isDark ? "Switch to light mode" : "Switch to dark mode"}
                  onClick={() => setAppearance({ mode: isDark ? "light" : "dark" })}
                >
                  {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
              </TooltipTrigger>
              <TooltipContent>{isDark ? "Switch to light mode" : "Switch to dark mode"}</TooltipContent>
            </Tooltip>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Settings">
                  <Settings className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                {SETTINGS_MENU.map((group, index) => (
                  <div key={group.label}>
                    {index > 0 && <DropdownMenuSeparator />}
                    <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                    {group.items.map((item) => (
                      <DropdownMenuItem key={item.to} asChild>
                        <NavLink to={item.to} className="cursor-pointer gap-2">
                          <item.icon className="size-4 text-muted-foreground" />
                          {item.label}
                        </NavLink>
                      </DropdownMenuItem>
                    ))}
                  </div>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button
                  type="button"
                  className="ml-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground hover:opacity-90"
                  aria-label="Account menu"
                >
                  {getInitials(user?.name)}
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                  <div className="flex flex-col">
                    <span className="truncate text-sm font-medium text-foreground">{user?.name}</span>
                    <span className="truncate text-xs text-muted-foreground">{user?.email}</span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <NavLink to="/settings/account" className="cursor-pointer gap-2">
                    <User className="size-4 text-muted-foreground" />
                    Account
                  </NavLink>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onSelect={handleLogout}
                  className="cursor-pointer gap-2 text-destructive focus:bg-destructive/10 focus:text-destructive"
                >
                  <LogOut className="size-4" />
                  Log out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <main
          id="main-content"
          ref={mainRef}
          tabIndex={-1}
          className="min-w-0 flex-1 overflow-y-auto pb-[calc(var(--bottom-nav-h)+env(safe-area-inset-bottom))] outline-none md:pb-0"
        >
          {children}
        </main>
      </div>

      <nav
        className="fixed inset-x-0 bottom-0 z-30 flex justify-evenly border-t border-border bg-background md:hidden"
        style={{ paddingBottom: "env(safe-area-inset-bottom)" }}
      >
        {primaryNavItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === "/"}
            className={({ isActive }) =>
              cn(
                "flex min-h-14 min-w-11 flex-col items-center justify-center gap-0.5 px-2 py-2 text-muted-foreground hover:text-foreground",
                isActive && "text-nav-active-foreground",
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
          <nav className="flex max-h-[70vh] flex-col gap-1 overflow-y-auto py-2">
            {moreNavGroups.map((group, index) => (
              <div key={group.label ?? `more-group-${index}`} className={index > 0 ? "mt-3" : undefined}>
                {group.label && (
                  <div className="px-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {group.label}
                  </div>
                )}
                {group.items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === "/"}
                    onClick={() => setMoreOpen(false)}
                    className={({ isActive }) =>
                      cn(
                        "flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium",
                        isActive
                          ? "bg-nav-active text-nav-active-foreground"
                          : "text-foreground hover:bg-accent hover:text-accent-foreground",
                      )
                    }
                  >
                    <item.icon className="size-5" />
                    {item.label}
                  </NavLink>
                ))}
              </div>
            ))}
            <button
              type="button"
              onClick={() => {
                setMoreOpen(false);
                handleLogout();
              }}
              className="mt-3 flex min-h-11 items-center gap-3 rounded-md border-t border-border px-3 pt-3 text-sm font-medium text-destructive hover:bg-accent"
            >
              <LogOut className="size-5" />
              Log out
            </button>
          </nav>
        </SheetContent>
      </Sheet>
      </div>
    </TooltipProvider>
  );
}
