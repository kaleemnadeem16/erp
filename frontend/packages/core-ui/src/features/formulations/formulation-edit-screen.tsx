import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useFormulationsShow,
  useFormulationsUpdate,
  useFormulationsVersionsStore,
  useFormulationVersionsUpdate,
  useFormulationVersionsActivate,
  useFormulationVersionsDestroy,
  type FormulationVersionResource
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Skeleton } from '../../components/ui/skeleton';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { PageHeader } from '../../components/page/page-header';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';
import { formulationLineSchema, formulationLinesPayload, FormulationLinesFields } from './formulation-lines-fields';
import { FormulationCostBreakdown } from './formulation-cost-breakdown';

const headerSchema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  output_qty: z.string().refine((v) => !v || (/^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0), 'Enter a positive quantity')
});
type HeaderFormValues = z.infer<typeof headerSchema>;
const HEADER_FIELDS = ['code', 'name', 'output_qty'] as const satisfies readonly (keyof HeaderFormValues)[];

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'success'> = {
  DRAFT: 'secondary',
  ACTIVE: 'success',
  SUPERSEDED: 'secondary',
  ARCHIVED: 'secondary'
};

export function FormulationEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useFormulationsShow(id, {
    include: 'outputItem,outputUom,customer,versions,versions.lines,versions.lines.inputItem,versions.lines.uom'
  });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const updateMutation = useFormulationsUpdate();
  const [error, setError] = useState<string | null>(null);

  const form = useForm<HeaderFormValues>({
    resolver: zodResolver(headerSchema),
    values: row ? { code: row.code, name: row.name, output_qty: row.output_qty } : undefined
  });

  const submitHeader = (v: HeaderFormValues) => {
    setError(null);
    updateMutation.mutate(
      { formulation: id, data: v },
      {
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, HEADER_FIELDS))
      }
    );
  };

  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  if (!row || query.isError)
    return (
      <div className="p-6">
        <Button variant="outline" onClick={() => navigate('/formulations')}>
          Back to formulations
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={row.name}
        description={`${row.output_item?.code ?? row.output_item_id} — ${row.status}`}
        breadcrumbs={[{ label: 'Formulations', to: '/formulations' }, { label: row.code }]}
      />
      <Form {...form}>
        <form className="space-y-6" onSubmit={form.handleSubmit(submitHeader)}>
          {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
          <DetailSection
            title="Formulation"
            description="Output item and customer override are immutable; archive and create a new formulation to change either."
          >
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Code</FormLabel>
                  <FormControl>
                    <Input {...field} value={field.value ?? ''} />
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
                    <Input {...field} value={field.value ?? ''} />
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
                    <Input inputMode="decimal" {...field} value={field.value ?? ''} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </DetailSection>
          <div className="flex justify-end">
            <Button type="submit" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Saving…' : 'Save formulation'}
            </Button>
          </div>
        </form>
      </Form>
      <FormulationVersions formulationId={id} outputItemId={row.output_item_id} versions={row.versions ?? []} onChanged={() => query.refetch()} />
    </div>
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
  const [revising, setRevising] = useState(false);
  const [editingVersionId, setEditingVersionId] = useState<number | null>(null);
  const [breakdownVersionId, setBreakdownVersionId] = useState<number | null>(null);
  const activateMutation = useFormulationVersionsActivate();
  const deleteMutation = useFormulationVersionsDestroy();
  const [actionError, setActionError] = useState<string | null>(null);

  const sorted = [...versions].sort((a, b) => b.version_no - a.version_no);

  return (
    <DetailSection title="Versions" description="Every revision of this recipe, oldest superseded versions kept forever." fullWidth>
      {actionError && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>}
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
                    <Button type="button" variant="outline" size="sm" onClick={() => setEditingVersionId(version.id)}>
                      Edit draft
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => {
                        setActionError(null);
                        activateMutation.mutate(
                          { formulationVersion: version.id },
                          {
                            onSuccess: onChanged,
                            onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not activate this version.')
                          }
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
                          { formulationVersion: version.id },
                          {
                            onSuccess: onChanged,
                            onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not delete this version.')
                          }
                        );
                      }}
                    >
                      Delete
                    </Button>
                  </>
                )}
                {version.status === 'ACTIVE' && (
                  <Button type="button" variant="outline" size="sm" onClick={() => setBreakdownVersionId((v) => (v === version.id ? null : version.id))}>
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
        <Button type="button" variant="outline" onClick={() => setRevising(true)}>
          Revise — start a new version
        </Button>
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
