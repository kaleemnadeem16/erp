import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { Archive } from 'lucide-react';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useFormulationsDestroy,
  useFormulationsShow,
  useFormulationsUpdate,
  useFormulationsVersionsStore,
  useFormulationVersionsUpdate,
  useFormulationVersionsActivate,
  useFormulationVersionsDestroy,
  type FormulationVersionResource
} from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { RecordPresenter } from '../../components/page/record-presenter';
import { RecordUnavailable } from '../../components/page/record-unavailable';
import { DetailField } from '../../components/detail/detail-field';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { RecipeStatusBadge } from '../estimating/estimating-status';
import { formulationLineSchema, formulationLinesPayload, FormulationLinesFields } from './formulation-lines-fields';
import { FormulationCostBreakdown } from './formulation-cost-breakdown';
import { usePermission } from '../../hooks/use-permission';
import { DecimalInput } from '../../components/ui/decimal-input';

const headerSchema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  output_qty: z.string().refine((v) => !v || (/^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0), 'Enter a positive quantity')
});
type HeaderFormValues = z.infer<typeof headerSchema>;
const HEADER_FIELDS = ['code', 'name', 'output_qty'] as const satisfies readonly (keyof HeaderFormValues)[];

const LIST = '/formulations';

/**
 * `/formulations/:id/edit`: the recipe's header, its versions (revise, edit a draft, activate, delete a draft, view cost) and *Archive*. A workspace, so a wide
 * sheet (decision D7: forms are sheets). Output item and customer are fixed once created.
 */
export function FormulationEditScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mayManage = usePermission('estimating.formulation.manage');
  const query = useFormulationsShow(id, {
    include: 'outputItem,outputUom,customer,versions,versions.lines,versions.lines.inputItem,versions.lines.uom'
  });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const updateMutation = useFormulationsUpdate();
  const archiveMutation = useFormulationsDestroy();
  const [error, setError] = useState<string | null>(null);
  const [confirmArchive, setConfirmArchive] = useState(false);
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  const form = useForm<HeaderFormValues>({
    resolver: zodResolver(headerSchema),
    values: row ? { code: row.code, name: row.name, output_qty: row.output_qty } : undefined
  });

  const submitHeader = (v: HeaderFormValues) => {
    setError(null);
    updateMutation.mutate(
      { formulation: id, data: v },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          back();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, HEADER_FIELDS))
      }
    );
  };

  if (!row) {
    return (
      <RecordPresenter presentation={presentation} title="Formulation" listPath={LIST} listLabel="Formulations" size="xl" form>
        <RecordUnavailable isLoading={query.isLoading} error={query.error} noun="formulation" />
      </RecordPresenter>
    );
  }

  const outputItem = row.output_item ? `${row.output_item.code} — ${row.output_item.name}` : `Item #${row.output_item_id}`;

  return (
    <RecordPresenter
      presentation={presentation}
      title={row.name}
      description={`${row.code} · ${outputItem}`}
      actions={
        <>
          <RecipeStatusBadge status={row.status} />
          {mayManage && row.status !== 'ARCHIVED' && (
            <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmArchive(true)}>
              <Archive aria-hidden="true" className="mr-1.5 h-4 w-4" />
              Archive
            </Button>
          )}
        </>
      }
      listPath={LIST}
      listLabel="Formulations"
      size="xl"
      form
    >
      <Form {...form}>
        <form className="space-y-6" noValidate aria-label="Edit formulation" onSubmit={form.handleSubmit(submitHeader)}>
          {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
          <DetailSection
            title="Formulation"
            description="Output item and customer override are fixed; archive this formulation and create a new one to change either."
          >
            <DetailField label="Output item">{outputItem}</DetailField>
            <DetailField label="Customer">{row.customer?.code ?? 'Company default'}</DetailField>
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Code</FormLabel>
                  <FormControl>
                    <Input className="h-11 md:h-10" {...field} value={field.value ?? ''} />
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
                    <Input className="h-11 md:h-10" {...field} value={field.value ?? ''} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="output_qty"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Output quantity</FormLabel>
                  <FormControl>
                    <DecimalInput className="h-11 md:h-10" rule={{ spec: 'assemblyTechnical', sign: 'positive' }} {...field} value={field.value ?? ''} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </DetailSection>
          <div className="flex items-center justify-end gap-2">
            <Button type="button" variant="outline" className="h-11 md:h-9" disabled={updateMutation.isPending} onClick={back}>
              Cancel
            </Button>
            <Button type="submit" className="h-11 md:h-9" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Saving…' : 'Save formulation'}
            </Button>
          </div>
        </form>
      </Form>
      <FormulationVersions formulationId={id} outputItemId={row.output_item_id} versions={row.versions ?? []} onChanged={() => query.refetch()} />

      <ConfirmActionDialog
        open={confirmArchive}
        onOpenChange={setConfirmArchive}
        title={`Archive ${row.name}?`}
        description={`An archived formulation no longer resolves as the recipe for ${outputItem}, even though its active version stays on record. There is no way to un-archive it: to use this recipe again, create a new formulation.`}
        confirmLabel="Archive formulation"
        pendingLabel="Archiving…"
        cancelLabel="Keep it active"
        failureMessage="The formulation could not be archived."
        testId="archive-formulation-dialog"
        onConfirm={async () => {
          await archiveMutation.mutateAsync({ formulation: id });
          await queryClient.invalidateQueries();
          back();
        }}
      />
    </RecordPresenter>
  );
}

