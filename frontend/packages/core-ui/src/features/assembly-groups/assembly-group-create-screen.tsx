import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useAssemblyGroupsStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  ASSEMBLY_GROUP_FORM_FIELDS,
  AssemblyGroupForm,
  assemblyGroupPayload,
  type AssemblyGroupFormValues,
} from './assembly-group-form';

export function AssemblyGroupCreateScreen() {
  const navigate = useNavigate();
  const mutation = useAssemblyGroupsStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: AssemblyGroupFormValues, form: UseFormReturn<AssemblyGroupFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: assemblyGroupPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/assembly-groups/${r.data.data.id}/edit`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_GROUP_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New assembly group"
        description="Define a new classification assemblies can be grouped under."
        breadcrumbs={[{ label: 'Assembly groups', to: '/assembly-groups' }, { label: 'New' }]}
      />
      <AssemblyGroupForm mode="create" onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
