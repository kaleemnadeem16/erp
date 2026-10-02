import { useState } from 'react';
import { useNumberingConstantsStore } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useNumberingConstants } from './numbering-api';

/** Company-defined constants usable as {NAME} in any format: {REGION} = NORTH. A change is a new record and affects only later numbers. */
export function NumberingConstantsPanel() {
  const { constants } = useNumberingConstants();
  const mayManage = usePermission('numbering.constant.manage');
  const store = useNumberingConstantsStore();
  const [draft, setDraft] = useState({ code: '', value: '', reason: '' });
  const [error, setError] = useState<string | null>(null);
  const active = constants.filter((row) => row.status === 'ACTIVE' && row.value !== null);

  function record(code: string, value: string | null, reason: string, done?: () => void) {
    setError(null);
    store.mutate({ data: { code: code.trim().toUpperCase(), value, reason: reason.trim() } }, { onSuccess: done, onError: (e) => setError(getApiErrorMessage(e)) });
  }

  return (
    <DetailSection title="Company constants" description="Your own words for numbers: define REGION = NORTH once and use {REGION} in any format. Changing one affects only numbers issued afterwards." fullWidth>
      <div className="space-y-3 sm:col-span-2">
        {active.length === 0 ? <p className="text-sm text-muted-foreground">No constants yet.</p> : (
          <ul className="divide-y rounded-md border" aria-label="Constants in force">
            {active.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                <span><span className="font-mono">{`{${row.code}}`}</span> = <span className="font-mono font-semibold">{row.value}</span> <span className="text-xs text-muted-foreground">since {formatDateTime(row.effective_from)} · {row.reason}</span></span>
                {mayManage && <Button type="button" size="sm" variant="ghost" disabled={store.isPending} onClick={() => record(row.code, null, 'Retired from the screen')}>Retire</Button>}
              </li>
            ))}
          </ul>
        )}
        {mayManage && (
          <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-3">
            <div><Label htmlFor="constant-code">Name</Label><Input id="constant-code" className="font-mono" placeholder="REGION" value={draft.code} onChange={(event) => setDraft({ ...draft, code: event.target.value })} /></div>
            <div><Label htmlFor="constant-value">Value</Label><Input id="constant-value" className="font-mono" placeholder="NORTH" value={draft.value} onChange={(event) => setDraft({ ...draft, value: event.target.value })} /></div>
            <div><Label htmlFor="constant-reason">Why</Label><Input id="constant-reason" value={draft.reason} onChange={(event) => setDraft({ ...draft, reason: event.target.value })} /></div>
            <div className="sm:col-span-3">
              <Button type="button" size="sm" disabled={store.isPending || !draft.code.trim() || !draft.value.trim() || !draft.reason.trim()} onClick={() => record(draft.code, draft.value.trim(), draft.reason, () => setDraft({ code: '', value: '', reason: '' }))}>
                Save constant
              </Button>
              {active.some((row) => row.code === draft.code.trim().toUpperCase()) && <Badge variant="outline" className="ml-2">replaces the current value</Badge>}
            </div>
          </div>
        )}
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      </div>
    </DetailSection>
  );
}
