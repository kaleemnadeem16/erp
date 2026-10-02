import { useState } from 'react';
import { useApprovalDelegationsDestroy, useApprovalDelegationsIndex, useApprovalDelegationsStore, useApprovalSubjectTypesIndex, useMembersIndex } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { ListError } from '../../components/data-table';
import { applyApiErrorsToForm, getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { useForm } from 'react-hook-form';
import { usePermission } from '../../hooks/use-permission';
import { formatDate } from '../../lib/format-date';

const ALL = 'ALL';

/**
 * Cover while someone is away (ADR 0070 §6). The delegate acts *as* the delegator — within the delegator's authority and
 * no further — for the dates given; it never adds the delegate's own authority, and one person can never fill two slots of
 * a step. Anyone may delegate their own approvals; everyone sees the delegations that involve them.
 */
export function DelegationsPanel() {
  const query = useApprovalDelegationsIndex();
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const members = useMembersIndex({ per_page: 100 });
  const memberRows = members.data?.status === 200 ? members.data.data.data : [];
  const subjects = useApprovalSubjectTypesIndex({ query: { retry: false } });
  const subjectRows = subjects.data?.status === 200 ? subjects.data.data.data : [];
  const mayManageAll = usePermission('approvals.delegation.manage');
  const store = useApprovalDelegationsStore();
  const revoke = useApprovalDelegationsDestroy();
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [delegate, setDelegate] = useState('');
  const [subject, setSubject] = useState(ALL);
  const form = useForm<{ valid_from: string; valid_to: string; reason: string }>({ defaultValues: { valid_from: '', valid_to: '', reason: '' } });

  function submit(values: { valid_from: string; valid_to: string; reason: string }) {
    setError(null);
    if (delegate === '') {
      setError('Choose who is covering for you.');
      return;
    }
    store.mutate(
      { data: { delegate_id: Number(delegate), subject_type: subject === ALL ? null : (subject as never), valid_from: values.valid_from, valid_to: values.valid_to, reason: values.reason } },
      {
        onSuccess: () => { setAdding(false); setDelegate(''); form.reset(); void query.refetch(); },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['valid_from', 'valid_to', 'reason'])),
      },
    );
  }

  return (
    <section className="space-y-3 rounded-lg border p-4" aria-labelledby="delegations-heading">
      <div>
        <h2 id="delegations-heading" className="font-semibold">Cover while you are away</h2>
        <p className="text-sm text-muted-foreground">
          Let a colleague approve on your behalf for a set period. They can only approve what <em>you</em> could, up to <em>your</em> limit — never more.
        </p>
      </div>
      {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
      {query.isError ? (
        <ListError message="Couldn't load delegations." error={query.error} onRetry={() => void query.refetch()} />
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No delegations.</p>
      ) : (
        <ul className="divide-y rounded-md border">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
              <div>
                <span className="font-medium">{row.delegator_name}</span> → <span className="font-medium">{row.delegate_name}</span>
                <p className="text-xs text-muted-foreground">{formatDate(row.valid_from)} to {formatDate(row.valid_to)} · {row.subject_type ?? 'every kind of approval'} · {row.reason}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={row.in_force ? 'success' : 'outline'}>{row.in_force ? 'In force' : row.revoked_at ? 'Revoked' : 'Not in force'}</Badge>
                {row.in_force && (
                  <Button type="button" size="sm" variant="outline" aria-label={`Revoke delegation to ${row.delegate_name}`} disabled={revoke.isPending}
                    onClick={() => revoke.mutate({ approvalDelegation: row.id }, { onSuccess: () => void query.refetch(), onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not revoke.') })}>
                    Revoke
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {adding ? (
        <form className="space-y-3 rounded-md border bg-muted/30 p-3" onSubmit={form.handleSubmit(submit)} aria-label="Add a delegation">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label>Covered by</Label>
              <Combobox {...lookupState(members, 'people')} aria-label="Covered by" value={delegate} onValueChange={setDelegate}
                options={memberRows.map((m) => ({ value: String(m.id), label: `${m.name} (${m.email})` }))} placeholder="Choose a colleague" searchPlaceholder="Search people…" emptyText="No one found." />
            </div>
            <div className="space-y-1">
              <Label>For</Label>
              <Combobox aria-label="For" value={subject} onValueChange={setSubject}
                options={[{ value: ALL, label: 'Every kind of approval' }, ...subjectRows.map((s) => ({ value: s.code, label: s.label }))]} searchPlaceholder="Search…" emptyText="Nothing found." />
            </div>
            <div className="space-y-1"><Label htmlFor="delegation-from">From</Label><Input id="delegation-from" type="date" {...form.register('valid_from', { required: true })} /></div>
            <div className="space-y-1"><Label htmlFor="delegation-to">To</Label><Input id="delegation-to" type="date" {...form.register('valid_to', { required: true })} /></div>
          </div>
          <div className="space-y-1"><Label htmlFor="delegation-reason">Reason</Label><Input id="delegation-reason" {...form.register('reason', { required: true })} placeholder="e.g. Annual leave" /></div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button>
            <Button type="submit" disabled={store.isPending}>{store.isPending ? 'Saving…' : 'Add delegation'}</Button>
          </div>
        </form>
      ) : (
        <Button type="button" variant="outline" onClick={() => setAdding(true)}>Delegate my approvals</Button>
      )}
      {mayManageAll && <p className="text-xs text-muted-foreground">You can see every delegation in the company.</p>}
    </section>
  );
}
