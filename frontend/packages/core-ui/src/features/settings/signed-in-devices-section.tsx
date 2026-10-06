import { useState } from "react";
import { LogOut, Smartphone } from "lucide-react";
import {
  useAccountDevicesDestroy,
  useAccountDevicesIndex,
} from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { getApiErrorMessage } from "../../lib/api-errors";
import { formatDateTime } from "../../lib/format-date";

/**
 * The devices signed in to your own account with a device token (for example a phone), and a way to sign one out. A device token lasts
 * 30 days and then you sign in again (ADR 0088); until then this is how you end one you no longer have. Browser sessions are not listed,
 * and the server only ever returns your own devices.
 */
export function SignedInDevicesSection() {
  const devices = useAccountDevicesIndex({ query: { retry: false } });
  const rows = devices.data?.status === 200 ? devices.data.data.data : [];
  const signOut = useAccountDevicesDestroy();
  const [error, setError] = useState<string | null>(null);

  function handleSignOut(id: number) {
    setError(null);
    signOut.mutate(
      { device: id },
      {
        onSuccess: () => void devices.refetch(),
        onError: (e) =>
          setError(getApiErrorMessage(e) ?? "Could not sign that device out."),
      },
    );
  }

  return (
    <DetailSection
      title="Signed-in devices"
      description="Phones and other devices signed in to your account. Each stays signed in for up to 30 days."
    >
      {error && (
        <div
          role="alert"
          className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
          {error}
        </div>
      )}
      {devices.isError ? (
        <p className="sm:col-span-2 text-sm text-muted-foreground">
          Couldn’t load your devices. Try again later.
        </p>
      ) : rows.length === 0 ? (
        <p className="sm:col-span-2 text-sm text-muted-foreground">
          No devices are signed in with a device token. Signing in on this
          browser is not listed here.
        </p>
      ) : (
        <ul
          className="sm:col-span-2 divide-y rounded-md border"
          aria-label="Signed-in devices"
        >
          {rows.map((device) => (
            <li
              key={device.id}
              className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm"
            >
              <div className="min-w-0 space-y-0.5">
                <div className="flex items-center gap-2 font-medium">
                  <Smartphone className="size-4 shrink-0" aria-hidden="true" />
                  <span className="truncate">{device.name}</span>
                  {device.is_current && (
                    <Badge variant="secondary">This device</Badge>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  Signed in {formatDateTime(device.created_at)} · last used{" "}
                  {device.last_used_at
                    ? formatDateTime(device.last_used_at)
                    : "never"}{" "}
                  · signs out on {formatDateTime(device.expires_at)}
                </p>
                {device.user_agent && (
                  <p className="truncate text-xs text-muted-foreground">
                    {device.user_agent}
                  </p>
                )}
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={signOut.isPending}
                onClick={() => handleSignOut(device.id)}
                aria-label={`Sign out ${device.name}`}
              >
                <LogOut className="size-4" />
                Sign out
              </Button>
            </li>
          ))}
        </ul>
      )}
    </DetailSection>
  );
}
