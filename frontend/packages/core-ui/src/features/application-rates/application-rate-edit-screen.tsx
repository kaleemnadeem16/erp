import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useApplicationRatesShow, useApplicationRatesUpdate } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import {
  APPLICATION_RATE_FORM_FIELDS,
  ApplicationRateForm,
  applicationRatePayload,
  type ApplicationRateFormValues,
} from './application-rate-form';

export function ApplicationRateEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useApplicationRatesShow(id, { include: 'item,workUom,uom' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useApplicationRatesUpdate();
  const [error, setError] = useState<string | null>(null);

  const submit = (v: ApplicationRateFormValues, form: UseFormReturn<ApplicationRateFormValues>) => {
    setError(null);
    mutation.mutate(
      { applicationRate: id, data: applicationRatePayload(v) },
      { onError: (e) => setError(applyApiErrorsToForm(e, form.setError, APPLICATION_RATE_FORM_FIELDS)) }
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
        <Button variant="outline" onClick={() => navigate('/application-rates')}>
          Back to application rates
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Edit application rate"
        description={row.item ? `${row.item.code} — ${row.item.name}` : String(row.item_id)}
        breadcrumbs={[{ label: 'Application rates', to: '/application-rates' }, { label: `#${row.id}` }]}
      />
      <ApplicationRateForm
        mode="edit"
        defaultValues={{
          item_id: String(row.item_id),
          work_uom_id: String(row.work_uom_id),
          qty_per_work_unit: row.qty_per_work_unit,
          uom_id: String(row.uom_id),
          is_default: row.is_default,
          dimension_values: Object.fromEntries(
            row.dimension_values.map((value) => [String(value.rate_dimension_id), String(value.id)])
          ),
        }}
        onSubmit={submit}
        isSubmitting={mutation.isPending}
        formError={error}
      />
    </div>
  );
}
