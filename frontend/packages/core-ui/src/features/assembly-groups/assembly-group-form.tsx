import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const schema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
});

export type AssemblyGroupFormValues = z.infer<typeof schema>;

export const ASSEMBLY_GROUP_FORM_FIELDS = [
  'code',
  'name',
] as const satisfies readonly (keyof AssemblyGroupFormValues)[];

export function assemblyGroupPayload(v: AssemblyGroupFormValues) {
  return { code: v.code.trim(), name: v.name.trim() };
}

export function AssemblyGroupForm({
  mode,
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<AssemblyGroupFormValues>;
  onSubmit: (v: AssemblyGroupFormValues, f: UseFormReturn<AssemblyGroupFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<AssemblyGroupFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { code: '', name: '', ...defaultValues },
  });
  return (
    <Form {...form}>
      <form className="space-y-6" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection
          title="Assembly group"
          description="A company-defined classification of assemblies, used to share overhead-policy configuration. Optional — assemblies don't need one."
        >
          <FormField
            control={form.control}
            name="code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Code</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Name</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create group' : 'Save group'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
