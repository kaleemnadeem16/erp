import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useAssemblyGroupsShow, useAssemblyGroupsUpdate } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  ASSEMBLY_GROUP_FORM_FIELDS,
  AssemblyGroupForm,
  assemblyGroupPayload,
  type AssemblyGroupFormValues,
} from './assembly-group-form';

export function AssemblyGroupEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useAssemblyGroupsShow(id);
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useAssemblyGroupsUpdate();
  const [error, setError] = useState<string | null>(null);

  const submit = (v: AssemblyGroupFormValues, form: UseFormReturn<AssemblyGroupFormValues>) => {
    setError(null);
    mutation.mutate(
      { assemblyGroup: id, data: assemblyGroupPayload(v) },
      { onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_GROUP_FORM_FIELDS)) }
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
        <Button variant="outline" onClick={() => navigate('/assembly-groups')}>
          Back to assembly groups
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Edit ${row.name}`}
        description={row.code}
        breadcrumbs={[{ label: 'Assembly groups', to: '/assembly-groups' }, { label: row.name }]}
      />
      <AssemblyGroupForm
        mode="edit"
        defaultValues={{ code: row.code, name: row.name }}
        onSubmit={submit}
        isSubmitting={mutation.isPending}
        formError={error}
      />
    </div>
  );
}
