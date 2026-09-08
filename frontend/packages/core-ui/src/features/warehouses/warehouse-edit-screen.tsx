import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useWarehousesShow, useWarehousesUpdate } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { WarehouseForm, WAREHOUSE_FORM_FIELDS, type WarehouseFormValues } from './warehouse-form';

export function WarehouseEditScreen() {
  const warehouseId = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const warehouseQuery = useWarehousesShow(warehouseId);
  const warehouse = warehouseQuery.data?.status === 200 ? warehouseQuery.data.data.data : undefined;
  const updateWarehouse = useWarehousesUpdate();

  function handleSubmit(values: WarehouseFormValues, form: UseFormReturn<WarehouseFormValues>) {
    setFormError(null);
    updateWarehouse.mutate(
      {
        warehouse: warehouseId,
        data: {
          code: values.code.trim(),
          name: values.name.trim(),
          is_default: values.is_default,
          active: values.active,
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 200) navigate(`/warehouses/${result.data.data.id}`);
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, WAREHOUSE_FORM_FIELDS)),
      }
    );
  }

  if (warehouseQuery.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  if (warehouseQuery.isError || !warehouse)
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
        <Button variant="outline" onClick={() => navigate('/warehouses')}>
          Back to warehouses
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Edit ${warehouse.name}`}
        description={warehouse.code}
        breadcrumbs={[
          { label: 'Warehouses', to: '/warehouses' },
          { label: warehouse.code, to: `/warehouses/${warehouse.id}` },
          { label: 'Edit' },
        ]}
      />
      <WarehouseForm
        mode="edit"
        defaultValues={{
          code: warehouse.code,
          name: warehouse.name,
          is_default: warehouse.is_default,
          active: warehouse.active,
        }}
        onSubmit={handleSubmit}
        isSubmitting={updateWarehouse.isPending}
        formError={formError}
      />
    </div>
  );
}
