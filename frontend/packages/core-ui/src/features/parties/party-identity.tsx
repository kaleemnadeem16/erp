import { useState } from 'react';
import { usePartiesCandidates, usePartiesRestore, type PartyResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { getApiErrorMessage } from '../../lib/api-errors';
import { suggestPartyCode } from '../customers/customer-rules';

export type PartyKindValue = 'ORGANISATION' | 'INDIVIDUAL';

export interface PartyIdentityValues {
  code: string;
  name: string;
  kind: PartyKindValue;
  taxId: string;
  registration: string;
  website: string;
}

export const EMPTY_PARTY_IDENTITY: PartyIdentityValues = { code: '', name: '', kind: 'ORGANISATION', taxId: '', registration: '', website: '' };

export type PartyIdentityErrors = Partial<Record<'code' | 'name' | 'kind' | 'tax_id' | 'registration_no' | 'website', string>>;

export function validatePartyIdentity(values: PartyIdentityValues): PartyIdentityErrors {
  const errors: PartyIdentityErrors = {};
  if (!values.name.trim()) errors.name = 'Enter the trading partner’s name.';
  else if (values.name.trim().length > 255) errors.name = 'The name can be at most 255 characters.';
  if (values.code.trim().length > 255) errors.code = 'The code can be at most 255 characters.';

  return errors;
}

/** Optional identifiers are sent as `null` when emptied so a stored value can be cleared. */
function identityFields(values: PartyIdentityValues) {
  return {
    name: values.name.trim(),
    kind: values.kind,
    tax_id: values.taxId.trim() || null,
    registration_no: values.registration.trim() || null,
    website: values.website.trim() || null,
  };
}

/** The body for registering a trading partner. The code is generated from the name when left blank (the person can still type their own). */
export function partyCreatePayload(values: PartyIdentityValues, customFields: Record<string, string | number | boolean> = {}) {
  return { code: values.code.trim() || suggestPartyCode(values.name), ...identityFields(values), custom_fields: customFields };
}

/** The body for editing a trading partner: a blank code is left out (the stored one stays), everything else is replaced. */
export function partyUpdatePayload(values: PartyIdentityValues, customFields?: Record<string, string | number | boolean>) {
  return { ...(values.code.trim() ? { code: values.code.trim() } : {}), ...identityFields(values), ...(customFields ? { custom_fields: customFields } : {}) };
}

/** Name, kind and identifiers of a trading partner. One field set for the Customers "register a partner" block and the Trading partners form. */
export function PartyIdentityFields({
  idPrefix,
  values,
  onChange,
  errors,
  codeHint,
}: {
  idPrefix: string;
  values: PartyIdentityValues;
  onChange: (values: PartyIdentityValues) => void;
  errors: PartyIdentityErrors;
  codeHint?: string;
}) {
  const set = <K extends keyof PartyIdentityValues>(key: K, value: PartyIdentityValues[K]) => onChange({ ...values, [key]: value });
  const text = (key: 'name' | 'code' | 'taxId' | 'registration' | 'website', errorKey: keyof PartyIdentityErrors, label: string, extra: { required?: boolean; hint?: string; type?: string } = {}) => {
    const id = `${idPrefix}-${key}`;

    return (
      <div className="space-y-1.5">
        <Label htmlFor={id}>{label}</Label>
        <Input
          id={id}
          className="h-11 md:h-10"
          type={extra.type ?? 'text'}
          autoComplete="off"
          required={extra.required}
          value={values[key]}
          aria-invalid={errors[errorKey] ? true : undefined}
          aria-describedby={errors[errorKey] ? `${id}-error` : extra.hint ? `${id}-hint` : undefined}
          onChange={(event) => set(key, event.target.value)}
        />
        {extra.hint && !errors[errorKey] && (
          <p id={`${id}-hint`} className="text-xs text-muted-foreground">
            {extra.hint}
          </p>
        )}
        {errors[errorKey] && (
          <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
            {errors[errorKey]}
          </p>
        )}
      </div>
    );
  };

  return (
    <>
      {text('name', 'name', 'Business name', { required: true })}
      <div className="space-y-1.5">
        <Label htmlFor={`${idPrefix}-kind`}>Kind</Label>
        <Select value={values.kind} onValueChange={(value) => set('kind', value as PartyKindValue)}>
          <SelectTrigger id={`${idPrefix}-kind`} className="h-11 md:h-10" aria-label="Kind">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="ORGANISATION">Organisation</SelectItem>
            <SelectItem value="INDIVIDUAL">Individual</SelectItem>
          </SelectContent>
        </Select>
      </div>
      {text('code', 'code', 'Partner code', { hint: codeHint })}
      {text('taxId', 'tax_id', 'Tax identifier')}
      {text('registration', 'registration_no', 'Registration number')}
      {text('website', 'website', 'Website', { type: 'url' })}
    </>
  );
}

/**
 * "Already here?" — probable matches for the name being typed, offered for reuse or (for an archived one) restoration. Nothing is merged, overwritten or created
 * (ADR 0105); a search the person is not permitted to run says so instead of silently showing nothing.
 */
export function PartyCandidatePanel({ name, onUse }: { name: string; onUse: (party: Pick<PartyResource, 'id'> & Partial<PartyResource>) => void }) {
  const probe = name.trim();
  const candidates = usePartiesCandidates({ name: probe }, { query: { enabled: probe.length >= 3, retry: false } });
  const restore = usePartiesRestore();
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const found = candidates.data?.status === 200 ? candidates.data.data.data : [];

  async function use(id: number, archived: boolean) {
    setError(null);
    setPending(true);
    try {
      if (archived) {
        const restored = await restore.mutateAsync({ party: id });
        onUse(restored.status === 200 ? restored.data.data : { id });
      } else {
        onUse({ id });
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure) ?? 'That trading partner could not be used.');
    } finally {
      setPending(false);
    }
  }

  if (probe.length < 3) return null;
  if (candidates.isError) {
    return (
      <p className="text-sm text-muted-foreground sm:col-span-2" data-testid="party-candidates-denied">
        Existing trading partners could not be checked for duplicates ({getApiErrorMessage(candidates.error) ?? 'not permitted'}). Check the Trading partners list before registering.
      </p>
    );
  }
  if (found.length === 0) return null;

  return (
    <div className="space-y-2 rounded-md border border-dashed p-3 sm:col-span-2" data-testid="party-candidates">
      <p className="text-sm font-medium">Already here? Use one of these instead.</p>
      {found.map((candidate) => (
        <div key={candidate.id} className="flex flex-wrap items-center justify-between gap-2 text-sm" data-testid="party-candidate">
          <span className="min-w-0 break-words">
            {candidate.name} <span className="text-muted-foreground">({candidate.code}, matched on {candidate.matched_on.join(', ')})</span>{' '}
            {candidate.status === 'ARCHIVED' && <Badge variant="secondary">Archived</Badge>}
          </span>
          <Button type="button" size="sm" variant="outline" className="h-11 md:h-9" disabled={pending} onClick={() => void use(candidate.id, candidate.status === 'ARCHIVED')}>
            {candidate.status === 'ARCHIVED' ? 'Restore and use' : 'Use this one'}
          </Button>
        </div>
      ))}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}
