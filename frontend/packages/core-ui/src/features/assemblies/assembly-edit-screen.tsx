import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { OverheadScopeType, useAssembliesShow, useAssembliesUpdate, useOverheadPoliciesResolve } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { OverheadPolicyPanel } from '../overhead-policies/overhead-policy-panel';
import { ASSEMBLY_FORM_FIELDS, AssemblyForm, assemblyPayload, type AssemblyFormValues } from './assembly-form';
import { AssemblyVersionsSection } from './assembly-versions-section';

const RESOLUTION_LABELS: Record<string, string> = {
  ASSEMBLY: 'this assembly',
  ASSEMBLY_GROUP: 'its assembly group',
  COMPANY: 'the company default',
  NONE: 'no policy',
};

export function AssemblyEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useAssembliesShow(id, { include: 'workUom,assemblyGroup,versions,versions.components' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useAssembliesUpdate();
  const [error, setError] = useState<string | null>(null);
  const resolveQuery = useOverheadPoliciesResolve({ assembly_id: id }, { query: { enabled: !!row, retry: false } });
  const resolution = resolveQuery.data?.status === 200 ? resolveQuery.data.data.data : undefined;

  const submit = (v: AssemblyFormValues, form: UseFormReturn<AssemblyFormValues>) => {
    setError(null);
    mutation.mutate(
      { assembly: id, data: assemblyPayload(v) },
      { onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_FORM_FIELDS)) }
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
        <Button variant="outline" onClick={() => navigate('/assemblies')}>
          Back to assemblies
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={row.name}
        description={`${row.code} — ${row.status}`}
        breadcrumbs={[{ label: 'Assemblies', to: '/assemblies' }, { label: row.code }]}
      />
      <AssemblyForm
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
        isSubmitting={mutation.isPending}
        formError={error}
      />
      {resolution && (
        <p className="text-sm text-muted-foreground">
          Effective overhead: resolved from {RESOLUTION_LABELS[resolution.method] ?? resolution.method}
          {resolution.method !== 'NONE' && ` (${resolution.policy?.rate} ${resolution.policy?.driver_type})`}.
        </p>
      )}
      <AssemblyVersionsSection assemblyId={row.id} versions={row.versions ?? []} />
      <DetailPageLayout>
        <OverheadPolicyPanel scopeType={OverheadScopeType.ASSEMBLY} scopeId={row.id} />
      </DetailPageLayout>
    </div>
  );
}
