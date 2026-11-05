import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { usePhysicalCountsStore } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import type { Presentation } from '../../lib/presentation';

import { applyApiErrorsToForm } from '../../lib/api-errors';
import { PhysicalCountForm, PHYSICAL_COUNT_FORM_FIELDS, type PhysicalCountFormValues } from './physical-count-form';

export function PhysicalCountCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const [formError, setFormError] = useState<string | null>(null);
  const createCount = usePhysicalCountsStore();

  function handleSubmit(values: PhysicalCountFormValues, form: UseFormReturn<PhysicalCountFormValues>) {
    setFormError(null);
    createCount.mutate(
      { data: { warehouse_id: Number(values.warehouse_id), reference: values.reference?.trim() || undefined } },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate({ pathname: `/physical-counts/${result.data.data.id}`, search });
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, PHYSICAL_COUNT_FORM_FIELDS)),
      }
    );
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title="New physical count"
      description="Start a count against one warehouse. Add lines and record quantities next."
      listPath="/physical-counts"
      listLabel="Physical Counts"
      size="lg"
      form
    >
      <PhysicalCountForm onSubmit={handleSubmit} isSubmitting={createCount.isPending} formError={formError} />
    </RecordPresenter>
  );
}
