import { useState } from 'react';
import { useNumberingCountersRaise, useNumberingSeriesRulesStore, useNumberingSeriesStore } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useSeriesCounters, useSeriesHistory, useSeriesRules } from './numbering-api';
import { CONTINUITY_LABELS, type NumberingDocumentType, type NumberingSeries } from './numbering-types';

function ErrorLine({ message }: { message: string | null }) {
  return message ? <p role="alert" className="text-sm text-destructive">{message}</p> : null;
}

/** Series of one document type, adding another, and the routing rules that send documents to one. */
export function SeriesSection({ type, selectedId, onSelect, canManage }: { type: NumberingDocumentType; selectedId: number; onSelect: (id: number) => void; canManage: boolean }) {
  const create = useNumberingSeriesStore();
  const addRule = useNumberingSeriesRulesStore();
  const selected = type.series.find((series) => series.id === selectedId) ?? type.series[0];
  const { rules } = useSeriesRules(selected?.id, true);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState({ code: '', name: '', template: '', selectable: false, reason: '' });
  const [rule, setRule] = useState({ attribute: type.routing_attributes[0] ?? '', operator: 'EQ' as 'EQ' | 'IN', values: '', priority: '10' });

  function createSeries() {
    setError(null);
    create.mutate(
      { data: { document_type: type.key, code: draft.code.trim().toUpperCase(), name: draft.name.trim(), template: draft.template.trim(), scope_period: 'NONE', scope_dimensions: [], continuity: type.default_continuity, is_selectable: draft.selectable, reason: draft.reason.trim() } },
      {
        onSuccess: (result) => { if (result.status === 201) { setDraft({ code: '', name: '', template: '', selectable: false, reason: '' }); onSelect((result.data.data as unknown as NumberingSeries).id); } },
        onError: (e) => setError(getApiErrorMessage(e)),
      },
    );
  }

  function createRule() {
    if (!selected) return;
    setError(null);
    addRule.mutate(
      { series: selected.id, data: { priority: Number(rule.priority), conditions: [{ attribute: rule.attribute, operator: rule.operator, values: rule.values.split(',').map((value) => value.trim()).filter(Boolean) }] } },
      { onSuccess: () => setRule({ ...rule, values: '' }), onError: (e) => setError(getApiErrorMessage(e)) },
    );
  }

  return (
    <DetailSection title="Series and routing" description="A document type can number from several lines — for example domestic and import. Each has its own format and counters." fullWidth>
      <div className="space-y-4 sm:col-span-2">
        <ul className="divide-y rounded-md border" aria-label="Series">
          {type.series.map((series) => (
            <li key={series.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
              <div>
                <span className="font-medium">{series.name}</span> <span className="font-mono text-xs text-muted-foreground">{series.code}</span>{' '}
                {series.is_default && <Badge variant="secondary">Default</Badge>} {series.is_selectable && <Badge variant="outline">Can be chosen by hand</Badge>}
                <div className="font-mono text-xs text-muted-foreground">{series.version?.template} → {series.example ?? '—'}</div>
              </div>
              <Button type="button" size="sm" variant={series.id === selected?.id ? 'secondary' : 'ghost'} onClick={() => onSelect(series.id)} aria-pressed={series.id === selected?.id}>
                {series.id === selected?.id ? 'Editing' : 'Edit this series'}
              </Button>
            </li>
          ))}
        </ul>

        {selected && (
          <div className="space-y-2">
            <h3 className="text-sm font-semibold">Routing rules for {selected.name}</h3>
            <p className="text-xs text-muted-foreground">A document is numbered from the first matching rule (lowest priority number first); with none, from the default series.</p>
            {rules.length === 0 ? <p className="text-sm text-muted-foreground">No rules.</p> : (
              <ul className="space-y-1 text-sm">
                {rules.map((row) => (
                  <li key={row.id} className="rounded-md border px-2 py-1">
                    <span className="text-xs text-muted-foreground">Priority {row.priority}:</span>{' '}
                    {row.conditions.map((condition) => `${condition.attribute} ${condition.operator === 'EQ' ? '=' : 'in'} ${condition.values.join(', ')}`).join(' and ')}
                  </li>
                ))}
              </ul>
            )}
            {canManage && type.routing_attributes.length > 0 && (
              <div className="grid gap-2 sm:grid-cols-5">
                <div><Label htmlFor="rule-attribute">When</Label>
                  <select id="rule-attribute" className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm" value={rule.attribute} onChange={(event) => setRule({ ...rule, attribute: event.target.value })}>
                    {type.routing_attributes.map((attribute) => <option key={attribute} value={attribute}>{attribute}</option>)}
                  </select></div>
                <div><Label htmlFor="rule-operator">Is</Label>
                  <select id="rule-operator" className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm" value={rule.operator} onChange={(event) => setRule({ ...rule, operator: event.target.value as 'EQ' | 'IN' })}>
                    <option value="EQ">equal to</option><option value="IN">one of</option>
                  </select></div>
                <div className="sm:col-span-2"><Label htmlFor="rule-values">Value (comma-separated for “one of”)</Label><Input id="rule-values" value={rule.values} onChange={(event) => setRule({ ...rule, values: event.target.value })} /></div>
                <div><Label htmlFor="rule-priority">Priority</Label><Input id="rule-priority" inputMode="numeric" value={rule.priority} onChange={(event) => setRule({ ...rule, priority: event.target.value })} /></div>
                <div className="sm:col-span-5"><Button type="button" size="sm" variant="outline" onClick={createRule} disabled={addRule.isPending || rule.values.trim() === ''}>Add rule to {selected.name}</Button></div>
              </div>
            )}
          </div>
        )}

        {canManage && (
          <div className="space-y-2 rounded-md border p-3">
            <h3 className="text-sm font-semibold">Add a series</h3>
            <div className="grid gap-2 sm:grid-cols-2">
              <div><Label htmlFor="series-code">Code</Label><Input id="series-code" placeholder="IMPORT" value={draft.code} onChange={(event) => setDraft({ ...draft, code: event.target.value })} /></div>
              <div><Label htmlFor="series-name">Name</Label><Input id="series-name" placeholder="Import orders" value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} /></div>
              <div className="sm:col-span-2"><Label htmlFor="series-template">Format</Label><Input id="series-template" className="font-mono" placeholder="IMP-{SEQ:5}" value={draft.template} onChange={(event) => setDraft({ ...draft, template: event.target.value })} /></div>
              <div className="sm:col-span-2"><Label htmlFor="series-reason">Why</Label><Input id="series-reason" value={draft.reason} onChange={(event) => setDraft({ ...draft, reason: event.target.value })} /></div>
              <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={draft.selectable} onChange={(event) => setDraft({ ...draft, selectable: event.target.checked })} /> A person may choose this series by hand</label>
            </div>
            <Button type="button" size="sm" onClick={createSeries} disabled={create.isPending || !draft.code.trim() || !draft.name.trim() || !draft.template.trim() || !draft.reason.trim()}>Add series</Button>
          </div>
        )}
        <ErrorLine message={error} />
      </div>
    </DetailSection>
  );
}

