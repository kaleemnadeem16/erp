import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { Archive } from 'lucide-react';
import { OverheadScopeType, useAssembliesDestroy, useAssembliesShow, useAssembliesUpdate, useOverheadPoliciesResolve } from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { RecordPresenter } from '../../components/page/record-presenter';
import { RecordUnavailable } from '../../components/page/record-unavailable';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { RecipeStatusBadge } from '../estimating/estimating-status';
import { OverheadPolicyPanel } from '../overhead-policies/overhead-policy-panel';
import { ASSEMBLY_FORM_FIELDS, AssemblyForm, assemblyPayload, type AssemblyFormValues } from './assembly-form';
import { AssemblyVersionsSection } from './assembly-versions-section';

const LIST = '/assemblies';

const RESOLUTION_LABELS: Record<string, string> = {
  ASSEMBLY: 'this assembly',
  ASSEMBLY_GROUP: 'its assembly group',
  COMPANY: 'the company default',
  NONE: 'no policy',
};

/**
 * `/assemblies/:id/edit`: the assembly's header, its versions (revise, edit a draft, activate, delete a draft, view cost), its overhead policy and *Archive*.
 * A workspace, so a wide sheet (decision D7: forms are sheets).
 */
export function AssemblyEditScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const query = useAssembliesShow(id, { include: 'workUom,assemblyGroup,versions,versions.components' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useAssembliesUpdate();
  const archiveMutation = useAssembliesDestroy();
  const [error, setError] = useState<string | null>(null);
  const [confirmArchive, setConfirmArchive] = useState(false);
  const resolveQuery = useOverheadPoliciesResolve({ assembly_id: id }, { query: { enabled: !!row, retry: false } });
  const resolution = resolveQuery.data?.status === 200 ? resolveQuery.data.data.data : undefined;
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  const submit = (v: AssemblyFormValues, form: UseFormReturn<AssemblyFormValues>) => {
    setError(null);
    mutation.mutate(
      { assembly: id, data: assemblyPayload(v) },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          back();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_FORM_FIELDS)),
      }
    );
  };

  if (!row) {
    return (
      <RecordPresenter presentation={presentation} title="Assembly" listPath={LIST} listLabel="Assemblies" size="xl" form>
        <RecordUnavailable isLoading={query.isLoading} error={query.error} noun="assembly" />
      </RecordPresenter>
    );
  }

  if (row.margin_type == null || row.margin_pct == null) {
    return (
      <RecordPresenter presentation={presentation} title={row.name} description={row.code} listPath={LIST} listLabel="Assemblies" size="xl">
        <p role="alert" className="text-sm text-destructive">
          You cannot edit this assembly without access to its margin settings.
        </p>
      </RecordPresenter>
    );
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title={row.name}
      description={row.code}
      actions={
        <>
          <RecipeStatusBadge status={row.status} />
          {row.status !== 'ARCHIVED' && (
            <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmArchive(true)}>
              <Archive aria-hidden="true" className="mr-1.5 h-4 w-4" />
              Archive
            </Button>
          )}
        </>
      }
      listPath={LIST}
      listLabel="Assemblies"
      size="xl"
      form
    >
      <AssemblyForm
        key={`${row.id}-${row.updated_at}`}
        mode="edit"
        defaultValues={{
          code: row.code,
          name: row.name,
          description: row.description ?? '',
          work_uom_id: String(row.work_uom_id),
          assembly_group_id: row.assembly_group_id ? String(row.assembly_group_id) : 'NONE',
          margin_type: row.margin_type as AssemblyFormValues['margin_type'],
          margin_pct: row.margin_pct,
        }}
        onSubmit={submit}
        onCancel={back}
        isSubmitting={mutation.isPending}
        formError={error}
      />
      {resolution && (
        <p className="text-sm text-muted-foreground">
          Effective overhead: resolved from {RESOLUTION_LABELS[resolution.method] ?? resolution.method}
          {resolution.method !== 'NONE' && resolution.policy?.rate != null && ` (${resolution.policy.rate} ${resolution.policy.driver_type})`}.
        </p>
      )}
      <AssemblyVersionsSection assemblyId={row.id} versions={row.versions ?? []} onChanged={() => void query.refetch()} />
      <OverheadPolicyPanel scopeType={OverheadScopeType.ASSEMBLY} scopeId={row.id} />

      <ConfirmActionDialog
        open={confirmArchive}
        onOpenChange={setConfirmArchive}
        title={`Archive ${row.name}?`}
        description="The assembly is retired and marked Archived; its versions stay on record. There is no way to un-archive it: to use this unit of work again, create a new assembly."
        confirmLabel="Archive assembly"
        pendingLabel="Archiving…"
        cancelLabel="Keep it active"
        failureMessage="The assembly could not be archived."
        testId="archive-assembly-dialog"
        onConfirm={async () => {
          await archiveMutation.mutateAsync({ assembly: id });
          await queryClient.invalidateQueries();
          back();
        }}
      />
    </RecordPresenter>
  );
}