function FormulationVersions({
  formulationId,
  outputItemId,
  versions,
  onChanged
}: {
  formulationId: number;
  outputItemId: number;
  versions: FormulationVersionResource[];
  onChanged: () => void;
}) {
  const mayViewInternalCost = usePermission('estimating.internal_cost.view');
  const [revising, setRevising] = useState(false);
  const [editingVersionId, setEditingVersionId] = useState<number | null>(null);
  const [breakdownVersionId, setBreakdownVersionId] = useState<number | null>(null);
  const activateMutation = useFormulationVersionsActivate();
  const deleteMutation = useFormulationVersionsDestroy();
  const [pendingAction, setPendingAction] = useState<{ kind: 'activate' | 'delete'; version: FormulationVersionResource } | null>(null);

  const sorted = [...versions].sort((a, b) => b.version_no - a.version_no);

  return (
    <DetailSection title="Versions" description="Every revision of this recipe, oldest superseded versions kept forever." fullWidth>
      <div className="space-y-3">
        {sorted.map((version) => (
          <div key={version.id} className="rounded-md border p-3">
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
              <div className="flex gap-2">
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
                {version.status === 'ACTIVE' && mayViewInternalCost && (
                  <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setBreakdownVersionId((v) => (v === version.id ? null : version.id))}>
                    {breakdownVersionId === version.id ? 'Hide cost breakdown' : 'View cost breakdown'}
                  </Button>
                )}
              </div>
            </div>
            {version.notes && <p className="mt-2 text-sm">{version.notes}</p>}
            <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
              {(version.lines ?? []).map((line) => (
                <li key={line.id}>
                  {line.input_item ? `${line.input_item.code} — ${line.input_item.name}` : `Item #${line.input_item_id}`}: {line.qty}{' '}
                  {line.uom ? `${line.uom.code} (${line.uom.name})` : `UoM #${line.uom_id}`}
                  {Number(line.wastage_pct) > 0 && ` (+${line.wastage_pct}% wastage)`}
                </li>
              ))}
              {(version.lines ?? []).length === 0 && <li>No lines yet.</li>}
            </ul>
            {breakdownVersionId === version.id && (
              <div className="mt-3 border-t pt-3">
                <FormulationCostBreakdown versionId={version.id} />
              </div>
            )}
            {editingVersionId === version.id && (
              <DraftVersionForm
                version={version}
                outputItemId={outputItemId}
                onDone={() => {
                  setEditingVersionId(null);
                  onChanged();
                }}
              />
            )}
          </div>
        ))}
      </div>
      {revising ? (
        <NewVersionForm
          formulationId={formulationId}
          outputItemId={outputItemId}
          sourceVersion={sorted.find((version) => version.status === 'ACTIVE')}
          onDone={() => {
            setRevising(false);
            onChanged();
          }}
        />
      ) : (
        <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => setRevising(true)}>
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
              ? 'It becomes the live recipe from today. The version that is active now (if any) is superseded and kept on record. A version needs at least one line, and the system refuses if it would make recipes consume each other in a loop.'
              : 'The draft and its lines are removed for good. Only a draft can be deleted; active and superseded versions are kept forever.'
          }
          confirmLabel={pendingAction.kind === 'activate' ? 'Activate version' : 'Delete draft'}
          pendingLabel={pendingAction.kind === 'activate' ? 'Activating…' : 'Deleting…'}
          cancelLabel="Not now"
          destructive={pendingAction.kind === 'delete'}
          failureMessage={pendingAction.kind === 'activate' ? 'Could not activate this version.' : 'Could not delete this version.'}
          testId={`${pendingAction.kind}-formulation-version-dialog`}
          onConfirm={async () => {
            if (pendingAction.kind === 'activate') await activateMutation.mutateAsync({ formulationVersion: pendingAction.version.id });
            else await deleteMutation.mutateAsync({ formulationVersion: pendingAction.version.id });
            onChanged();
          }}
        />
      )}
    </DetailSection>
  );
}

