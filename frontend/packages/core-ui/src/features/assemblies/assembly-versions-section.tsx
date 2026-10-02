import { useState } from 'react';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useAssembliesVersionsStore,
  useAssemblyVersionsActivate,
  useAssemblyVersionsDestroy,
  type AssemblyVersionResource,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Form } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';
import {
  AssemblyComponentsFields,
  assemblyComponentSchema,
  assemblyComponentsPayload,
  emptyAssemblyComponent,
  type AssemblyComponentFormValues,
} from './assembly-component-fields';
import { AssemblyCostBreakdown } from './assembly-cost-breakdown';
import { usePermission } from '../../hooks/use-permission';

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'success'> = {
  DRAFT: 'secondary',
  ACTIVE: 'success',
  SUPERSEDED: 'secondary',
};

const versionSchema = z.object({ components: z.array(assemblyComponentSchema) });
type VersionFormValues = z.infer<typeof versionSchema>;

export function AssemblyVersionsSection({ assemblyId, versions }: { assemblyId: number; versions: AssemblyVersionResource[] }) {
  const mayViewCost = usePermission('estimating.internal_cost.view');
  const [creating, setCreating] = useState(false);
  const [breakdownVersionId, setBreakdownVersionId] = useState<number | null>(null);
  const activateMutation = useAssemblyVersionsActivate();
  const deleteMutation = useAssemblyVersionsDestroy();
  const [actionError, setActionError] = useState<string | null>(null);

  const sorted = [...versions].sort((a, b) => b.version_no - a.version_no);

  return (
    <DetailSection
      title="Versions"
      description="The assembly's recipe — material, labour, plant and subcontract components. Overhead is never entered here; it's resolved from the overhead policy above at cost time."
      fullWidth
    >
      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}
      <div className="space-y-3">
        {sorted.map((version) => (
          <div key={version.id} className="rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <span className="font-medium">Version {version.version_no}</span>
                <Badge variant={STATUS_VARIANT[version.status] ?? 'default'}>{version.status}</Badge>
                {version.effective_from && (
                  <span className="text-xs text-muted-foreground">
                    from {version.effective_from}
                    {version.effective_to ? ` to ${version.effective_to}` : ''}
                  </span>
                )}
              </div>
              <div className="flex gap-2">
                {version.status === 'DRAFT' && (
                  <>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => {
                        setActionError(null);
                        activateMutation.mutate(
                          { assemblyVersion: version.id },
                          { onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not activate this version.') }
                        );
                      }}
                    >
                      Activate
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setActionError(null);
                        deleteMutation.mutate(
                          { assemblyVersion: version.id },
                          { onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not delete this version.') }
                        );
                      }}
                    >
                      Delete
                    </Button>
                  </>
                )}
                {version.status === 'ACTIVE' && mayViewCost && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setBreakdownVersionId((v) => (v === version.id ? null : version.id))}
                  >
                    {breakdownVersionId === version.id ? 'Hide cost breakdown' : 'View cost breakdown'}
                  </Button>
                )}
              </div>
            </div>
            <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
              {(version.components ?? []).map((component) => (
                <li key={component.id}>
                  {component.kind}
                  {component.item ? ` — ${component.item.code}` : ''}: {component.qty_per_work_unit}
                </li>
              ))}
              {(version.components ?? []).length === 0 && <li>No components yet.</li>}
            </ul>
            {breakdownVersionId === version.id && (
              <div className="mt-3 border-t pt-3">
                <AssemblyCostBreakdown versionId={version.id} />
              </div>
            )}
          </div>
        ))}
      </div>
      {creating ? (
        <NewAssemblyVersionForm assemblyId={assemblyId} onDone={() => setCreating(false)} />
      ) : (
        <Button type="button" variant="outline" onClick={() => setCreating(true)}>
          Revise — start a new version
        </Button>
      )}
    </DetailSection>
  );
}

function NewAssemblyVersionForm({ assemblyId, onDone }: { assemblyId: number; onDone: () => void }) {
  const mutation = useAssembliesVersionsStore();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<VersionFormValues>({
    resolver: zodResolver(versionSchema),
    defaultValues: { components: [emptyAssemblyComponent()] },
  });

  const submit = (v: VersionFormValues, f: UseFormReturn<VersionFormValues>) => {
    setError(null);
    mutation.mutate(
      { assembly: assemblyId, data: { components: assemblyComponentsPayload(v.components as AssemblyComponentFormValues[]) } },
      {
        onSuccess: () => onDone(),
        onError: (e) => setError(applyApiErrorsToForm(e, f.setError, ['components'])),
      }
    );
  };

  return (
    <Form {...form}>
      <form className="space-y-4 rounded-md border p-4" onSubmit={form.handleSubmit((v) => submit(v, form))}>
        {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <AssemblyComponentsFields control={form.control} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Create draft version'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
