import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useItemSuppliersShow, useItemSuppliersUpdate } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  ITEM_SUPPLIER_FORM_FIELDS,
  ItemSupplierForm,
  itemSupplierPayload,
  type ItemSupplierFormValues,
} from './item-supplier-form';
export function ItemSupplierEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useItemSuppliersShow(id, { include: 'item,supplierAccount,purchaseUom' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useItemSuppliersUpdate();
  const [error, setError] = useState<string | null>(null);
  const submit = (v: ItemSupplierFormValues, form: UseFormReturn<ItemSupplierFormValues>) => {
    setError(null);
    mutation.mutate(
      { itemSupplier: id, data: itemSupplierPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 200) navigate(`/item-suppliers/${r.data.data.id}`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ITEM_SUPPLIER_FORM_FIELDS)),
      }
    );
  };
  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  if (!row || query.isError)
    return (
      <div className="p-6">
        <Button variant="outline" onClick={() => navigate('/item-suppliers')}>
          Back to item suppliers
        </Button>
      </div>
    );
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Edit item supplier"
        description={`${row.item?.code ?? row.item_id} / ${row.supplier_account?.code ?? row.supplier_account_id}`}
        breadcrumbs={[
          { label: 'Item suppliers', to: '/item-suppliers' },
          { label: String(row.id), to: `/item-suppliers/${id}` },
          { label: 'Edit' },
        ]}
      />
      <ItemSupplierForm
        mode="edit"
        defaultValues={{
          item_id: String(row.item_id),
          supplier_account_id: String(row.supplier_account_id),
          supplier_item_code: row.supplier_item_code ?? '',
          priority: row.priority == null ? '' : String(row.priority),
          lead_time_days: row.lead_time_days == null ? '' : String(row.lead_time_days),
          minimum_order_qty: row.minimum_order_qty ?? '',
          purchase_uom_id: row.purchase_uom_id == null ? 'NONE' : String(row.purchase_uom_id),
          active: row.active,
        }}
        onSubmit={submit}
        isSubmitting={mutation.isPending}
        formError={error}
      />
    </div>
  );
}
