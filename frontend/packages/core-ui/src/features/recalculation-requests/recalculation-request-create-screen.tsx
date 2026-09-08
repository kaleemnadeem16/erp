import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useRecalculationRequestsStore, type RecalculationMovementKind } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  RecalculationRequestForm,
  RECALCULATION_REQUEST_FORM_FIELDS,
  type RecalculationRequestFormValues,
} from './recalculation-request-form';

export function RecalculationRequestCreateScreen() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const createRequest = useRecalculationRequestsStore();

  function handleSubmit(values: RecalculationRequestFormValues, form: UseFormReturn<RecalculationRequestFormValues>) {
    setFormError(null);
    const isReceipt = values.movement_kind === 'RECEIPT';
    createRequest.mutate(
      {
        data: {
          item_id: Number(values.item_id),
          location_id: Number(values.location_id),
          movement_kind: values.movement_kind as RecalculationMovementKind,
          destination_location_id: values.destination_location_id ? Number(values.destination_location_id) : undefined,
          qty: Number(values.qty),
          unit_cost: isReceipt && values.unit_cost ? Number(values.unit_cost) : undefined,
          base_unit_cost: isReceipt && values.base_unit_cost ? Number(values.base_unit_cost) : undefined,
          currency_id: isReceipt && values.currency_id ? Number(values.currency_id) : undefined,
          fx_rate: isReceipt && values.fx_rate ? Number(values.fx_rate) : undefined,
          batch_no: values.batch_no?.trim() || undefined,
          serial_no: values.serial_no?.trim() || undefined,
          moved_at: new Date(values.moved_at).toISOString(),
          source_type: values.source_type,
          source_id: Number(values.source_id),
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/recalculation-requests/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, RECALCULATION_REQUEST_FORM_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New stock recalculation request"
        description="Request a correction for a backdated receipt, issue or transfer that conflicts with later valuation history."
        breadcrumbs={[{ label: 'Stock Recalculation', to: '/recalculation-requests' }, { label: 'New' }]}
      />
      <RecalculationRequestForm onSubmit={handleSubmit} isSubmitting={createRequest.isPending} formError={formError} />
    </div>
  );
}
