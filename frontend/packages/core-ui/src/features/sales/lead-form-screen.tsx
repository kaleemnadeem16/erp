import { useState, type ReactNode } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { useLeadsStore } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { RegisterPartyDialog, SALES, useIntentKey } from './sales-common';

/** Record an enquiry as it arrived. Only a name is required; nothing about who it resolves to is decided unless the person already knows. */
export function LeadFormScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const backToList = () => navigate({ pathname: '/leads', search });
  const mayManage = usePermission(SALES.lead);
  const mayAssign = usePermission(SALES.assign);
  const store = useLeadsStore();
  const keyFor = useIntentKey();
  const [form, setForm] = useState({ name: '', organisation: '', email: '', phone: '', source: '', description: '', receivedOn: '', partyId: '', assignedTo: '' });
  const [more, setMore] = useState(false);
  const [registering, setRegistering] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const set = (field: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [field]: event.target.value });

  async function save() {
    setShowErrors(true);
    setError(null);
    if (form.name.trim() === '') return;
    try {
      const result = await store.mutateAsync({
        data: {
          request_key: keyFor(JSON.stringify(form)),
          enquirer_name: form.name.trim(),
          enquirer_organisation: form.organisation.trim() || null,
          enquirer_email: form.email.trim() || null,
          enquirer_phone: form.phone.trim() || null,
          source: form.source.trim() || null,
          description: form.description.trim() || null,
          ...(form.receivedOn ? { received_at: form.receivedOn } : {}),
          ...(form.partyId ? { party_id: Number(form.partyId) } : {}),
          ...(form.assignedTo && mayAssign ? { assigned_to: Number(form.assignedTo) } : {}),
        },
      });
      if (result.status === 201 || result.status === 200) navigate({ pathname: `/leads/${result.data.data.id}`, search });
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  /** The same form as a side sheet over the list (the default; never closed by a stray click) or as a page of its own. */
  function present(content: ReactNode) {
    const title = 'Record an enquiry';
    const description = 'Write down who asked and what for, as it arrived.';
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={title} description={description} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="form" className="erp-screen pb-24">
        <div className="space-y-4">
          <PageHeader title={title} description={description} breadcrumbs={[{ label: 'Enquiries', to: `/leads${search}` }, { label: 'New' }]} />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (!mayManage) {
    return present(
      <p className="text-sm text-muted-foreground" data-testid="sales-denied">
        You don't have permission to record enquiries.
      </p>,
    );
  }

  return present(
    <div className="space-y-4" data-testid="lead-form">
      {error && (
        <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      <DetailSection title="The enquiry">
        <div className="space-y-1.5">
          <Label htmlFor="lead-name">Who is asking?</Label>
          <Input id="lead-name" value={form.name} onChange={set('name')} aria-invalid={showErrors && form.name.trim() === ''} />
          {showErrors && form.name.trim() === '' && <p className="text-xs text-destructive">An enquiry needs a name.</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lead-org">Organisation</Label>
          <Input id="lead-org" value={form.organisation} onChange={set('organisation')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lead-email">Email</Label>
          <Input id="lead-email" type="email" value={form.email} onChange={set('email')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lead-phone">Phone</Label>
          <Input id="lead-phone" value={form.phone} onChange={set('phone')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lead-source">Where did it come from?</Label>
          <Input id="lead-source" value={form.source} onChange={set('source')} />
        </div>
        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="lead-description">What do they want?</Label>
          <Textarea id="lead-description" rows={3} value={form.description} onChange={set('description')} />
        </div>
        <div className="sm:col-span-2">
          <MoreOptionsToggle open={more} onOpenChange={setMore} controls="lead-more" label="When it arrived, trading partner and who handles it" />
        </div>
        {more && (
          <div id="lead-more" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="lead-received">Received on</Label>
              <Input id="lead-received" type="date" value={form.receivedOn} onChange={set('receivedOn')} />
              <p className="text-xs text-muted-foreground">Leave empty for now. Set it when you are recording an enquiry that arrived earlier.</p>
            </div>
            {mayAssign && (
              <div className="space-y-1.5">
                <Label>Assign to</Label>
                <OwnerCombobox label="Assign to" placeholder="Leave unassigned" className="w-full" value={form.assignedTo} onValueChange={(value) => setForm({ ...form, assignedTo: value })} />
              </div>
            )}
            <div className="space-y-1.5 sm:col-span-2">
              <Label>Trading partner</Label>
              <div className="flex flex-wrap items-center gap-2">
                <PartyCombobox
                  label="Trading partner"
                  placeholder="Search trading partners"
                  className="min-w-0 flex-1"
                  value={form.partyId}
                  onValueChange={(value) => setForm({ ...form, partyId: value })}
                />
                <Button type="button" variant="outline" size="sm" onClick={() => setRegistering(true)} data-testid="register-party">
                  Register new…
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">Only if you already know who this is. Choosing one never creates a customer account.</p>
            </div>
          </div>
        )}
      </DetailSection>
      <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
        <Button variant="outline" onClick={backToList}>
          Cancel
        </Button>
        <Button onClick={() => void save()} disabled={store.isPending} data-testid="lead-save">
          {store.isPending ? 'Saving…' : 'Record enquiry'}
        </Button>
      </div>
      <RegisterPartyDialog open={registering} onOpenChange={setRegistering} onChosen={(party) => setForm((current) => ({ ...current, partyId: String(party.id) }))} />
    </div>,
  );
}
