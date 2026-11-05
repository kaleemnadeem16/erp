import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { useApplicationRatesStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import {
  APPLICATION_RATE_FORM_FIELDS,
  ApplicationRateForm,
  applicationRatePayload,
  type ApplicationRateFormValues,
} from './application-rate-form';

/** `/application-rates/new`: on saving, the rate opens on its own record. */
export function ApplicationRateCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mutation = useApplicationRatesStore();
  const [error, setError] = useState<string | null>(null);
  const back = () => navigate({ pathname: '/application-rates', search: window.location.search });
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
          if (r.status === 201) {
            void queryClient.invalidateQueries();
            navigate({ pathname: `/application-rates/${r.data.data.id}/edit`, search: window.location.search });
          }
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, APPLICATION_RATE_FORM_FIELDS)),
      }
    );
  };
  return (
    <RecordPresenter presentation={presentation} title="New application rate" description="How much of an item is consumed per one unit of work." listPath="/application-rates" listLabel="Application rates" size="lg" form>
      <ApplicationRateForm mode="create" onSubmit={submit} onCancel={back} isSubmitting={mutation.isPending} formError={error} />
    </RecordPresenter>
  );
}
