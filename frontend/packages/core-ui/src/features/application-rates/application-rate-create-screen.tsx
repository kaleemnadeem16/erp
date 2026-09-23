import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useApplicationRatesStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  APPLICATION_RATE_FORM_FIELDS,
  ApplicationRateForm,
  applicationRatePayload,
  type ApplicationRateFormValues,
} from './application-rate-form';

export function ApplicationRateCreateScreen() {
  const navigate = useNavigate();
  const mutation = useApplicationRatesStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: ApplicationRateFormValues, form: UseFormReturn<ApplicationRateFormValues>) => {
    setError(null);
    mutation.mutate(
      {
        data: {
          item_id: Number(v.item_id),
          work_uom_id: Number(v.work_uom_id),
          ...applicationRatePayload(v),
        },
      },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/application-rates/${r.data.data.id}/edit`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, APPLICATION_RATE_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New application rate"
        description="How much of an item is consumed per one unit of work."
        breadcrumbs={[{ label: 'Application rates', to: '/application-rates' }, { label: 'New' }]}
      />
      <ApplicationRateForm mode="create" onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
