import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { useRateDimensionsStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import {
  RATE_DIMENSION_FORM_FIELDS,
  RateDimensionForm,
  rateDimensionPayload,
  type RateDimensionFormValues,
} from './rate-dimension-form';

/** `/rate-dimensions/new`: on saving, the new dimension opens on its own record so its values can be added. */
export function RateDimensionCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mutation = useRateDimensionsStore();
  const [error, setError] = useState<string | null>(null);
  const back = () => navigate({ pathname: '/rate-dimensions', search: window.location.search });
  const submit = (v: RateDimensionFormValues, form: UseFormReturn<RateDimensionFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: rateDimensionPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 201) {
            void queryClient.invalidateQueries();
            navigate({ pathname: `/rate-dimensions/${r.data.data.id}/edit`, search: window.location.search });
          }
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, RATE_DIMENSION_FORM_FIELDS)),
      }
    );
  };
  return (
    <RecordPresenter presentation={presentation} title="New rate dimension" description="Define a new axis application rates can vary by." listPath="/rate-dimensions" listLabel="Rate dimensions" size="lg" form>
      <RateDimensionForm mode="create" onSubmit={submit} onCancel={back} isSubmitting={mutation.isPending} formError={error} />
    </RecordPresenter>
  );
}
