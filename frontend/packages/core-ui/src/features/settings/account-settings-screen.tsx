import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useAccountUpdate, useAccountPasswordUpdate } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { PasswordInput } from '../../components/ui/password-input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { useAuthStore } from '../../stores/auth-store';

const profileSchema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(255),
});
type ProfileValues = z.infer<typeof profileSchema>;
const PROFILE_FIELDS = ['name'] as const;

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password'),
    password: z.string().min(8, 'Must be at least 8 characters'),
    password_confirmation: z.string().min(1, 'Confirm your new password'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "New passwords don't match",
    path: ['password_confirmation'],
  });
type PasswordValues = z.infer<typeof passwordSchema>;
const PASSWORD_FIELDS = ['current_password', 'password', 'password_confirmation'] as const;

/** Your own name and password — company-wide policy/printing/appearance settings live elsewhere under Settings. */
export function AccountSettingsScreen() {
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);

  const updateAccount = useAccountUpdate();
  const [profileError, setProfileError] = useState<string | null>(null);
  const [profileSaved, setProfileSaved] = useState(false);

  const profileForm = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { name: user?.name ?? '' },
  });

  useEffect(() => {
    if (user) profileForm.reset({ name: user.name });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.name]);

  function handleProfileSubmit(values: ProfileValues) {
    setProfileError(null);
    setProfileSaved(false);
    updateAccount.mutate(
      { data: values },
      {
        onSuccess: (result) => {
          if (result.status === 200) setUser(result.data.data);
          setProfileSaved(true);
        },
        onError: (error) => setProfileError(applyApiErrorsToForm(error, profileForm.setError, PROFILE_FIELDS)),
      }
    );
  }

  const updatePassword = useAccountPasswordUpdate();
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordSaved, setPasswordSaved] = useState(false);

  const passwordForm = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  });

  function handlePasswordSubmit(values: PasswordValues) {
    setPasswordError(null);
    setPasswordSaved(false);
    updatePassword.mutate(
      { data: values },
      {
        onSuccess: () => {
          passwordForm.reset({ current_password: '', password: '', password_confirmation: '' });
          setPasswordSaved(true);
        },
        onError: (error) => setPasswordError(applyApiErrorsToForm(error, passwordForm.setError, PASSWORD_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader title="Account" description="Your name and password for signing in." />

      <DetailPageLayout>
        <Form {...profileForm}>
          <form className="col-span-full" onSubmit={profileForm.handleSubmit(handleProfileSubmit)}>
            <DetailSection title="Profile" description="Shown across the app wherever your name appears.">
              {profileError && (
                <div className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                  {profileError}
                </div>
              )}
              <FormField
                control={profileForm.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input {...field} autoComplete="name" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="space-y-2">
                <Label>Email</Label>
                <Input value={user?.email ?? ''} disabled readOnly />
              </div>
              <div className="sm:col-span-2 flex items-center justify-end gap-3">
                {profileSaved && !updateAccount.isPending && (
                  <span className="text-sm text-muted-foreground">Saved.</span>
                )}
                <Button type="submit" disabled={updateAccount.isPending}>
                  {updateAccount.isPending ? 'Saving…' : 'Save changes'}
                </Button>
              </div>
            </DetailSection>
          </form>
        </Form>

        <Form {...passwordForm}>
          <form className="col-span-full" onSubmit={passwordForm.handleSubmit(handlePasswordSubmit)}>
            <DetailSection title="Password" description="You'll stay signed in on this device after changing it.">
              {passwordError && (
                <div className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                  {passwordError}
                </div>
              )}
              <FormField
                control={passwordForm.control}
                name="current_password"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Current password</FormLabel>
                    <FormControl>
                      <PasswordInput {...field} autoComplete="current-password" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={passwordForm.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>New password</FormLabel>
                    <FormControl>
                      <PasswordInput {...field} autoComplete="new-password" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={passwordForm.control}
                name="password_confirmation"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Confirm new password</FormLabel>
                    <FormControl>
                      <PasswordInput {...field} autoComplete="new-password" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="sm:col-span-2 flex items-center justify-end gap-3">
                {passwordSaved && !updatePassword.isPending && (
                  <span className="text-sm text-muted-foreground">Password updated.</span>
                )}
                <Button type="submit" disabled={updatePassword.isPending}>
                  {updatePassword.isPending ? 'Saving…' : 'Update password'}
                </Button>
              </div>
            </DetailSection>
          </form>
        </Form>
      </DetailPageLayout>
    </div>
  );
}
