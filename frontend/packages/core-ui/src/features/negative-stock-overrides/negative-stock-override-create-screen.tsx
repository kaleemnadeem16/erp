import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useNegativeStockOverrideRequestsStore, type NegativeStockMovementClass, type NegativeStockReasonCode } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  NegativeStockOverrideForm,
  NEGATIVE_STOCK_OVERRIDE_FORM_FIELDS,
  type NegativeStockOverrideFormValues,
} from './negative-stock-override-form';

export function NegativeStockOverrideCreateScreen() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const createOverride = useNegativeStockOverrideRequestsStore();

  function handleSubmit(values: NegativeStockOverrideFormValues, form: UseFormReturn<NegativeStockOverrideFormValues>) {
    setFormError(null);
    createOverride.mutate(
      {
        data: {
          item_id: Number(values.item_id),
          location_id: Number(values.location_id),
          movement_class: values.movement_class as NegativeStockMovementClass,
          requested_qty: values.requested_qty.trim(),
          source_type: values.source_type,
          source_id: Number(values.source_id),
          reason_code: values.reason_code as NegativeStockReasonCode,
          explanation: values.explanation,
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/negative-stock-overrides/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, NEGATIVE_STOCK_OVERRIDE_FORM_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New negative-stock override request"
        description="Request authorisation for a genuine anticipated shortage before it's posted."
        breadcrumbs={[{ label: 'Negative-Stock Overrides', to: '/negative-stock-overrides' }, { label: 'New' }]}
      />
      <NegativeStockOverrideForm onSubmit={handleSubmit} isSubmitting={createOverride.isPending} formError={formError} />
    </div>
  );
}
