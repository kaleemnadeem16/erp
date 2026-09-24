import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useAssembliesStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { ASSEMBLY_FORM_FIELDS, AssemblyForm, assemblyPayload, type AssemblyFormValues } from './assembly-form';

export function AssemblyCreateScreen() {
  const navigate = useNavigate();
  const mutation = useAssembliesStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: AssemblyFormValues, form: UseFormReturn<AssemblyFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: assemblyPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/assemblies/${r.data.data.id}/edit`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New assembly"
        description="Define a new sellable unit of work."
        breadcrumbs={[{ label: 'Assemblies', to: '/assemblies' }, { label: 'New' }]}
      />
      <AssemblyForm mode="create" onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
