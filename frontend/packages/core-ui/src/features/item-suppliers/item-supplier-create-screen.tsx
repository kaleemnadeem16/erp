import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useItemSuppliersStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  ITEM_SUPPLIER_FORM_FIELDS,
  ItemSupplierForm,
  itemSupplierPayload,
  type ItemSupplierFormValues,
} from './item-supplier-form';
export function ItemSupplierCreateScreen() {
  const navigate = useNavigate();
  const mutation = useItemSuppliersStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: ItemSupplierFormValues, form: UseFormReturn<ItemSupplierFormValues>) => {
    setError(null);
    mutation.mutate(
      {
        data: {
          item_id: Number(v.item_id),
          supplier_account_id: Number(v.supplier_account_id),
          ...itemSupplierPayload(v),
        },
      },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/item-suppliers/${r.data.data.id}`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ITEM_SUPPLIER_FORM_FIELDS)),
      }
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New item supplier"
        description="Approve a supplier for an item and record its sourcing terms."
        breadcrumbs={[{ label: 'Item suppliers', to: '/item-suppliers' }, { label: 'New' }]}
      />
      <ItemSupplierForm mode="create" onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
