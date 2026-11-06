import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useRecalculationRequestsStore, type RecalculationMovementKind } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import type { Presentation } from '../../lib/presentation';

import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  RecalculationRequestForm,
  RECALCULATION_REQUEST_FORM_FIELDS,
  type RecalculationRequestFormValues,
} from './recalculation-request-form';

export function RecalculationRequestCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const { search } = useLocation();
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
          qty: values.qty.trim(),
          unit_cost: isReceipt && values.unit_cost ? values.unit_cost.trim() : undefined,
          base_unit_cost: isReceipt && values.base_unit_cost ? values.base_unit_cost.trim() : undefined,
          currency_id: isReceipt && values.currency_id ? Number(values.currency_id) : undefined,
          fx_rate: isReceipt && values.fx_rate ? values.fx_rate.trim() : undefined,
          batch_no: values.batch_no?.trim() || undefined,
          serial_no: values.serial_no?.trim() || undefined,
          moved_at: new Date(values.moved_at).toISOString(),
          source_type: values.source_type,
          source_id: Number(values.source_id),
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate({ pathname: `/recalculation-requests/${result.data.data.id}`, search });
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, RECALCULATION_REQUEST_FORM_FIELDS)),
      }
    );
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title="New stock recalculation request"
      description="Request a correction for a backdated receipt, issue or transfer that conflicts with later valuation history."
      listPath="/recalculation-requests"
      listLabel="Stock Recalculation"
      size="lg"
      form
    >
      <RecalculationRequestForm onSubmit={handleSubmit} isSubmitting={createRequest.isPending} formError={formError} />
    </RecordPresenter>
  );
}
