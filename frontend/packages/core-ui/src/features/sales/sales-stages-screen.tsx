import { useState } from 'react';
import { useSalesStagesSeedSuggested, useSalesStagesStore, useSalesStagesUpdate } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { StatusBadge } from '../../components/ui/status-badge';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, getApiReasonCode } from '../../lib/api-errors';
import { SALES, useSalesStages } from './sales-common';

/** The OPEN stages of the pipeline. A stage is archived, never deleted: what was recorded about it keeps the name it had. */
export function SalesStagesScreen() {
  const mayManage = usePermission(SALES.stage);
  const { stages, query } = useSalesStages();
  const store = useSalesStagesStore();
  const update = useSalesStagesUpdate();
  const seed = useSalesStagesSeedSuggested();
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [renames, setRenames] = useState<Record<number, string>>({});

  async function run(action: () => Promise<unknown>, done?: () => void) {
    setError(null);
    try {
      await action();
      await query.refetch();
      done?.();
    } catch (failure) {
      setError(getApiReasonCode(failure) === 'LAST_ACTIVE_STAGE' ? 'Keep at least one active stage.' : getApiErrorMessage(failure));
    }
  }

  const code = name.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50);

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="sales-stages">
        <PageHeader title="Pipeline stages" description="The steps an open deal moves through. Archiving a stage keeps what was recorded about it." />
        {error && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}
        {stages.length === 0 && !query.isLoading && (
          <div className="space-y-2 rounded-md border border-dashed p-3 text-sm" data-testid="stages-empty">
            <p>No stages yet.</p>
            {mayManage && (
              <Button size="sm" variant="outline" data-testid="stages-seed" onClick={() => void run(() => seed.mutateAsync())}>
                Start with Qualification, Proposal and Negotiation
              </Button>
            )}
          </div>
        )}
        <ul className="space-y-2">
          {stages.map((stage, index) => (
            <li key={stage.id} className="flex flex-wrap items-center gap-2 rounded-md border p-3" data-testid="stage-row">
              <span className="w-6 text-sm text-muted-foreground tabular-nums">{index + 1}</span>
              {mayManage ? (
                <Input aria-label={`Name of ${stage.name}`} className="h-9 min-w-0 flex-1 md:w-64 md:flex-none" value={renames[stage.id] ?? stage.name} onChange={(event) => setRenames({ ...renames, [stage.id]: event.target.value })} />
              ) : (
                <span className="min-w-0 flex-1">{stage.name}</span>
              )}
              <StatusBadge tone={stage.status === 'ACTIVE' ? 'success' : 'draft'}>{stage.status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>
              {mayManage && (
                <>
                  <Button size="sm" variant="outline" disabled={(renames[stage.id] ?? stage.name).trim() === stage.name || (renames[stage.id] ?? stage.name).trim() === ''} onClick={() => void run(() => update.mutateAsync({ stage: stage.id, data: { name: (renames[stage.id] ?? stage.name).trim() } }), () => setRenames((current) => (current[stage.id]?.trim() === (renames[stage.id] ?? stage.name).trim() ? Object.fromEntries(Object.entries(current).filter(([id]) => Number(id) !== stage.id)) : current)))}>
                    Rename
                  </Button>
                  <Button size="sm" variant="outline" disabled={index === 0} aria-label={`Move ${stage.name} up`} onClick={() => void run(() => update.mutateAsync({ stage: stage.id, data: { sort_order: Math.max(0, (stages[index - 1]?.sort_order ?? 10) - 5) } }))}>
                    Up
                  </Button>
                  <Button size="sm" variant="outline" data-testid={`stage-toggle-${stage.code}`} onClick={() => void run(() => update.mutateAsync({ stage: stage.id, data: { status: stage.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' } }))}>
                    {stage.status === 'ACTIVE' ? 'Archive' : 'Restore'}
                  </Button>
                </>
              )}
            </li>
          ))}
        </ul>
        {mayManage && (
          <div className="flex flex-wrap items-end gap-2">
            <div className="space-y-1">
              <Label htmlFor="stage-new">New stage</Label>
              <Input id="stage-new" className="h-9 w-64" value={name} onChange={(event) => setName(event.target.value)} />
            </div>
            <Button disabled={code === '' || store.isPending} data-testid="stage-add" onClick={() => void run(() => store.mutateAsync({ data: { code, name: name.trim() } }), () => setName(''))}>
              Add stage
            </Button>
          </div>
        )}
      </div>
    </ScreenTemplate>
  );
}
