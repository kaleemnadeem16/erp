import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { Trash2 } from 'lucide-react';
import {
  useRateDimensionsShow,
  useRateDimensionsUpdate,
  useRateDimensionsValuesIndex,
  useRateDimensionsValuesStore,
  useRateDimensionValuesDestroy,
} from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';
import {
  RATE_DIMENSION_FORM_FIELDS,
  RateDimensionForm,
  rateDimensionPayload,
  type RateDimensionFormValues,
} from './rate-dimension-form';

export function RateDimensionEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useRateDimensionsShow(id);
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useRateDimensionsUpdate();
  const [error, setError] = useState<string | null>(null);

  const submit = (v: RateDimensionFormValues, form: UseFormReturn<RateDimensionFormValues>) => {
    setError(null);
    mutation.mutate(
      { rateDimension: id, data: rateDimensionPayload(v) },
      { onError: (e) => setError(applyApiErrorsToForm(e, form.setError, RATE_DIMENSION_FORM_FIELDS)) }
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
        <Button variant="outline" onClick={() => navigate('/rate-dimensions')}>
          Back to rate dimensions
        </Button>
      </div>
    );

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Edit ${row.name}`}
        description={row.code}
        breadcrumbs={[{ label: 'Rate dimensions', to: '/rate-dimensions' }, { label: row.name }]}
      />
      <RateDimensionForm
        mode="edit"
        defaultValues={{
          code: row.code,
          name: row.name,
          sort_order: String(row.sort_order),
          is_required: row.is_required,
        }}
        onSubmit={submit}
        isSubmitting={mutation.isPending}
        formError={error}
      />
      <RateDimensionValues rateDimensionId={id} />
    </div>
  );
}

function RateDimensionValues({ rateDimensionId }: { rateDimensionId: number }) {
  const query = useRateDimensionsValuesIndex(rateDimensionId, { per_page: 100 });
  const values = query.data?.status === 200 ? query.data.data.data : [];
  const createMutation = useRateDimensionsValuesStore();
  const deleteMutation = useRateDimensionValuesDestroy();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [addError, setAddError] = useState<string | null>(null);

  const add = () => {
    setAddError(null);
    createMutation.mutate(
      { rateDimension: rateDimensionId, data: { code: code.trim(), name: name.trim() } },
      {
        onSuccess: () => {
          setCode('');
          setName('');
          void query.refetch();
        },
        onError: (e) => setAddError(getApiErrorMessage(e) ?? 'Could not add value.'),
      }
    );
  };

  return (
    <DetailSection title="Values" description="The values a rate can be tagged with on this axis.">
      <div className="space-y-2">
        {values.length === 0 && <p className="text-sm text-muted-foreground">No values yet.</p>}
        {values.map((value) => (
          <div key={value.id} className="flex items-center justify-between rounded-md border px-3 py-2">
            <div>
              <div className="text-sm font-medium">{value.name}</div>
              <div className="text-xs text-muted-foreground">{value.code}</div>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={() =>
                deleteMutation.mutate(
                  { rateDimensionValue: value.id },
                  { onSuccess: () => void query.refetch() }
                )
              }
              aria-label={`Delete ${value.name}`}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </div>
        ))}
      </div>
      <div className="flex flex-wrap items-end gap-2 pt-2">
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="new-value-code">
            Code
          </label>
          <Input id="new-value-code" value={code} onChange={(e) => setCode(e.target.value)} className="w-32" />
        </div>
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="new-value-name">
            Name
          </label>
          <Input id="new-value-name" value={name} onChange={(e) => setName(e.target.value)} className="w-48" />
        </div>
        <Button type="button" onClick={add} disabled={!code.trim() || !name.trim() || createMutation.isPending}>
          Add value
        </Button>
      </div>
      {addError && <p className="text-sm text-destructive">{addError}</p>}
    </DetailSection>
  );
}
