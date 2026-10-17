import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useWarehousesStore } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { WarehouseForm, applyWarehouseFormErrors, type WarehouseFormValues } from './warehouse-form';
import { usePermission } from '../../hooks/use-permission';
import type { Presentation } from '../../lib/presentation';

export interface WarehouseCreateScreenProps {
  presentation?: Presentation;
}

export function WarehouseCreateScreen({ presentation = 'page' }: WarehouseCreateScreenProps) {
  const mayManage = usePermission('inventory.warehouse.manage');
  const navigate = useNavigate();
  const { search } = useLocation();
  const [formError, setFormError] = useState<string | null>(null);
  const createWarehouse = useWarehousesStore();

  const handleClose = () => {
    navigate({ pathname: '/warehouses', search });
  };

  if (!mayManage) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Access denied" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">You don't have permission to create warehouses.</p>
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
          <PageHeader
            title="Access denied"
            breadcrumbs={[{ label: 'Warehouses', to: `/warehouses${search}` }]}
            backTo={`/warehouses${search}`}
          />
          <p className="text-sm text-destructive">You don't have permission to create warehouses.</p>
          <Button variant="outline" onClick={handleClose}>
            Back to warehouses
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

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
          if (result.status === 201) {
            navigate({ pathname: `/warehouses/${result.data.data.id}`, search });
          }
        },
        onError: (error) => setFormError(applyWarehouseFormErrors(error, form)),
      }
    );
  }

  const formElement = (
    <WarehouseForm
      mode="create"
      onSubmit={handleSubmit}
      isSubmitting={createWarehouse.isPending}
      formError={formError}
    />
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title="New warehouse"
        description="Create a warehouse and its initial stock location."
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
          title="New warehouse"
          description="Create a warehouse and its initial stock location."
          breadcrumbs={[{ label: 'Warehouses', to: `/warehouses${search}` }, { label: 'New' }]}
          backTo={`/warehouses${search}`}
        />
        {formElement}
      </div>
    </ScreenTemplate>
  );
}
