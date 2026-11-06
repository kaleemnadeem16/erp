import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { useFormulationsStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { FORMULATION_FORM_FIELDS, FormulationForm, formulationPayload, type FormulationFormValues } from './formulation-form';

/** `/formulations/new`: on saving, the formulation opens on its own record, where its versions are managed. */
export function FormulationCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mutation = useFormulationsStore();
  const [error, setError] = useState<string | null>(null);
  const back = () => navigate({ pathname: '/formulations', search: window.location.search });
  const submit = (v: FormulationFormValues, form: UseFormReturn<FormulationFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: { output_item_id: Number(v.output_item_id), ...formulationPayload(v) } },
      {
        onSuccess: (r) => {
          if (r.status === 201) {
            void queryClient.invalidateQueries();
            navigate({ pathname: `/formulations/${r.data.data.id}/edit`, search: window.location.search });
          }
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, FORMULATION_FORM_FIELDS)),
      }
    );
  };
  return (
    <RecordPresenter presentation={presentation} title="New formulation" description="Define what it takes to produce one unit of an output item." listPath="/formulations" listLabel="Formulations" size="xl" form>
      <FormulationForm onSubmit={submit} onCancel={back} isSubmitting={mutation.isPending} formError={error} />
    </RecordPresenter>
  );
}
