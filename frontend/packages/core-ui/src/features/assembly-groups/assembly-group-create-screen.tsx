import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { useAssemblyGroupsStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import {
  ASSEMBLY_GROUP_FORM_FIELDS,
  AssemblyGroupForm,
  assemblyGroupPayload,
  type AssemblyGroupFormValues,
} from './assembly-group-form';

/** `/assembly-groups/new`: on saving, the new group opens on its own record so its overhead policy can be set. */
export function AssemblyGroupCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mutation = useAssemblyGroupsStore();
  const [error, setError] = useState<string | null>(null);
  const back = () => navigate({ pathname: '/assembly-groups', search: window.location.search });
  const submit = (v: AssemblyGroupFormValues, form: UseFormReturn<AssemblyGroupFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: assemblyGroupPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) {
            void queryClient.invalidateQueries();
            navigate({ pathname: `/assembly-groups/${r.data.data.id}/edit`, search: window.location.search });
          }
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_GROUP_FORM_FIELDS)),
      }
    );
  };
  return (
    <RecordPresenter presentation={presentation} title="New assembly group" description="Define a new classification assemblies can be grouped under." listPath="/assembly-groups" listLabel="Assembly groups" size="lg" form>
      <AssemblyGroupForm mode="create" onSubmit={submit} onCancel={back} isSubmitting={mutation.isPending} formError={error} />
    </RecordPresenter>
  );
}
