import { useState } from "react";
import { Link, useSearchParams } from "react-router";
import { usePasswordReset } from "@erp/core-api";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { Button } from "../../components/ui/button";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { PasswordInput } from "../../components/ui/password-input";
import { applyApiErrorsToForm } from "../../lib/api-errors";

const schema = z
  .object({
    password: z.string().min(8),
    password_confirmation: z.string().min(8),
  })
  .refine((value) => value.password === value.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords don't match",
  });
type Values = z.infer<typeof schema>;

export function AcceptInvitationScreen({
  mode = "invitation",
}: {
  mode?: "invitation" | "reset";
}) {
  const [params] = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const reset = usePasswordReset();
  const [complete, setComplete] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  const submit = (values: Values) => {
    setError(null);
    reset.mutate(
      { data: { ...values, token, email } },
      {
        onSuccess: () => setComplete(true),
        onError: (cause) =>
          setError(
            applyApiErrorsToForm(cause, form.setError, [
              "password",
              "password_confirmation",
            ]),
          ),
      },
    );
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
      <section className="w-full max-w-md space-y-6 rounded-xl border bg-card p-6 shadow-sm">
        <div>
          <h1 className="text-2xl font-semibold">
            {mode === "reset" ? "Reset your password" : "Activate your account"}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Set your password for {email || "this account"}.
          </p>
        </div>
        {complete ? (
          <div className="space-y-4">
            <p>
              {mode === "reset"
                ? "Your password has been updated."
                : "Your account is active."}
            </p>
            <Button asChild className="w-full">
              <Link to="/login">Continue to sign in</Link>
            </Button>
          </div>
        ) : (
          <Form {...form}>
            <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
              {(!token || !email) && (
                <p className="text-sm text-destructive">
                  This {mode === "reset" ? "reset" : "invitation"} link is
                  incomplete.
                </p>
              )}
              {error && <p className="text-sm text-destructive">{error}</p>}
              <FormField
                control={form.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Password</FormLabel>
                    <FormControl>
                      <PasswordInput autoComplete="new-password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="password_confirmation"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Confirm password</FormLabel>
                    <FormControl>
                      <PasswordInput autoComplete="new-password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <Button
                className="w-full"
                type="submit"
                disabled={!token || !email || reset.isPending}
              >
                {reset.isPending
                  ? "Saving…"
                  : mode === "reset"
                    ? "Reset password"
                    : "Activate account"}
              </Button>
            </form>
          </Form>
        )}
      </section>
    </main>
  );
}
