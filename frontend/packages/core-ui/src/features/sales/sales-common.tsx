import { useRef, useState } from 'react';
import { usePartiesCandidates, usePartiesRestore, usePartiesStore, useSalesStagesIndex, type PartyResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';

/** The Sales capabilities, by duty (the server is the authority; these only decide what to show). */
export const SALES = {
  view: 'sales.pipeline.view',
  lead: 'sales.lead.manage',
  opportunity: 'sales.opportunity.manage',
  assign: 'sales.opportunity.assign',
  close: 'sales.opportunity.close',
  stage: 'sales.stage.manage',
} as const;

/** A fresh request key; a retry of the same intent reuses it so the server records it once. */
export const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(16).slice(2)}`);

/**
 * One key per intent: the same request retried (a double click, a dropped connection) keeps its key, so it is recorded once; any change to what is being
 * asked gets a new key, because the server refuses a key reused for a different request.
 */
export function useIntentKey(): (intent: string) => string {
  const current = useRef<{ intent: string; key: string } | null>(null);

  return (intent: string) => {
    if (current.current === null || current.current.intent !== intent) current.current = { intent, key: newKey() };

    return current.current.key;
  };
}

/** Whether the person may use any Sales surface at all. */
export function useMaySeeSales(): boolean {
  const permissions = [usePermission(SALES.view), usePermission(SALES.lead), usePermission(SALES.opportunity), usePermission(SALES.assign), usePermission(SALES.close)];

  return permissions.some(Boolean);
}

export const OUTCOME_LABEL: Record<string, string> = { OPEN: 'Open', WON: 'Won', LOST: 'Lost', ABANDONED: 'Abandoned' };
const OUTCOME_TONE: Record<string, StatusTone> = { OPEN: 'active', WON: 'success', LOST: 'danger', ABANDONED: 'draft' };

export function OutcomeBadge({ outcome }: { outcome: string }) {
  return <StatusBadge tone={OUTCOME_TONE[outcome] ?? 'draft'}>{OUTCOME_LABEL[outcome] ?? outcome}</StatusBadge>;
}

export const LEAD_LABEL: Record<string, string> = { NEW: 'New', QUALIFIED: 'Qualified', DISQUALIFIED: 'Disqualified' };
const LEAD_TONE: Record<string, StatusTone> = { NEW: 'pending', QUALIFIED: 'success', DISQUALIFIED: 'draft' };

export function LeadStatusBadge({ status }: { status: string }) {
  return <StatusBadge tone={LEAD_TONE[status] ?? 'draft'}>{LEAD_LABEL[status] ?? status}</StatusBadge>;
}

/** The active and archived stages of the company, oldest-first as configured. */
export function useSalesStages() {
  const query = useSalesStagesIndex();
  const stages = query.data?.status === 200 ? query.data.data.data : [];

  return { query, stages, active: stages.filter((stage) => stage.status === 'ACTIVE') };
}

/** A history row in the words a salesperson uses. */
export function describeEvent(event: { event: string; reason?: string | null; details?: Record<string, unknown> }): string {
  const d = (event.details ?? {}) as Record<string, string | number | null | undefined>;
  switch (event.event) {
    case 'LEAD_RECEIVED':
      return 'Enquiry recorded';
    case 'LEAD_ASSIGNED':
      return d.to ? 'Enquiry assigned' : 'Enquiry left unassigned';
    case 'LEAD_PARTY_LINKED':
      return `Linked to ${d.to_party_name ?? 'a trading partner'}`;
    case 'LEAD_QUALIFIED':
      return 'Qualified';
    case 'LEAD_DISQUALIFIED':
      return 'Disqualified';
    case 'LEAD_REVIVED':
      return 'Revived';
    case 'LEAD_ENQUIRY_CORRECTED':
      return 'Enquiry details corrected';
    case 'LEAD_CONVERTED':
      return 'Converted to an opportunity';
    case 'OPPORTUNITY_CREATED':
      return d.from_lead_id ? 'Opportunity created from an enquiry' : 'Opportunity created';
    case 'OWNER_CHANGED':
      return 'Owner changed';
    case 'STAGE_CHANGED':
      return `Stage: ${d.from_stage_name ?? '—'} → ${d.to_stage_name ?? '—'}`;
    case 'CLOSED':
      return `Closed as ${OUTCOME_LABEL[String(d.outcome)] ?? d.outcome}`;
    case 'REOPENED':
      return 'Reopened';
    case 'OPPORTUNITY_ATTRIBUTION_CORRECTED':
      return 'Recorded responsibility corrected';
    default:
      return event.event;
  }
}

export function HistoryList({ rows }: { rows: Array<{ id: number; event: string; actor_name?: string | null; occurred_at: string; reason?: string | null; details?: Record<string, unknown> }> }) {
  if (rows.length === 0) return <p className="text-sm text-muted-foreground">Nothing recorded yet.</p>;

  return (
    <ol className="space-y-3" data-testid="sales-history">
      {rows.map((row) => (
        <li key={row.id} className="border-l-2 border-border pl-3 text-sm" data-testid="sales-history-row">
          <p className="font-medium">{describeEvent(row)}</p>
          {row.reason && <p className="text-muted-foreground">“{row.reason}”</p>}
          <p className="text-xs text-muted-foreground">
            {row.actor_name ?? 'Someone'} · {formatDateTime(row.occurred_at)}
          </p>
        </li>
      ))}
    </ol>
  );
}

export function NotesList({ rows }: { rows: Array<{ id: number; body: string; author_name?: string | null; created_at: string }> }) {
  if (rows.length === 0) return <p className="text-sm text-muted-foreground">No notes yet.</p>;

  return (
    <ul className="space-y-3" data-testid="sales-notes">
      {rows.map((note) => (
        <li key={note.id} className="rounded-md border p-3 text-sm" data-testid="sales-note">
          <p className="whitespace-pre-wrap break-words">{note.body}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {note.author_name ?? 'Someone'} · {formatDateTime(note.created_at)}
          </p>
        </li>
      ))}
    </ul>
  );
}

/**
 * Register a trading partner for a deal or an enquiry — after looking for it (ADR 0105). The name is searched as it is typed; probable matches are offered for
 * reuse (or, for an archived one, restoration) and nothing is merged. Needs Party maintenance; without it the person is told to ask for the partner to be added.
 */
export function RegisterPartyDialog({ open, onOpenChange, initialName, onChosen }: { open: boolean; onOpenChange: (open: boolean) => void; initialName?: string; onChosen: (party: PartyResource) => void }) {
  const mayManage = usePermission('parties.party.manage');
  const [name, setName] = useState(initialName ?? '');
  const [kind, setKind] = useState<'ORGANISATION' | 'INDIVIDUAL'>('ORGANISATION');
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const probe = name.trim();
  const candidates = usePartiesCandidates({ name: probe }, { query: { enabled: open && probe.length >= 3, retry: false } });
  const found = candidates.data?.status === 200 ? candidates.data.data.data : [];
  const store = usePartiesStore();
  const restore = usePartiesRestore();

  async function register() {
    setError(null);
    setPending(true);
    try {
      const code = (probe.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8) || 'PTY') + '-' + Date.now().toString().slice(-4);
      const result = await store.mutateAsync({ data: { code, name: probe, kind } });
      if (result.status === 201) {
        onChosen(result.data.data);
        onOpenChange(false);
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    } finally {
      setPending(false);
    }
  }

  async function reuse(id: number, archived: boolean) {
    setError(null);
    setPending(true);
    try {
      const party = archived ? await restore.mutateAsync({ party: id }) : null;
      const chosen = party && party.status === 200 ? party.data.data : undefined;
      if (chosen) onChosen(chosen);
      else if (!archived) onChosen({ id } as PartyResource);
      onOpenChange(false);
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    } finally {
      setPending(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="register-party-dialog">
        <DialogHeader>
          <DialogTitle>Register a trading partner</DialogTitle>
          <DialogDescription>Check whether they are already here first. Nothing is merged or changed automatically.</DialogDescription>
        </DialogHeader>
        {!mayManage ? (
          <p className="text-sm text-muted-foreground" data-testid="register-party-denied">
            You don't maintain trading partners. Ask someone who does to add this one, then choose it here.
          </p>
        ) : (
          <div className="space-y-3">
            <div className="space-y-1">
              <Label htmlFor="party-name-input">Name</Label>
              <Input id="party-name-input" value={name} onChange={(event) => setName(event.target.value)} />
            </div>
            <div className="space-y-1">
              <Label>Kind</Label>
              <Select value={kind} onValueChange={(value) => setKind(value as 'ORGANISATION' | 'INDIVIDUAL')}>
                <SelectTrigger aria-label="Kind">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="ORGANISATION">Organisation</SelectItem>
                  <SelectItem value="INDIVIDUAL">Individual</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {candidates.data?.status === 403 && (
              <p className="text-sm text-muted-foreground" data-testid="party-candidates-denied">
                You are not permitted to search existing identities, so duplicates cannot be checked here.
              </p>
            )}
            {found.length > 0 && (
              <div className="space-y-2 rounded-md border border-dashed p-3" data-testid="party-candidates">
                <p className="text-sm font-medium">Already here? Use one of these instead.</p>
                {found.map((candidate) => (
                  <div key={candidate.id} className="flex flex-wrap items-center justify-between gap-2 text-sm" data-testid="party-candidate">
                    <span className="min-w-0 break-words">
                      {candidate.name} <span className="text-muted-foreground">({candidate.code}, matched on {candidate.matched_on.join(', ')})</span>{' '}
                      {candidate.status === 'ARCHIVED' && <Badge variant="secondary">Archived</Badge>}
                    </span>
                    <Button type="button" size="sm" variant="outline" disabled={pending} onClick={() => void reuse(candidate.id, candidate.status === 'ARCHIVED')}>
                      {candidate.status === 'ARCHIVED' ? 'Restore and use' : 'Use this one'}
                    </Button>
                  </div>
                ))}
              </div>
            )}
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          {mayManage && (
            <Button onClick={() => void register()} disabled={pending || probe.length < 2} data-testid="register-party-submit">
              {found.length > 0 ? 'Register as new anyway' : 'Register'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
