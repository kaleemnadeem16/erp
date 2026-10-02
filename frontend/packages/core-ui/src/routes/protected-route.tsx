import type { ReactNode } from "react";
import { Navigate, useLocation } from "react-router";

import { useSession } from "../hooks/use-session";
import { canAccessScreen, homeScreen } from "./screen-access";
import { AccessDenied } from "./permission-route";

export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { isLoading, isAuthenticated, user } = useSession();
  const { pathname } = useLocation();

  if (isLoading) {
    return (
      <div className="flex h-dvh items-center justify-center text-muted-foreground">
        Loading…
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (
    (user as { must_change_password?: boolean } | null)?.must_change_password
  ) {
    return <Navigate to="/change-password" replace />;
  }

  const permissions = new Set(
    Array.isArray(user?.permissions)
      ? user.permissions.filter(
          (permission): permission is string => typeof permission === "string",
        )
      : [],
  );
  if (pathname === "/" && !canAccessScreen("/", permissions)) {
    return <Navigate to={homeScreen(permissions)} replace />;
  }
  if (!canAccessScreen(pathname, permissions)) {
    return <AccessDenied />;
  }

  return <>{children}</>;
}
