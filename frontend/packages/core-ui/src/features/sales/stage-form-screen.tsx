import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useSalesStagesStore, useSalesStagesUpdate } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { SALES, useSalesStages } from './sales-common';
import { stageCodeFrom } from './stage-common';

/**
 * Add a stage, or rename one (`/sales-stages/:id/edit`). A stage's code is its stable identity — chosen once, from the name unless someone sets it — so an existing
 * stage can be renamed but not re-coded; its place in the order and its archive state change from the list or the stage's own panel.
 */
export function StageFormScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const editingId = Number(useParams<{ id: string }>().id) || undefined;
  const editing = editingId !== undefined;
  const mayManage = usePermission(SALES.stage);
  const { stages, query: stagesQuery } = useSalesStages();
  const stage = stages.find((candidate) => candidate.id === editingId);
  const store = useSalesStagesStore();
  const update = useSalesStagesUpdate();
  const backToList = () => navigate({ pathname: editing ? `/sales-stages/${editingId}` : '/sales-stages', search });

  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [codeEdited, setCodeEdited] = useState(false);
  const [order, setOrder] = useState('');
  const [more, setMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);

  const filled = useRef(false);
  useEffect(() => {
    if (!stage || filled.current) return;
    filled.current = true;
    setName(stage.name);
  }, [stage]);

  const effectiveCode = codeEdited ? code : stageCodeFrom(name);
  const codeTaken = !editing && stages.some((candidate) => candidate.code === effectiveCode);
  const nameMissing = name.trim() === '';
  const orderProblem = order.trim() !== '' && !/^\d+$/.test(order.trim()) ? 'Enter a whole number, or leave it empty to add the stage at the end.' : null;

  async function save() {
    setShowErrors(true);
    setError(null);
    if (nameMissing || (!editing && (effectiveCode === '' || codeTaken)) || orderProblem) {
      if (orderProblem || codeTaken) setMore(true);
      return;
    }
    try {
      if (editing) {
        const saved = await update.mutateAsync({ stage: editingId, data: { name: name.trim() } });
        if (saved.status === 200) backToList();
      } else {
        const created = await store.mutateAsync({ data: { code: effectiveCode, name: name.trim(), ...(order.trim() !== '' ? { sort_order: Number(order.trim()) } : {}) } });
        if (created.status === 201) navigate({ pathname: `/sales-stages/${created.data.data.id}`, search });
      }
      await stagesQuery.refetch();
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  function present(content: ReactNode) {
    const title = editing ? 'Rename stage' : 'New stage';
    const description = editing ? (stage?.code ?? undefined) : 'A step an open deal moves through.';
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={title} description={description} size="md" className="erp-record" dismissOnOutsideClick={false} onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="form" className="erp-screen pb-24">
        <div className="space-y-4">
          <PageHeader title={title} description={description} breadcrumbs={[{ label: 'Pipeline stages', to: `/sales-stages${search}` }, { label: editing ? 'Rename' : 'New' }]} />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (!mayManage) {
    return present(
      <p className="text-sm text-muted-foreground" data-testid="sales-denied">
        You don't have permission to change the pipeline stages.
      </p>,
    );
  }
  if (editing && stagesQuery.isLoading) return present(<Skeleton className="h-32 w-full" />);
  if (editing && !stage) return present(<p className="text-sm text-muted-foreground">Couldn't find this stage.</p>);

  return present(
    <div className="space-y-4" data-testid="stage-form">
      {error && (
        <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      <DetailSection title="The stage">
        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="stage-name">Name</Label>
          <Input id="stage-name" value={name} onChange={(event) => setName(event.target.value)} aria-invalid={showErrors && nameMissing} placeholder="For example: Site visit" />
          {showErrors && nameMissing && <p className="text-xs text-destructive">Give the stage a name.</p>}
        </div>
        {!editing && (
          <>
            <div className="sm:col-span-2">
              <MoreOptionsToggle open={more} onOpenChange={setMore} controls="stage-more" label="Code and position" />
            </div>
            {more && (
              <div id="stage-more" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="stage-code">Code</Label>
                  <Input
                    id="stage-code"
                    value={effectiveCode}
                    onChange={(event) => {
                      setCodeEdited(true);
                      setCode(stageCodeFrom(event.target.value));
                    }}
                    aria-invalid={showErrors && codeTaken}
                  />
                  <p className="text-xs text-muted-foreground">Its fixed identity, taken from the name. It cannot be changed later.</p>
                  {codeTaken && <p className="text-xs text-destructive">Another stage already has this code.</p>}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="stage-order">Position value</Label>
                  <Input
                    id="stage-order"
                    inputMode="numeric"
                    value={order}
                    onChange={(event) => setOrder(event.target.value)}
                    placeholder="Empty = at the end"
                    aria-invalid={showErrors && Boolean(orderProblem)}
                  />
                  <p className="text-xs text-muted-foreground">Lower values come earlier. You can also move it from the list.</p>
                  {orderProblem && <p className="text-xs text-destructive">{orderProblem}</p>}
                </div>
              </div>
            )}
          </>
        )}
      </DetailSection>
      <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
        <Button variant="outline" onClick={backToList}>
          Cancel
        </Button>
        <Button onClick={() => void save()} disabled={store.isPending || update.isPending} data-testid="stage-save">
          {store.isPending || update.isPending ? 'Saving…' : editing ? 'Save name' : 'Add stage'}
        </Button>
      </div>
    </div>,
  );
}
