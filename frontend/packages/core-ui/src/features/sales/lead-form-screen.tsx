import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useLeadsStore } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { SALES, useIntentKey } from './sales-common';

/** Record an enquiry as it arrived. Only a name is required; nothing about who it resolves to is decided here. */
export function LeadFormScreen() {
  const navigate = useNavigate();
  const mayManage = usePermission(SALES.lead);
  const store = useLeadsStore();
  const keyFor = useIntentKey();
  const [form, setForm] = useState({ name: '', organisation: '', email: '', phone: '', source: '', description: '' });
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
        },
      });
      if (result.status === 201 || result.status === 200) navigate(`/leads/${result.data.data.id}`);
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  if (!mayManage) {
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <p className="text-sm text-muted-foreground" data-testid="sales-denied">
          You don't have permission to record enquiries.
        </p>
      </ScreenTemplate>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="lead-form">
        <PageHeader title="Record an enquiry" description="Write down who asked and what for, as it arrived." breadcrumbs={[{ label: 'Enquiries', to: '/leads' }, { label: 'New' }]} />
        {error && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}
        <DetailSection title="The enquiry">
          <div className="space-y-1">
            <Label htmlFor="lead-name">Who is asking?</Label>
            <Input id="lead-name" value={form.name} onChange={set('name')} aria-invalid={showErrors && form.name.trim() === ''} />
            {showErrors && form.name.trim() === '' && <p className="text-xs text-destructive">An enquiry needs a name.</p>}
          </div>
          <div className="space-y-1">
            <Label htmlFor="lead-org">Organisation</Label>
            <Input id="lead-org" value={form.organisation} onChange={set('organisation')} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="lead-email">Email</Label>
            <Input id="lead-email" type="email" value={form.email} onChange={set('email')} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="lead-phone">Phone</Label>
            <Input id="lead-phone" value={form.phone} onChange={set('phone')} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="lead-source">Where did it come from?</Label>
            <Input id="lead-source" value={form.source} onChange={set('source')} />
          </div>
          <div className="space-y-1 sm:col-span-2">
            <Label htmlFor="lead-description">What do they want?</Label>
            <Textarea id="lead-description" rows={3} value={form.description} onChange={set('description')} />
          </div>
        </DetailSection>
        <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
          <Button variant="outline" onClick={() => navigate('/leads')}>
            Cancel
          </Button>
          <Button onClick={() => void save()} disabled={store.isPending} data-testid="lead-save">
            {store.isPending ? 'Saving…' : 'Record enquiry'}
          </Button>
        </div>
      </div>
    </ScreenTemplate>
  );
}
