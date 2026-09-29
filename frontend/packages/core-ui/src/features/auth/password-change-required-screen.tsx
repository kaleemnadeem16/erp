import { useState, type FormEvent } from "react";
import { useNavigate, Navigate } from "react-router";
import { useAccountPasswordUpdate } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { getApiErrorMessage } from "../../lib/api-errors";
import { useSession } from "../../hooks/use-session";

export function PasswordChangeRequiredScreen() {
  const { user, isAuthenticated, isLoading } = useSession();
  const navigate = useNavigate();
  const updatePassword = useAccountPasswordUpdate();
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);

  if (isLoading) return <div className="p-6">Loading…</div>;
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (
    !(user as { must_change_password?: boolean } | null)?.must_change_password
  )
    return <Navigate to="/" replace />;

  function submit(event: FormEvent) {
    event.preventDefault();
    if (password !== confirmation) {
      setError("Passwords do not match.");
      return;
    }
    setError(null);
    updatePassword.mutate(
      {
        data: {
          current_password: currentPassword,
          password,
          password_confirmation: confirmation,
        },
      },
      {
        onSuccess: () => {
          window.location.assign("/");
        },
        onError: (requestError) => setError(getApiErrorMessage(requestError)),
      },
    );
  }

  return (
    <main className="flex min-h-dvh items-center justify-center p-4">
      <form
        onSubmit={submit}
        className="w-full max-w-md space-y-4 rounded-lg border p-6"
      >
        <h1 className="text-lg font-semibold">Change temporary password</h1>
        <p className="text-sm text-muted-foreground">
          Set a private password before using the application.
        </p>
        <div className="space-y-1">
          <Label htmlFor="temporary-password">Temporary password</Label>
          <Input
            id="temporary-password"
            type="password"
            autoComplete="current-password"
            required
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor="new-password">New password</Label>
          <Input
            id="new-password"
            type="password"
            autoComplete="new-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor="confirm-password">Confirm new password</Label>
          <Input
            id="confirm-password"
            type="password"
            autoComplete="new-password"
            required
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
          />
        </div>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <Button type="submit" disabled={updatePassword.isPending}>
          {updatePassword.isPending ? "Saving…" : "Save password"}
        </Button>
      </form>
    </main>
  );
}
