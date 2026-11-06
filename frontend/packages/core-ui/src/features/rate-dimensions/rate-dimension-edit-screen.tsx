import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import {
  useRateDimensionsDestroy,
  useRateDimensionsShow,
  useRateDimensionsUpdate,
  useRateDimensionsValuesIndex,
  useRateDimensionsValuesStore,
  useRateDimensionValuesDestroy,
  useRateDimensionValuesUpdate,
  type RateDimensionValueResource,
} from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { RecordPresenter } from '../../components/page/record-presenter';
import { RecordUnavailable } from '../../components/page/record-unavailable';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import {
  RATE_DIMENSION_FORM_FIELDS,
  RateDimensionForm,
  rateDimensionPayload,
  type RateDimensionFormValues,
} from './rate-dimension-form';

const LIST = '/rate-dimensions';

/** `/rate-dimensions/:id/edit`: the dimension, its values (add, rename, delete) and *Delete dimension*. */
export function RateDimensionEditScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const query = useRateDimensionsShow(id);
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useRateDimensionsUpdate();
  const destroy = useRateDimensionsDestroy();
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  const submit = (v: RateDimensionFormValues, form: UseFormReturn<RateDimensionFormValues>) => {
    setError(null);
    mutation.mutate(
      { rateDimension: id, data: rateDimensionPayload(v) },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          back();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, RATE_DIMENSION_FORM_FIELDS)),
      }
    );
  };

  if (!row) {
    return (
      <RecordPresenter presentation={presentation} title="Rate dimension" listPath={LIST} listLabel="Rate dimensions" size="lg" form>
        <RecordUnavailable isLoading={query.isLoading} error={query.error} noun="rate dimension" />
      </RecordPresenter>
    );
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title={`Edit ${row.name}`}
      description={row.code}
      actions={
        <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmDelete(true)}>
          <Trash2 aria-hidden="true" className="mr-1.5 h-4 w-4" />
          Delete dimension
        </Button>
      }
      listPath={LIST}
      listLabel="Rate dimensions"
      size="lg"
      form
    >
      <RateDimensionForm
        key={`${row.id}-${row.updated_at}`}
        mode="edit"
        defaultValues={{
          code: row.code,
          name: row.name,
          sort_order: String(row.sort_order),
          is_required: row.is_required,
        }}
        onSubmit={submit}
        onCancel={back}
        isSubmitting={mutation.isPending}
        formError={error}
      />
      <RateDimensionValues rateDimensionId={id} />

      <ConfirmActionDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={`Delete ${row.name}?`}
        description="The dimension is removed for good. It can be deleted only when it has no values left (delete them first) and no application rate is tagged with it; otherwise the system refuses and nothing changes."
        confirmLabel="Delete dimension"
        pendingLabel="Deleting…"
        failureMessage="The dimension could not be deleted."
        testId="delete-dimension-dialog"
        onConfirm={async () => {
          await destroy.mutateAsync({ rateDimension: id });
          await queryClient.invalidateQueries();
          back();
        }}
      />
    </RecordPresenter>
  );
}

