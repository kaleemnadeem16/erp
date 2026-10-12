import { useState } from 'react';
import { useLeadsUpdate, type LeadResource } from '@erp/core-api';

import { DetailField } from '../../components/detail/detail-field';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { getApiErrorMessage, getApiReasonCode } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useIntentKey } from './sales-common';

const FIELDS = [
  ['enquirer_name', 'Who is asking?'],
  ['enquirer_organisation', 'Organisation'],
  ['enquirer_email', 'Email'],
  ['enquirer_phone', 'Phone'],
  ['source', 'Where did it come from?'],
  ['description', 'What do they want?'],
] as const;
type Field = (typeof FIELDS)[number][0];

const LABEL = Object.fromEntries(FIELDS) as Record<Field, string>;

/** The enquiry as received, the corrections made to the current details since, and why: nothing here can be edited. */
export function LeadEnquiryEvidence({ lead }: { lead: LeadResource }) {
  const original = lead.original_intake;
  const corrections = lead.enquiry_corrections ?? [];

  return (
    <DetailSection title="As received" description="What was first recorded stays as it was. Corrections change the current details above and are listed here with their reasons." fullWidth>
      <div className="space-y-3 sm:col-span-2" data-testid="lead-evidence">
        {original ? (
          <>
            <p className="text-sm" data-testid="lead-original-kind">
              {original.evidence_kind === 'ORIGINAL_INTAKE'
                ? `Original intake, recorded ${formatDateTime(original.captured_at)}.`
                : `Baseline captured ${formatDateTime(original.captured_at)}: this enquiry predates intake evidence, so its details at that time are kept as the baseline, not as a proven original.`}
              {' '}Received {formatDateTime(original.received_at)}.
            </p>
            <dl className="grid gap-2 sm:grid-cols-2" data-testid="lead-original-values">
              {FIELDS.map(([field, label]) => (
                <DetailField key={field} label={label}>
                  {original[field] ?? '—'}
                </DetailField>
              ))}
            </dl>
          </>
        ) : (
          <p className="text-sm text-muted-foreground">No intake evidence is available.</p>
        )}
        {corrections.length === 0 ? (
          <p className="text-sm text-muted-foreground">No corrections have been made.</p>
        ) : (
          <ol className="space-y-3" data-testid="lead-corrections">
            {[...corrections].reverse().map((correction) => (
              <li key={correction.id} className="border-l-2 border-border pl-3 text-sm" data-testid="lead-correction">
                <p className="font-medium">Version {correction.resulting_version}</p>
                <p className="text-muted-foreground">“{correction.reason}”</p>
                <ul className="mt-1 space-y-0.5">
                  {correction.fields.map((change) => (
                    <li key={change.field_name}>
                      {LABEL[change.field_name as Field] ?? change.field_name}: <span className="text-muted-foreground line-through">{change.old_value ?? '—'}</span> → {change.new_value ?? '—'}
                    </li>
                  ))}
                </ul>
                <p className="text-xs text-muted-foreground">{formatDateTime(correction.recorded_at)}</p>
              </li>
            ))}
          </ol>
        )}
      </div>
    </DetailSection>
  );
}

/** Correct the current enquiry details in any state. Status, partner, assignee and the received date are not part of it. */
export function LeadCorrectionDialog({ lead, open, onClose, onSaved }: { lead: LeadResource; open: boolean; onClose: () => void; onSaved: () => Promise<void> }) {
  const update = useLeadsUpdate();
  const keyFor = useIntentKey();
  const current = (): Record<Field, string> => ({
    enquirer_name: lead.enquirer_name,
    enquirer_organisation: lead.enquirer_organisation ?? '',
    enquirer_email: lead.enquirer_email ?? '',
    enquirer_phone: lead.enquirer_phone ?? '',
    source: lead.source ?? '',
    description: lead.description ?? '',
  });
  const [form, setForm] = useState<Record<Field, string> | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const values = form ?? current();
  const changed = FIELDS.filter(([field]) => values[field].trim() !== (current()[field] ?? '').trim()).map(([field]) => field);
  const nameBlank = values.enquirer_name.trim() === '';

  function close() {
    setForm(null);
    setReason('');
    setError(null);
    onClose();
  }

  async function save() {
    setError(null);
    const data: Record<string, string | null> = {};
    for (const field of changed) data[field] = values[field].trim() === '' ? null : values[field].trim();
    try {
      await update.mutateAsync({
        lead: lead.id,
        data: { ...data, reason: reason.trim(), expected_enquiry_version: lead.enquiry_version, request_key: keyFor(JSON.stringify({ data, reason: reason.trim(), version: lead.enquiry_version })) },
      });
      await onSaved();
      close();
    } catch (failure) {
      if (getApiReasonCode(failure) === 'STALE_EVIDENCE') {
        setError('This enquiry was corrected by someone else since you opened it. It has been refreshed: review the current details, then correct again.');
        setForm(null);
        await onSaved();
      } else {
        setError(getApiErrorMessage(failure));
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && close()}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="lead-correct-dialog">
        <DialogHeader>
          <DialogTitle>Correct enquiry details</DialogTitle>
          <DialogDescription>The enquiry as received is kept; your change becomes the current detail, with your reason. Status, partner and assignee are not affected.</DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          {FIELDS.map(([field, label]) => (
            <div key={field} className="space-y-1">
              <Label htmlFor={`correct-${field}`}>{label}</Label>
              {field === 'description' ? (
                <Textarea id={`correct-${field}`} rows={3} value={values[field]} onChange={(event) => setForm({ ...values, [field]: event.target.value })} />
              ) : (
                <Input id={`correct-${field}`} value={values[field]} onChange={(event) => setForm({ ...values, [field]: event.target.value })} aria-invalid={field === 'enquirer_name' && nameBlank} />
              )}
              {field === 'enquirer_name' && nameBlank && <p className="text-xs text-destructive">An enquiry needs a name.</p>}
            </div>
          ))}
          <div className="space-y-1">
            <Label htmlFor="correct-reason">Why is this being corrected?</Label>
            <Textarea id="correct-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={close}>
            Go back
          </Button>
          <Button disabled={changed.length === 0 || nameBlank || reason.trim() === '' || update.isPending} data-testid="lead-correct-confirm" onClick={() => void save()}>
            {update.isPending ? 'Saving…' : 'Save correction'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
