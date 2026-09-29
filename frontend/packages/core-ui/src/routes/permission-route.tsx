import type { ReactNode } from "react";
import { ShieldAlert } from "lucide-react";

import { usePermission } from "../hooks/use-permission";

export function PermissionRoute({
  permission,
  children,
}: {
  permission: string;
  children: ReactNode;
}) {
  const allowed = usePermission(permission);

  if (!allowed) {
    return (
      <div className="flex min-h-full items-center justify-center p-6">
        <div className="max-w-md rounded-lg border border-border bg-card p-6 text-center">
          <ShieldAlert className="mx-auto size-10 text-muted-foreground" />
          <h1 className="mt-4 text-lg font-semibold">You don’t have access</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Ask an administrator for the required permission if you need to use
            this area.
          </p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
