import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useMaterialVarianceReasonsIndex,
  useMaterialVarianceReasonsStore,
  useMaterialVarianceReasonsUpdate,
  type MaterialVarianceReasonResource,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';

const schema = z.object({
  code: z.string().trim().min(1, 'A short code is required').max(64).regex(/^[A-Za-z0-9][A-Za-z0-9_-]*$/, 'Letters, numbers, dashes and underscores only'),
  name: z.string().trim().min(1, 'A name is required').max(255),
  description: z.string().trim().max(1000),
});
type FormValues = z.infer<typeof schema>;
const FIELDS = ['code', 'name', 'description'] as const;

/**
 * The company's own list of reasons a planned quantity may differ from the calculated standard. Nothing is
 * built in: what counts as a reason is the company's call. A reason is retired, never deleted, because
 * adopted plans refer to it; a retired reason is no longer offered but stays readable.
 */
export function ReasonsPanel({ mayManage }: { mayManage: boolean }) {
  const [adding, setAdding] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const query = useMaterialVarianceReasonsIndex({ per_page: 100, sort: 'name' });
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const store = useMaterialVarianceReasonsStore();
  const update = useMaterialVarianceReasonsUpdate();
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { code: '', name: '', description: '' } });

  function submit(values: FormValues) {
    setActionError(null);
    store.mutate(
      { data: { code: values.code, name: values.name, description: values.description || null } },
      {
        onSuccess: () => {
          setAdding(false);
          form.reset();
          void query.refetch();
        },
        onError: (e) => setActionError(applyApiErrorsToForm(e, form.setError, FIELDS)),
      },
    );
  }

  function setActive(reason: MaterialVarianceReasonResource, active: boolean) {
    setActionError(null);
    update.mutate(
      { materialVarianceReason: reason.id, data: { active } },
      {
        onSuccess: () => void query.refetch(),
        onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not change this reason.'),
      },
    );
  }

  return (
    <DetailSection
      title="Reasons"
      description="Why a planned quantity may differ from the calculated standard. Your company decides the list; a reason is retired rather than deleted so past plans keep reading."
      fullWidth
    >
      <div className="space-y-3 sm:col-span-2">
        {actionError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>}

        {query.isError ? (
          <ListError message="Couldn't load the reasons." error={query.error} onRetry={() => void query.refetch()} />
        ) : query.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">No reasons yet. Until you add some, a plan that needs a reason cannot be given one.</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {rows.map((reason) => (
              <li key={reason.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                <div className={reason.active ? '' : 'text-muted-foreground'}>
                  <span className="font-medium">{reason.name}</span> <span className="text-xs text-muted-foreground">({reason.code})</span>
                  {reason.description && <p className="text-xs text-muted-foreground">{reason.description}</p>}
                </div>
                <div className="flex items-center gap-2">
                  {!reason.active && <Badge variant="secondary">Retired</Badge>}
                  {mayManage && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={update.isPending}
                      onClick={() => setActive(reason, !reason.active)}
                      aria-label={`${reason.active ? 'Retire' : 'Reinstate'} ${reason.name}`}
                    >
                      {reason.active ? 'Retire' : 'Reinstate'}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {mayManage &&
          (adding ? (
            <Form {...form}>
              <form className="space-y-3 rounded-md border p-3" onSubmit={form.handleSubmit(submit)}>
                <div className="grid gap-3 sm:grid-cols-2">
                  <FormField control={form.control} name="name" render={({ field }) => (
                    <FormItem><FormLabel>Name</FormLabel><FormControl><Input {...field} placeholder="e.g. Porous surface" /></FormControl><FormMessage /></FormItem>
                  )} />
                  <FormField control={form.control} name="code" render={({ field }) => (
                    <FormItem><FormLabel>Code</FormLabel><FormControl><Input {...field} placeholder="e.g. porous-surface" /></FormControl><FormMessage /></FormItem>
                  )} />
                </div>
                <FormField control={form.control} name="description" render={({ field }) => (
                  <FormItem><FormLabel>Description (optional)</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
                )} />
                <p className="text-xs text-muted-foreground">The code cannot be changed once saved; past plans refer to it.</p>
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button>
                  <Button type="submit" disabled={store.isPending}>{store.isPending ? 'Saving…' : 'Add reason'}</Button>
                </div>
              </form>
            </Form>
          ) : (
            <Button type="button" variant="outline" onClick={() => setAdding(true)}>Add a reason</Button>
          ))}
      </div>
    </DetailSection>
  );
}
