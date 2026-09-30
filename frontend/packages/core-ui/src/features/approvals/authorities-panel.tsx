import { useState } from 'react';
import { useApprovalAuthoritiesDestroy, useApprovalAuthoritiesIndex, useApprovalAuthoritiesStore, useMembersIndex, type ApprovalSubjectTypesIndex200DataItem } from '@erp/core-api';
import { useForm } from 'react-hook-form';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { ListError } from '../../components/data-table';
import { applyApiErrorsToForm, getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { formatDate } from '../../lib/format-date';
import { trimZeros } from './approval-labels';

/**
 * Amount ceilings held as data (ADR 0070 §5), never as permissions like "approve-under-10000": how much each person may
 * approve for a kind of document, optionally for a period. It matters only for steps that use authority limits, and
 * changing it affects future decisions only — decisions already made keep the authority they were made under.
 */
export function AuthoritiesPanel({ subjectTypes, mayManage }: { subjectTypes: ApprovalSubjectTypesIndex200DataItem[]; mayManage: boolean }) {
  const query = useApprovalAuthoritiesIndex();
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const members = useMembersIndex({ per_page: 100 });
  const memberRows = members.data?.status === 200 ? members.data.data.data : [];
  const nameOf = (id: number) => memberRows.find((m) => m.id === id)?.name ?? `User #${id}`;
  const store = useApprovalAuthoritiesStore();
  const revoke = useApprovalAuthoritiesDestroy();
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [user, setUser] = useState('');
  const [subject, setSubject] = useState(subjectTypes[0]?.code ?? '');
  const [unlimited, setUnlimited] = useState(false);
  const form = useForm<{ ceiling: string; valid_from: string; valid_to: string; reason: string }>({ defaultValues: { ceiling: '', valid_from: '', valid_to: '', reason: '' } });

  function submit(values: { ceiling: string; valid_from: string; valid_to: string; reason: string }) {
    setError(null);
    if (user === '') {
      setError('Choose the person.');
      return;
    }
    store.mutate(
      {
        data: {
          user_id: Number(user), subject_type: subject as never, ...(unlimited ? { unlimited: true } : { ceiling_base_amount: values.ceiling }),
          valid_from: values.valid_from === '' ? null : values.valid_from, valid_to: values.valid_to === '' ? null : values.valid_to, reason: values.reason,
        },
      },
      {
        onSuccess: () => { setAdding(false); setUser(''); setUnlimited(false); form.reset(); void query.refetch(); },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['reason']) ?? getApiErrorMessage(e)),
      },
    );
  }

  return (
    <section className="space-y-3 rounded-lg border p-4" aria-labelledby="authorities-heading">
      <div>
        <h2 id="authorities-heading" className="font-semibold">Approval limits</h2>
        <p className="text-sm text-muted-foreground">How much each person may approve, in the company base currency. Only steps that “use authority limits” look at this.</p>
      </div>
      {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
      {query.isError ? (
        <ListError message="Couldn't load approval limits." error={query.error} onRetry={() => void query.refetch()} />
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No limits set yet.</p>
      ) : (
        <ul className="divide-y rounded-md border">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
              <div>
                <span className="font-medium">{nameOf(row.user_id)}</span> · {subjectTypes.find((s) => s.code === row.subject_type)?.label ?? row.subject_type}
                <p className="text-xs text-muted-foreground">
                  {row.unlimited ? 'No ceiling' : `Up to ${trimZeros(row.ceiling_base_amount)}`}
                  {row.valid_from || row.valid_to ? ` · ${row.valid_from ? formatDate(row.valid_from) : 'any time'} to ${row.valid_to ? formatDate(row.valid_to) : 'open-ended'}` : ''} · {row.reason}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={row.in_force ? 'success' : 'outline'}>{row.in_force ? 'In force' : row.revoked_at ? 'Revoked' : 'Not in force'}</Badge>
                {mayManage && row.in_force && (
                  <Button type="button" size="sm" variant="outline" aria-label={`Revoke limit for ${nameOf(row.user_id)}`} disabled={revoke.isPending}
                    onClick={() => revoke.mutate({ approvalAuthority: row.id }, { onSuccess: () => void query.refetch(), onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not revoke.') })}>
                    Revoke
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {mayManage && (adding ? (
        <form className="space-y-3 rounded-md border bg-muted/30 p-3" onSubmit={form.handleSubmit(submit)} aria-label="Add an approval limit">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1"><Label>Person</Label>
              <Combobox {...lookupState(members, 'people')} aria-label="Person" value={user} onValueChange={setUser} options={memberRows.map((m) => ({ value: String(m.id), label: `${m.name} (${m.email})` }))} placeholder="Choose a person" searchPlaceholder="Search people…" emptyText="No one found." />
            </div>
            <div className="space-y-1"><Label>For</Label>
              <Combobox aria-label="Kind of approval" value={subject} onValueChange={setSubject} options={subjectTypes.map((s) => ({ value: s.code, label: s.label }))} searchPlaceholder="Search…" emptyText="Nothing found." />
            </div>
            <div className="space-y-1"><Label htmlFor="authority-ceiling">Ceiling</Label><Input id="authority-ceiling" inputMode="decimal" disabled={unlimited} {...form.register('ceiling')} /></div>
            <label className="flex items-center gap-2 self-end text-sm"><Checkbox checked={unlimited} onCheckedChange={(checked) => setUnlimited(checked === true)} />No ceiling (unlimited)</label>
            <div className="space-y-1"><Label htmlFor="authority-from">From (optional)</Label><Input id="authority-from" type="date" {...form.register('valid_from')} /></div>
            <div className="space-y-1"><Label htmlFor="authority-to">To (optional)</Label><Input id="authority-to" type="date" {...form.register('valid_to')} /></div>
          </div>
          <div className="space-y-1"><Label htmlFor="authority-reason">Why?</Label><Input id="authority-reason" {...form.register('reason', { required: true })} placeholder="e.g. Purchasing manager" /></div>
          <div className="flex justify-end gap-2"><Button type="button" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button><Button type="submit" disabled={store.isPending}>Add limit</Button></div>
        </form>
      ) : (
        <Button type="button" variant="outline" onClick={() => setAdding(true)}>Add a limit</Button>
      ))}
    </section>
  );
}
