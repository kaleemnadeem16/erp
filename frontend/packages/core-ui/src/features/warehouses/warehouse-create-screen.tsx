import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useWarehousesStore } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { WarehouseForm, WAREHOUSE_FORM_FIELDS, type WarehouseFormValues } from './warehouse-form';

export function WarehouseCreateScreen() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const createWarehouse = useWarehousesStore();

  function handleSubmit(values: WarehouseFormValues, form: UseFormReturn<WarehouseFormValues>) {
    setFormError(null);
    createWarehouse.mutate(
      {
        data: {
          code: values.code.trim(),
          name: values.name.trim(),
          is_default: values.is_default,
          active: values.active,
          location_code: values.location_code.trim(),
          location_name: values.location_name.trim(),
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/warehouses/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, WAREHOUSE_FORM_FIELDS)),
      }
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New warehouse"
        description="Create a warehouse and its initial stock location."
        breadcrumbs={[{ label: 'Warehouses', to: '/warehouses' }, { label: 'New' }]}
      />
      <WarehouseForm
        mode="create"
        onSubmit={handleSubmit}
        isSubmitting={createWarehouse.isPending}
        formError={formError}
      />
    </div>
  );
}
