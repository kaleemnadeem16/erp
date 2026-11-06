import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { Trash2 } from 'lucide-react';
import { useApplicationRatesDestroy, useApplicationRatesShow, useApplicationRatesUpdate } from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { RecordPresenter } from '../../components/page/record-presenter';
import { RecordUnavailable } from '../../components/page/record-unavailable';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import {
  APPLICATION_RATE_FORM_FIELDS,
  ApplicationRateForm,
  applicationRatePayload,
  type ApplicationRateFormValues,
} from './application-rate-form';

const LIST = '/application-rates';

/** `/application-rates/:id/edit`: change the quantity, unit, default flag and tags; item and work unit are fixed. *Delete rate* removes it (and its tags) for good. */
export function ApplicationRateEditScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const query = useApplicationRatesShow(id, { include: 'item,workUom,uom' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useApplicationRatesUpdate();
  const destroy = useApplicationRatesDestroy();
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  const submit = (v: ApplicationRateFormValues, form: UseFormReturn<ApplicationRateFormValues>) => {
    setError(null);
    mutation.mutate(
      { applicationRate: id, data: applicationRatePayload(v) },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          back();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, APPLICATION_RATE_FORM_FIELDS)),
      }
    );
  };

  if (!row) {
    return (
      <RecordPresenter presentation={presentation} title="Application rate" listPath={LIST} listLabel="Application rates" size="lg" form>
        <RecordUnavailable isLoading={query.isLoading} error={query.error} noun="application rate" />
      </RecordPresenter>
    );
  }

  const itemName = row.item ? `${row.item.code} — ${row.item.name}` : String(row.item_id);

  return (
    <RecordPresenter
      presentation={presentation}
      title="Edit application rate"
      description={itemName}
      actions={
        <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmDelete(true)}>
          <Trash2 aria-hidden="true" className="mr-1.5 h-4 w-4" />
          Delete rate
        </Button>
      }
      listPath={LIST}
      listLabel="Application rates"
      size="lg"
      form
    >
      <ApplicationRateForm
        key={`${row.id}-${row.updated_at}`}
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
        onCancel={back}
        isSubmitting={mutation.isPending}
        formError={error}
      />

      <ConfirmActionDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this application rate?"
        description={
          <>
            The rate for {itemName} and its dimension tags are removed for good. A rate that an assembly component points to cannot be deleted: the system refuses and
            nothing changes. Resolution then no longer offers this rate, so another matching rate or the default for that item and work unit is used.
          </>
        }
        confirmLabel="Delete rate"
        pendingLabel="Deleting…"
        failureMessage="The rate could not be deleted."
        testId="delete-application-rate-dialog"
        onConfirm={async () => {
          await destroy.mutateAsync({ applicationRate: id });
          await queryClient.invalidateQueries();
          back();
        }}
      />
    </RecordPresenter>
  );
}
