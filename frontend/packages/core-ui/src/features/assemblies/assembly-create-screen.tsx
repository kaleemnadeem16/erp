import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { useAssembliesStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { ASSEMBLY_FORM_FIELDS, AssemblyForm, assemblyPayload, type AssemblyFormValues } from './assembly-form';

/** `/assemblies/new`: on saving, the assembly opens on its own record, where its versions and overhead are managed. */
export function AssemblyCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mutation = useAssembliesStore();
  const [error, setError] = useState<string | null>(null);
  const back = () => navigate({ pathname: '/assemblies', search: window.location.search });
  const submit = (v: AssemblyFormValues, form: UseFormReturn<AssemblyFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: assemblyPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) {
            void queryClient.invalidateQueries();
            navigate({ pathname: `/assemblies/${r.data.data.id}/edit`, search: window.location.search });
          }
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_FORM_FIELDS)),
      }
    );
  };
  return (
    <RecordPresenter presentation={presentation} title="New assembly" description="Define a new sellable unit of work." listPath="/assemblies" listLabel="Assemblies" size="xl" form>
      <AssemblyForm mode="create" onSubmit={submit} onCancel={back} isSubmitting={mutation.isPending} formError={error} />
    </RecordPresenter>
  );
}