/** The running numbers of one series, with the one change allowed: raising a counter. */
export function CountersSection({ series, canRaise }: { series: NumberingSeries; canRaise: boolean }) {
  const { counters } = useSeriesCounters(series.id, true);
  const raise = useNumberingCountersRaise();
  const [drafts, setDrafts] = useState<Record<number, { next: string; reason: string }>>({});
  const [error, setError] = useState<string | null>(null);

  return (
    <DetailSection title="Counters" description={`The running count for each ${series.name} counter. A counter can be raised, never lowered, so no number is ever repeated.`} fullWidth>
      <div className="space-y-2 sm:col-span-2">
        {counters.length === 0 ? <p className="text-sm text-muted-foreground">No number has been issued from this series yet.</p> : (
          <ul className="divide-y rounded-md border" aria-label="Counters">
            {counters.map((counter) => {
              const draft = drafts[counter.id] ?? { next: '', reason: '' };
              return (
                <li key={counter.id} className="space-y-2 p-2 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span><span className="font-mono">{counter.counter_key === 'ALL' ? 'Whole company' : counter.counter_key}</span> <Badge variant="outline">{counter.allocator === 'LOCKED' ? 'No gaps' : 'Gaps possible'}</Badge></span>
                    <span>Next number <span className="font-mono font-semibold">{counter.next_number}</span></span>
                  </div>
                  {canRaise && (
                    <div className="flex flex-wrap items-end gap-2">
                      <div><Label htmlFor={`raise-${counter.id}`}>Raise to</Label><Input id={`raise-${counter.id}`} inputMode="numeric" className="w-28" value={draft.next} onChange={(event) => setDrafts({ ...drafts, [counter.id]: { ...draft, next: event.target.value } })} /></div>
                      <div className="min-w-48 flex-1"><Label htmlFor={`raise-why-${counter.id}`}>Why</Label><Input id={`raise-why-${counter.id}`} value={draft.reason} onChange={(event) => setDrafts({ ...drafts, [counter.id]: { ...draft, reason: event.target.value } })} /></div>
                      <Button type="button" size="sm" variant="outline" disabled={raise.isPending || !draft.next || !draft.reason.trim()}
                        onClick={() => { setError(null); raise.mutate({ counter: counter.id, data: { next: Number(draft.next), reason: draft.reason.trim() } }, { onSuccess: () => setDrafts({ ...drafts, [counter.id]: { next: '', reason: '' } }), onError: (e) => setError(getApiErrorMessage(e)) }); }}>
                        Raise counter
                      </Button>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        <ErrorLine message={error} />
      </div>
    </DetailSection>
  );
}

/** Every format a series has had; issued numbers stay tied to the version that made them. */
export function HistorySection({ series }: { series: NumberingSeries }) {
  const { history } = useSeriesHistory(series.id, true);
  return (
    <DetailSection title="History" description={`Every format ${series.name} has had. Numbers already issued stay tied to the version that made them.`} fullWidth>
      <ol className="space-y-2 text-sm sm:col-span-2" aria-label="Format history">
        {history.map((row) => (
          <li key={row.id} className="rounded-md border p-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-mono">{row.template}</span>
              <Badge variant={row.status === 'ACTIVE' ? 'success' : 'outline'}>{row.status === 'ACTIVE' ? 'In force' : 'Replaced'}</Badge>
            </div>
            <div className="text-xs text-muted-foreground">
              {row.scope_text} · {CONTINUITY_LABELS[row.continuity]} · from {formatDateTime(row.effective_from)}{row.effective_to ? ` to ${formatDateTime(row.effective_to)}` : ''} · {row.changed_by_name ?? 'unknown'}
            </div>
            <div className="text-xs">{row.reason}</div>
          </li>
        ))}
      </ol>
    </DetailSection>
  );
}
