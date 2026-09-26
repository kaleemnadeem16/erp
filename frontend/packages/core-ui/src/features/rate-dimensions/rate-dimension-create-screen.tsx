import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useRateDimensionsStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  RATE_DIMENSION_FORM_FIELDS,
  RateDimensionForm,
  rateDimensionPayload,
  type RateDimensionFormValues,
} from './rate-dimension-form';

export function RateDimensionCreateScreen() {
  const navigate = useNavigate();
  const mutation = useRateDimensionsStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: RateDimensionFormValues, form: UseFormReturn<RateDimensionFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: rateDimensionPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/rate-dimensions/${r.data.data.id}/edit`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, RATE_DIMENSION_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New rate dimension"
        description="Define a new axis application rates can vary by."
        breadcrumbs={[{ label: 'Rate dimensions', to: '/rate-dimensions' }, { label: 'New' }]}
      />
      <RateDimensionForm mode="create" onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
