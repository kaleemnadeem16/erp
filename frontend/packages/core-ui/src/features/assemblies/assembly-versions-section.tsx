import { useState } from 'react';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useAssembliesVersionsStore,
  useAssemblyVersionsActivate,
  useAssemblyVersionsDestroy,
  useAssemblyVersionsUpdate,
  type AssemblyComponentResource,
  type AssemblyVersionResource,
} from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { Textarea } from '../../components/ui/textarea';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  AssemblyComponentsFields,
  assemblyComponentSchema,
  assemblyComponentsPayload,
  emptyAssemblyComponent,
  type AssemblyComponentFormValues,
} from './assembly-component-fields';
import { AssemblyCostBreakdown } from './assembly-cost-breakdown';
import { RecipeStatusBadge } from '../estimating/estimating-status';
import { usePermission } from '../../hooks/use-permission';

const NONE = 'NONE';

const versionSchema = z.object({ notes: z.string().max(2000), components: z.array(assemblyComponentSchema) });
type VersionFormValues = z.infer<typeof versionSchema>;

/** A stored component as the editor's text fields (the inverse of `assemblyComponentsPayload`). */
function componentFormValues(component: AssemblyComponentResource): AssemblyComponentFormValues {
  return {
    kind: component.kind as AssemblyComponentFormValues['kind'],
    item_id: component.item_id ? String(component.item_id) : component.kind === 'MATERIAL' ? NONE : '',
    formulation_id: component.formulation_id ? String(component.formulation_id) : NONE,
    application_rate_id: component.application_rate_id ? String(component.application_rate_id) : NONE,
    qty_per_work_unit: component.qty_per_work_unit,
    uom_id: String(component.uom_id),
    wastage_pct: component.wastage_pct ?? '0',
    multiplier: component.multiplier ?? '1',
    rate_override: component.rate_override ?? '',
  };
}

function versionFormValues(version?: AssemblyVersionResource): VersionFormValues {
  const components = (version?.components ?? []).map(componentFormValues);

  return { notes: version?.notes ?? '', components: components.length > 0 ? components : [emptyAssemblyComponent()] };
}

