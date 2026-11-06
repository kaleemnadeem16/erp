import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { UseFormReturn } from 'react-hook-form';
import { Trash2 } from 'lucide-react';
import { OverheadScopeType, useAssemblyGroupsDestroy, useAssemblyGroupsShow, useAssemblyGroupsUpdate } from '@erp/core-api';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Button } from '../../components/ui/button';
import { RecordPresenter } from '../../components/page/record-presenter';
import { RecordUnavailable } from '../../components/page/record-unavailable';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { OverheadPolicyPanel } from '../overhead-policies/overhead-policy-panel';
import {
  ASSEMBLY_GROUP_FORM_FIELDS,
  AssemblyGroupForm,
  assemblyGroupPayload,
  type AssemblyGroupFormValues,
} from './assembly-group-form';

const LIST = '/assembly-groups';

/** `/assembly-groups/:id/edit`: the group, its overhead policy and *Delete group* (refused while an assembly is still in it). */
export function AssemblyGroupEditScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const query = useAssemblyGroupsShow(id);
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useAssemblyGroupsUpdate();
  const destroy = useAssemblyGroupsDestroy();
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  const submit = (v: AssemblyGroupFormValues, form: UseFormReturn<AssemblyGroupFormValues>) => {
    setError(null);
    mutation.mutate(
      { assemblyGroup: id, data: assemblyGroupPayload(v) },
      {
        onSuccess: () => {
          void queryClient.invalidateQueries();
          back();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ASSEMBLY_GROUP_FORM_FIELDS)),
      }
    );
  };

  if (!row) {
    return (
      <RecordPresenter presentation={presentation} title="Assembly group" listPath={LIST} listLabel="Assembly groups" size="lg" form>
        <RecordUnavailable isLoading={query.isLoading} error={query.error} noun="assembly group" />
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
          Delete group
        </Button>
      }
      listPath={LIST}
      listLabel="Assembly groups"
      size="lg"
      form
    >
      <AssemblyGroupForm
        key={`${row.id}-${row.updated_at}`}
        mode="edit"
        defaultValues={{ code: row.code, name: row.name }}
        onSubmit={submit}
        onCancel={back}
        isSubmitting={mutation.isPending}
        formError={error}
      />
      <OverheadPolicyPanel scopeType={OverheadScopeType.ASSEMBLY_GROUP} scopeId={row.id} />

      <ConfirmActionDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={`Delete ${row.name}?`}
        description="The group is removed for good; no assembly is deleted by this. It can be deleted only while no assembly belongs to it, otherwise the system refuses and nothing changes."
        confirmLabel="Delete group"
        pendingLabel="Deleting…"
        failureMessage="The group could not be deleted."
        testId="delete-group-dialog"
        onConfirm={async () => {
          await destroy.mutateAsync({ assemblyGroup: id });
          await queryClient.invalidateQueries();
          back();
        }}
      />
    </RecordPresenter>
  );
}
