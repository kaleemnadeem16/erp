import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useInventoryCutoversStore, type InventoryCutoverMode, type StoreInventoryCutoverRequest } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  InventoryCutoverForm,
  INVENTORY_CUTOVER_FORM_FIELDS,
  policyRowsToPolicy,
  type InventoryCutoverFormValues,
  type PolicyRow,
} from './inventory-cutover-form';

export function InventoryCutoverCreateScreen() {
  const navigate = useNavigate();
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
          control_quantity: Number(values.control_quantity),
          control_value: Number(values.control_value),
          base_control_value: Number(values.base_control_value),
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/inventory-cutovers/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, INVENTORY_CUTOVER_FORM_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New inventory cutover"
        description="Define the source system, cutover timestamp and target valuation policy. Attach staged batches next."
        breadcrumbs={[{ label: 'Inventory Cutovers', to: '/inventory-cutovers' }, { label: 'New' }]}
      />
      <InventoryCutoverForm onSubmit={handleSubmit} isSubmitting={createCutover.isPending} formError={formError} />
    </div>
  );
}