export function AssemblyVersionsSection({ assemblyId, versions, onChanged }: { assemblyId: number; versions: AssemblyVersionResource[]; onChanged: () => void }) {
  const mayViewCost = usePermission('estimating.internal_cost.view');
  const [creating, setCreating] = useState(false);
  const [editingVersionId, setEditingVersionId] = useState<number | null>(null);
  const [breakdownVersionId, setBreakdownVersionId] = useState<number | null>(null);
  const activateMutation = useAssemblyVersionsActivate();
  const deleteMutation = useAssemblyVersionsDestroy();
  const [pendingAction, setPendingAction] = useState<{ kind: 'activate' | 'delete'; version: AssemblyVersionResource } | null>(null);

  const sorted = [...versions].sort((a, b) => b.version_no - a.version_no);

  return (
    <DetailSection
      title="Versions"
      description="The assembly's recipe — material, labour, plant and subcontract components. Overhead is never entered here; it's resolved from the overhead policy below at cost time."
      fullWidth
    >
      <div className="space-y-3">
        {sorted.map((version) => (
          <div key={version.id} className="rounded-md border p-3" data-testid={`assembly-version-${version.version_no}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <span className="font-medium">Version {version.version_no}</span>
                <RecipeStatusBadge status={version.status} />
                {version.effective_from && (
                  <span className="text-xs text-muted-foreground">
                    from {version.effective_from}
                    {version.effective_to ? ` to ${version.effective_to}` : ''}
                  </span>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                {version.status === 'DRAFT' && (
                  <>
                    <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setEditingVersionId(version.id)}>
                      Edit draft
                    </Button>
                    <Button type="button" size="sm" className="h-11 md:h-8" onClick={() => setPendingAction({ kind: 'activate', version })}>
                      Activate
                    </Button>
                    <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setPendingAction({ kind: 'delete', version })}>
                      Delete
                    </Button>
                  </>
                )}
                {version.status === 'ACTIVE' && mayViewCost && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-11 md:h-8"
                    onClick={() => setBreakdownVersionId((v) => (v === version.id ? null : version.id))}
                  >
                    {breakdownVersionId === version.id ? 'Hide cost breakdown' : 'View cost breakdown'}
                  </Button>
                )}
              </div>
            </div>
            {version.notes && <p className="mt-2 text-sm">{version.notes}</p>}
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
            {editingVersionId === version.id && (
              <DraftAssemblyVersionForm
                version={version}
                onDone={() => {
                  setEditingVersionId(null);
                  onChanged();
                }}
              />
            )}
          </div>
        ))}
      </div>
      {creating ? (
        <NewAssemblyVersionForm
          assemblyId={assemblyId}
          sourceVersion={sorted.find((version) => version.status === 'ACTIVE') ?? sorted[0]}
          onDone={() => {
            setCreating(false);
            onChanged();
          }}
        />
      ) : (
        <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => setCreating(true)}>
          Revise — start a new version
        </Button>
      )}

      {pendingAction && (
        <ConfirmActionDialog
          open
          onOpenChange={(next) => !next && setPendingAction(null)}
          title={pendingAction.kind === 'activate' ? `Activate version ${pendingAction.version.version_no}?` : `Delete draft version ${pendingAction.version.version_no}?`}
          description={
            pendingAction.kind === 'activate'
              ? 'It becomes the live recipe from today. The version that is active now (if any) is superseded and kept on record.'
              : 'The draft and its components are removed for good. Only a draft can be deleted; active and superseded versions are kept forever.'
          }
          confirmLabel={pendingAction.kind === 'activate' ? 'Activate version' : 'Delete draft'}
          pendingLabel={pendingAction.kind === 'activate' ? 'Activating…' : 'Deleting…'}
          cancelLabel="Not now"
          destructive={pendingAction.kind === 'delete'}
          failureMessage={pendingAction.kind === 'activate' ? 'Could not activate this version.' : 'Could not delete this version.'}
          testId={`${pendingAction.kind}-assembly-version-dialog`}
          onConfirm={async () => {
            if (pendingAction.kind === 'activate') await activateMutation.mutateAsync({ assemblyVersion: pendingAction.version.id });
            else await deleteMutation.mutateAsync({ assemblyVersion: pendingAction.version.id });
            onChanged();
          }}
        />
      )}
    </DetailSection>
  );
}

function NotesField({ form }: { form: UseFormReturn<VersionFormValues> }) {
  return (
    <FormField
      control={form.control}
      name="notes"
      render={({ field }) => (
        <FormItem>
          <FormLabel>Version notes</FormLabel>
          <FormControl>
            <Textarea rows={2} placeholder="What changed, and why?" {...field} value={field.value ?? ''} />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function NewAssemblyVersionForm({ assemblyId, sourceVersion, onDone }: { assemblyId: number; sourceVersion?: AssemblyVersionResource; onDone: () => void }) {
  const mutation = useAssembliesVersionsStore();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<VersionFormValues>({
    resolver: zodResolver(versionSchema),
    // A revision starts from the live recipe (or the latest one) rather than from a blank page; the notes are for this revision, so they start empty.
    defaultValues: { ...versionFormValues(sourceVersion), notes: '' },
  });

  const submit = (v: VersionFormValues, f: UseFormReturn<VersionFormValues>) => {
    setError(null);
    mutation.mutate(
      { assembly: assemblyId, data: { notes: v.notes || null, components: assemblyComponentsPayload(v.components as AssemblyComponentFormValues[]) } },
      {
        onSuccess: () => onDone(),
        onError: (e) => setError(applyApiErrorsToForm(e, f.setError, ['notes', 'components'])),
      }
    );
  };

  return (
    <Form {...form}>
      <form className="space-y-4 rounded-md border p-4" noValidate aria-label="New assembly version" onSubmit={form.handleSubmit((v) => submit(v, form))}>
        {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <p className="text-sm text-muted-foreground">
          {sourceVersion ? `Copied from version ${sourceVersion.version_no}.` : 'Start the new recipe below.'}
        </p>
        <NotesField form={form} />
        <AssemblyComponentsFields control={form.control} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" className="h-11 md:h-9" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" className="h-11 md:h-9" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Create draft version'}
          </Button>
        </div>
      </form>
    </Form>
  );
}

/** Edit a draft's notes and components in place (only a DRAFT may have its components changed; an active one is revised instead). */
function DraftAssemblyVersionForm({ version, onDone }: { version: AssemblyVersionResource; onDone: () => void }) {
  const mutation = useAssemblyVersionsUpdate();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<VersionFormValues>({
    resolver: zodResolver(versionSchema),
    defaultValues: versionFormValues(version),
  });

  return (
    <Form {...form}>
      <form
        className="mt-3 space-y-4 border-t pt-3"
        noValidate
        aria-label={`Edit draft version ${version.version_no}`}
        onSubmit={form.handleSubmit((values) => {
          setError(null);
          mutation.mutate(
            {
              assemblyVersion: version.id,
              data: { notes: values.notes || null, components: assemblyComponentsPayload(values.components as AssemblyComponentFormValues[]) },
            },
            {
              onSuccess: onDone,
              onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['notes', 'components'])),
            }
          );
        })}
      >
        {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <NotesField form={form} />
        <AssemblyComponentsFields control={form.control} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" className="h-11 md:h-9" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" className="h-11 md:h-9" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Save draft'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
