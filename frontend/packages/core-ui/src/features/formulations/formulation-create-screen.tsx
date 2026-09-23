import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useFormulationsStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { FORMULATION_FORM_FIELDS, FormulationForm, formulationPayload, type FormulationFormValues } from './formulation-form';

export function FormulationCreateScreen() {
  const navigate = useNavigate();
  const mutation = useFormulationsStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: FormulationFormValues, form: UseFormReturn<FormulationFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: { output_item_id: Number(v.output_item_id), ...formulationPayload(v) } },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/formulations/${r.data.data.id}/edit`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, FORMULATION_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New formulation"
        description="Define what it takes to produce one unit of an output item."
        breadcrumbs={[{ label: 'Formulations', to: '/formulations' }, { label: 'New' }]}
      />
      <FormulationForm onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