function RateDimensionValues({ rateDimensionId }: { rateDimensionId: number }) {
  const query = useRateDimensionsValuesIndex(rateDimensionId, { per_page: 100 });
  const values = query.data?.status === 200 ? query.data.data.data : [];
  const createMutation = useRateDimensionsValuesStore();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [addError, setAddError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<RateDimensionValueResource | null>(null);
  const destroyValue = useRateDimensionValuesDestroy();

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
      <div className="space-y-2 sm:col-span-2">
        {query.isError && (
          <p role="alert" className="text-sm text-destructive">
            Couldn't load the values.{' '}
            <button type="button" className="underline" onClick={() => void query.refetch()}>
              Try again
            </button>
          </p>
        )}
        {!query.isError && values.length === 0 && <p className="text-sm text-muted-foreground">No values yet.</p>}
        {values.map((value) => (
          <ValueRow key={`${value.id}-${value.updated_at}`} value={value} onChanged={() => void query.refetch()} onDelete={() => setRemoving(value)} />
        ))}
      </div>
      <div className="flex flex-wrap items-end gap-2 pt-2 sm:col-span-2">
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="new-value-code">
            Code
          </label>
          <Input id="new-value-code" value={code} onChange={(e) => setCode(e.target.value)} className="h-11 w-32 md:h-10" />
        </div>
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="new-value-name">
            Name
          </label>
          <Input id="new-value-name" value={name} onChange={(e) => setName(e.target.value)} className="h-11 w-48 md:h-10" />
        </div>
        <Button type="button" className="h-11 md:h-10" onClick={add} disabled={!code.trim() || !name.trim() || createMutation.isPending}>
          Add value
        </Button>
      </div>
      {addError && (
        <p role="alert" className="text-sm text-destructive sm:col-span-2">
          {addError}
        </p>
      )}

      {removing && (
        <ConfirmActionDialog
          open
          onOpenChange={(next) => !next && setRemoving(null)}
          title={`Delete the value ${removing.name}?`}
          description="The value is removed from this axis. It cannot be deleted while any application rate is tagged with it; the system then refuses and nothing changes."
          confirmLabel="Delete value"
          pendingLabel="Deleting…"
          failureMessage="The value could not be deleted."
          testId="delete-value-dialog"
          onConfirm={async () => {
            await destroyValue.mutateAsync({ rateDimensionValue: removing.id });
            await query.refetch();
          }}
        />
      )}
    </DetailSection>
  );
}

/** One value: its name and code, with an inline rename (name and code) and delete. */
function ValueRow({ value, onChanged, onDelete }: { value: RateDimensionValueResource; onChanged: () => void; onDelete: () => void }) {
  const update = useRateDimensionValuesUpdate();
  const [editing, setEditing] = useState(false);
  const [code, setCode] = useState(value.code);
  const [name, setName] = useState(value.name);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setError(null);
    try {
      await update.mutateAsync({ rateDimensionValue: value.id, data: { code: code.trim(), name: name.trim() } });
      setEditing(false);
      onChanged();
    } catch (failure) {
      setError(getApiErrorMessage(failure) ?? 'Could not save the value.');
    }
  }

  if (editing) {
    return (
      <div className="space-y-2 rounded-md border px-3 py-2" data-testid={`dimension-value-${value.code}`}>
        <div className="flex flex-wrap items-end gap-2">
          <div className="space-y-1">
            <label className="text-xs font-medium" htmlFor={`value-code-${value.id}`}>
              Code
            </label>
            <Input id={`value-code-${value.id}`} value={code} onChange={(e) => setCode(e.target.value)} className="h-11 w-32 md:h-9" />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium" htmlFor={`value-name-${value.id}`}>
              Name
            </label>
            <Input id={`value-name-${value.id}`} value={name} onChange={(e) => setName(e.target.value)} className="h-11 w-48 md:h-9" />
          </div>
          <Button type="button" size="sm" className="h-11 md:h-9" disabled={!code.trim() || !name.trim() || update.isPending} onClick={() => void save()} aria-label={`Save ${value.name}`}>
            <Check aria-hidden="true" className="mr-1 h-4 w-4" />
            Save
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-11 md:h-9"
            disabled={update.isPending}
            onClick={() => {
              setEditing(false);
              setCode(value.code);
              setName(value.name);
              setError(null);
            }}
            aria-label={`Cancel renaming ${value.name}`}
          >
            <X aria-hidden="true" className="mr-1 h-4 w-4" />
            Cancel
          </Button>
        </div>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
      </div>
    );
  }

  return (
    <div className="flex items-center justify-between rounded-md border px-3 py-2" data-testid={`dimension-value-${value.code}`}>
      <div>
        <div className="text-sm font-medium">{value.name}</div>
        <div className="text-xs text-muted-foreground">{value.code}</div>
      </div>
      <div className="flex items-center">
        <Button type="button" variant="ghost" size="icon" className="h-11 w-11 md:h-9 md:w-9" onClick={() => setEditing(true)} aria-label={`Rename ${value.name}`}>
          <Pencil className="h-4 w-4" />
        </Button>
        <Button type="button" variant="ghost" size="icon" className="h-11 w-11 md:h-9 md:w-9" onClick={onDelete} aria-label={`Delete ${value.name}`}>
          <Trash2 className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