const newVersionSchema = z.object({
  notes: z.string().max(2000),
  lines: z.array(formulationLineSchema)
});
type NewVersionFormValues = z.infer<typeof newVersionSchema>;

function versionFormValues(version?: FormulationVersionResource): NewVersionFormValues {
  return {
    notes: version?.notes ?? '',
    lines: (version?.lines ?? []).map((line) => ({
      input_item_id: String(line.input_item_id),
      qty: line.qty,
      uom_id: String(line.uom_id),
      wastage_pct: line.wastage_pct
    }))
  };
}

function VersionFields({ form, outputItemId }: { form: UseFormReturn<NewVersionFormValues>; outputItemId: number }) {
  return (
    <>
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
      <FormulationLinesFields control={form.control} excludeItemId={outputItemId} />
    </>
  );
}

function NewVersionForm({
  formulationId,
  outputItemId,
  sourceVersion,
  onDone
}: {
  formulationId: number;
  outputItemId: number;
  sourceVersion?: FormulationVersionResource;
  onDone: () => void;
}) {
  const mutation = useFormulationsVersionsStore();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<NewVersionFormValues>({
    resolver: zodResolver(newVersionSchema),
    defaultValues: versionFormValues(sourceVersion)
  });

  const submit = (v: NewVersionFormValues, f: UseFormReturn<NewVersionFormValues>) => {
    setError(null);
    mutation.mutate(
      {
        formulation: formulationId,
        data: {
          notes: v.notes || null,
          lines: formulationLinesPayload(v.lines)
        }
      },
      {
        onSuccess: () => onDone(),
        onError: (e) => setError(applyApiErrorsToForm(e, f.setError, ['lines']))
      }
    );
  };

  return (
    <Form {...form}>
      <form className="space-y-4 rounded-md border p-4" onSubmit={form.handleSubmit((v) => submit(v, form))}>
        {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <p className="text-sm text-muted-foreground">
          {sourceVersion ? `Copied from active version ${sourceVersion.version_no}.` : 'Start the new recipe below.'}
        </p>
        <VersionFields form={form} outputItemId={outputItemId} />
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

function DraftVersionForm({ version, outputItemId, onDone }: { version: FormulationVersionResource; outputItemId: number; onDone: () => void }) {
  const mutation = useFormulationVersionsUpdate();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<NewVersionFormValues>({
    resolver: zodResolver(newVersionSchema),
    defaultValues: versionFormValues(version)
  });

  return (
    <Form {...form}>
      <form
        className="mt-3 space-y-4 border-t pt-3"
        onSubmit={form.handleSubmit((values) => {
          setError(null);
          mutation.mutate(
            {
              formulationVersion: version.id,
              data: {
                notes: values.notes || null,
                lines: formulationLinesPayload(values.lines)
              }
            },
            {
              onSuccess: onDone,
              onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['notes', 'lines']))
            }
          );
        })}
      >
        {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <VersionFields form={form} outputItemId={outputItemId} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Save draft'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
