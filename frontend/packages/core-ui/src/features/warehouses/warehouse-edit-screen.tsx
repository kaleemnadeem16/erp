import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useWarehousesShow, useWarehousesUpdate } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { WarehouseForm, applyWarehouseFormErrors, type WarehouseFormValues } from './warehouse-form';
import { usePermission } from '../../hooks/use-permission';
import type { Presentation } from '../../lib/presentation';

export interface WarehouseEditScreenProps {
  presentation?: Presentation;
}

export function WarehouseEditScreen({ presentation = 'page' }: WarehouseEditScreenProps) {
  const warehouseId = Number(useParams<{ id: string }>().id);
  const mayManage = usePermission('inventory.warehouse.manage');
  const navigate = useNavigate();
  const { search } = useLocation();
  const [formError, setFormError] = useState<string | null>(null);

  const warehouseQuery = useWarehousesShow(warehouseId);
  const warehouse = warehouseQuery.data?.status === 200 ? warehouseQuery.data.data.data : undefined;
  const updateWarehouse = useWarehousesUpdate();

  const handleClose = () => {
    navigate({ pathname: `/warehouses/${warehouseId}`, search });
  };

  if (!mayManage) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Access denied" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">You don't have permission to edit warehouses.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to warehouse
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4 p-6">
          <PageHeader
            title="Access denied"
            breadcrumbs={[{ label: 'Warehouses', to: `/warehouses${search}` }]}
            backTo={`/warehouses/${warehouseId}${search}`}
          />
          <p className="text-sm text-destructive">You don't have permission to edit warehouses.</p>
          <Button variant="outline" onClick={handleClose}>
            Back to warehouse
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

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
          if (result.status === 200) {
            navigate({ pathname: `/warehouses/${result.data.data.id}`, search });
          }
        },
        onError: (error) => setFormError(applyWarehouseFormErrors(error, form)),
      }
    );
  }

  if (warehouseQuery.isLoading) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Edit warehouse" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 p-4">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-64 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4 p-6">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-64 w-full" />
        </div>
      </ScreenTemplate>
    );
  }

  if (warehouseQuery.isError || !warehouse) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Warehouse not found" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 p-4">
            <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to warehouses
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4 p-6">
          <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
          <Button variant="outline" onClick={handleClose}>
            Back to warehouses
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const formElement = (
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
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={`Edit ${warehouse.name}`}
        description={warehouse.code}
        size="lg"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{formElement}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4 p-6">
        <PageHeader
          title={`Edit ${warehouse.name}`}
          description={warehouse.code}
          breadcrumbs={[
            { label: 'Warehouses', to: `/warehouses${search}` },
            { label: warehouse.code, to: `/warehouses/${warehouse.id}${search}` },
            { label: 'Edit' },
          ]}
          backTo={`/warehouses/${warehouse.id}${search}`}
        />
        {formElement}
      </div>
    </ScreenTemplate>
  );
}
