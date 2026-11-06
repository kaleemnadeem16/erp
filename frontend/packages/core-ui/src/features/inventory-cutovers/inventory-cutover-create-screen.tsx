import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useInventoryCutoversStore, type InventoryCutoverMode, type StoreInventoryCutoverRequest } from '@erp/core-api';
import { RecordPresenter } from '../../components/page/record-presenter';
import type { Presentation } from '../../lib/presentation';

import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  InventoryCutoverForm,
  INVENTORY_CUTOVER_FORM_FIELDS,
  policyRowsToPolicy,
  type InventoryCutoverFormValues,
  type PolicyRow,
} from './inventory-cutover-form';

export function InventoryCutoverCreateScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const [formError, setFormError] = useState<string | null>(null);
  const createCutover = useInventoryCutoversStore();

  function handleSubmit(values: InventoryCutoverFormValues, policyRows: PolicyRow[], form: UseFormReturn<InventoryCutoverFormValues>) {
    setFormError(null);
    createCutover.mutate(
      {
        data: {
          mode: values.mode as InventoryCutoverMode,
          cutover_at: new Date(values.cutover_at).toISOString(),
          source_system: values.source_system,
          // The generated type is imprecise here (see inventory-cutover-form.tsx) —
          // this is really an object keyed by item code, not a string[].
          target_valuation_policy: policyRowsToPolicy(policyRows) as unknown as StoreInventoryCutoverRequest['target_valuation_policy'],
          control_quantity: values.control_quantity.trim(),
          control_value: values.control_value.trim(),
          base_control_value: values.base_control_value.trim(),
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate({ pathname: `/inventory-cutovers/${result.data.data.id}`, search });
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, INVENTORY_CUTOVER_FORM_FIELDS)),
      }
    );
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title="New inventory cutover"
      description="Define the source system, cutover timestamp and target valuation policy. Attach staged batches next."
      listPath="/inventory-cutovers"
      listLabel="Inventory Cutovers"
      size="lg"
      form
    >
      <InventoryCutoverForm onSubmit={handleSubmit} isSubmitting={createCutover.isPending} formError={formError} />
    </RecordPresenter>
  );
}
