import { useState } from 'react';
import { useSalesStagesUpdate, type SalesStageResource } from '@erp/core-api';

import { getApiErrorMessage, getApiReasonCode } from '../../lib/api-errors';

export type StageRow = SalesStageResource;

/** The code a stage gets from its name until someone chooses one: upper case, words joined by underscores. */
export const stageCodeFrom = (name: string) =>
  name
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
    .slice(0, 50);

export const STAGE_STATUS_LABEL: Record<string, string> = { ACTIVE: 'Active', ARCHIVED: 'Archived' };

/**
 * Move, archive and restore a stage — one place for the list rows, the detail panel and the bulk bar's single-stage cousins. `stages` is the whole ordered list (archived
 * included), because a stage's place is relative to its neighbours. Refusals come back as a message: the server keeps at least one stage active.
 */
export function useStageActions(stages: StageRow[], refetch: () => Promise<unknown>) {
  const update = useSalesStagesUpdate();
  const [error, setError] = useState<string | null>(null);

  async function run(change: () => Promise<unknown>) {
    setError(null);
    try {
      await change();
      await refetch();
    } catch (failure) {
      setError(getApiReasonCode(failure) === 'LAST_ACTIVE_STAGE' ? 'Keep at least one active stage.' : (getApiErrorMessage(failure) ?? 'The change was refused.'));
    }
  }

  const indexOf = (stage: StageRow) => stages.findIndex((candidate) => candidate.id === stage.id);
  const moveUp = (stage: StageRow) => {
    const index = indexOf(stage);
    const previous = stages[index - 1];
    if (!previous) return Promise.resolve();

    return run(() => update.mutateAsync({ stage: stage.id, data: { sort_order: Math.max(0, previous.sort_order - 5) } }));
  };
  // Moving a stage down is moving the one below it up.
  const moveDown = (stage: StageRow) => {
    const next = stages[indexOf(stage) + 1];

    return next ? moveUp(next) : Promise.resolve();
  };
  const setStatus = (stage: StageRow, status: 'ACTIVE' | 'ARCHIVED') => run(() => update.mutateAsync({ stage: stage.id, data: { status } }));

  return {
    error,
    clearError: () => setError(null),
    indexOf,
    moveUp,
    moveDown,
    setStatus,
    canMoveUp: (stage: StageRow) => indexOf(stage) > 0,
    canMoveDown: (stage: StageRow) => indexOf(stage) < stages.length - 1,
  };
}
