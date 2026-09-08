import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { usePhysicalCountsStore } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { PhysicalCountForm, PHYSICAL_COUNT_FORM_FIELDS, type PhysicalCountFormValues } from './physical-count-form';

export function PhysicalCountCreateScreen() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const createCount = usePhysicalCountsStore();

  function handleSubmit(values: PhysicalCountFormValues, form: UseFormReturn<PhysicalCountFormValues>) {
    setFormError(null);
    createCount.mutate(
      { data: { warehouse_id: Number(values.warehouse_id), reference: values.reference?.trim() || undefined } },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/physical-counts/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, PHYSICAL_COUNT_FORM_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New physical count"
        description="Start a count against one warehouse. Add lines and record quantities next."
        breadcrumbs={[{ label: 'Physical Counts', to: '/physical-counts' }, { label: 'New' }]}
      />
      <PhysicalCountForm onSubmit={handleSubmit} isSubmitting={createCount.isPending} formError={formError} />
    </div>
  );
}
