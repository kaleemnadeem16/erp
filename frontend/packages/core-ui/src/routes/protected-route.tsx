import type { ReactNode } from "react";
import { Navigate } from "react-router";

import { useSession } from "../hooks/use-session";

export function ProtectedRoute({ children }: { children: ReactNode }) {
  const { isLoading, isAuthenticated, user } = useSession();

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

  return <>{children}</>;
}
