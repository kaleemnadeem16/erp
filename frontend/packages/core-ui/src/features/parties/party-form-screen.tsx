import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { usePartiesShow, usePartiesStore, usePartiesUpdate, type PartyResource } from '@erp/core-api';
import { History, RotateCcw } from 'lucide-react';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Collapse } from '../../components/ui/collapse';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { SectionStack } from '../../components/ui/section-stack';
import { Skeleton } from '../../components/ui/skeleton';
import { getApiErrorMessage, isApiError } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { clearDraft, readDraft, writeDraft } from '../../lib/session-draft';
import { CustomFieldInputs, customFieldValues, type CustomFieldValues } from '../custom-fields/custom-field-inputs';
import {
  EMPTY_PARTY_IDENTITY,
  PartyCandidatePanel,
  PartyIdentityFields,
  partyCreatePayload,
  partyUpdatePayload,
  validatePartyIdentity,
  type PartyIdentityErrors,
  type PartyIdentityValues,
} from './party-identity';

const DRAFT_NAME = 'party';

interface UnfinishedParty {
  identity: PartyIdentityValues;
  fields: CustomFieldValues;
  showOptions: boolean;
}

const isPristine = (draft: UnfinishedParty) => JSON.stringify(draft.identity) === JSON.stringify(EMPTY_PARTY_IDENTITY) && Object.keys(draft.fields).length === 0;

export const hasUnfinishedParty = () => readDraft<UnfinishedParty>(DRAFT_NAME)?.value != null;

const toIdentity = (party: PartyResource): PartyIdentityValues => ({
  code: party.code,
  name: party.name,
  kind: party.kind === 'INDIVIDUAL' ? 'INDIVIDUAL' : 'ORGANISATION',
  taxId: party.tax_id ?? '',
  registration: party.registration_no ?? '',
  website: party.website ?? '',
});

export interface PartyFormScreenProps {
  presentation?: Presentation;
}

/** Register (`/parties/new`) or edit (`/parties/:id/edit`) a trading-partner identity. Needs `parties.party.manage`; the API enforces it too. */
export function PartyFormScreen({ presentation = 'sheet' }: PartyFormScreenProps) {
  const { id } = useParams<{ id: string }>();
  const partyId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = usePartiesShow(partyId, undefined, { query: { enabled: Number.isFinite(partyId) && partyId > 0 } });
  const party = query.data?.status === 200 ? query.data.data.data : undefined;
  const close = () => navigate({ pathname: '/parties', search });

  if (id && !party) {
    const body = query.isLoading ? (
      <Skeleton className="m-4 h-48 w-[calc(100%-2rem)]" />
    ) : (
      <div className="space-y-3 p-4" role="alert">
        <p className="text-sm text-destructive">{query.isError && isApiError(query.error) && query.error.status >= 500 ? "Couldn't load this trading partner." : "This trading partner isn't available."}</p>
        <Button variant="ghost" className="h-11 md:h-9" onClick={close}>
          Back to trading partners
        </Button>
      </div>
    );

    return presentation === 'sheet' ? (
      <RecordSheet title={query.isLoading ? 'Loading…' : 'Trading partner'} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={close}>
        {body}
      </RecordSheet>
    ) : (
      <ScreenTemplate kind="form" className="erp-screen">
        <PageHeader title="Trading partner" />
        {body}
      </ScreenTemplate>
    );
  }

  return <PartyEditor key={id ?? 'new'} party={party} presentation={presentation} />;
}

function PartyEditor({ party, presentation }: { party?: PartyResource; presentation: Presentation }) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();
  const isCreate = !party;
  const [unfinished] = useState(() => (isCreate ? (readDraft<UnfinishedParty>(DRAFT_NAME)?.value ?? null) : null));
  const [resumed, setResumed] = useState(unfinished !== null);
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);
  const [identity, setIdentity] = useState<PartyIdentityValues>(party ? toIdentity(party) : (unfinished?.identity ?? EMPTY_PARTY_IDENTITY));
  const [fields, setFields] = useState<CustomFieldValues>(party ? customFieldValues(party.custom_fields) : (unfinished?.fields ?? {}));
  const [showOptions, setShowOptions] = useState(party ? Object.keys(customFieldValues(party.custom_fields)).length > 0 : (unfinished?.showOptions ?? false));
  const [errors, setErrors] = useState<PartyIdentityErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const store = usePartiesStore();
  const update = usePartiesUpdate();
  const busy = store.isPending || update.isPending;

  useEffect(() => {
    if (!isCreate) return;
    const draft: UnfinishedParty = { identity, fields, showOptions };
    if (isPristine(draft)) clearDraft(DRAFT_NAME);
    else writeDraft(DRAFT_NAME, draft);
  }, [isCreate, identity, fields, showOptions]);

  const leave = (to: string) => navigate({ pathname: to, search });

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    const problems = validatePartyIdentity(identity);
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }

    try {
      const result = party
        ? await update.mutateAsync({ party: party.id, data: partyUpdatePayload(identity, fields) })
        : await store.mutateAsync({ data: partyCreatePayload(identity, fields) });
      if (result.status === 200 || result.status === 201) {
        clearDraft(DRAFT_NAME);
        await queryClient.invalidateQueries();
        leave(`/parties/${result.data.data.id}`);
      }
    } catch (failure) {
      if (isApiError(failure) && failure.body?.errors) {
        const server = failure.body.errors;
        const first = (key: string) => server[key]?.[0];
        setErrors({ code: first('code'), name: first('name'), kind: first('kind'), tax_id: first('tax_id'), registration_no: first('registration_no'), website: first('website') });
        const known = ['code', 'name', 'kind', 'tax_id', 'registration_no', 'website'];
        const rest = Object.entries(server).filter(([key]) => !known.includes(key)).map(([, messages]) => messages[0]);
        setFormError(rest.length > 0 ? rest.join(' ') : null);
      } else {
        setFormError(getApiErrorMessage(failure) ?? 'The trading partner could not be saved.');
      }
    }
  }

  const formBody = (
    <form ref={formRef} onSubmit={(event) => void submit(event)} className="space-y-6" noValidate aria-label={isCreate ? 'New trading partner' : 'Edit trading partner'}>
      {isCreate && resumed && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-primary-soft-foreground">
          <div className="flex min-w-0 items-start gap-3">
            <History aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <p className="min-w-0 text-sm font-medium">{confirmingDiscard ? "Discard everything you've entered?" : 'Continuing your unfinished trading partner'}</p>
          </div>
          {confirmingDiscard ? (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                size="sm"
                variant="destructive"
                className="h-11 md:h-8"
                onClick={() => {
                  setIdentity(EMPTY_PARTY_IDENTITY);
                  setFields({});
                  setShowOptions(false);
                  setResumed(false);
                  setConfirmingDiscard(false);
                  clearDraft(DRAFT_NAME);
                }}
              >
                <RotateCcw className="mr-1 h-3.5 w-3.5" />
                Yes, start over
              </Button>
              <Button type="button" size="sm" variant="outline" className="h-11 md:h-8" autoFocus onClick={() => setConfirmingDiscard(false)}>
                Keep it
              </Button>
            </div>
          ) : (
            <Button type="button" size="sm" variant="outline" className="h-11 md:h-8" onClick={() => setConfirmingDiscard(true)}>
              <RotateCcw className="mr-1 h-3.5 w-3.5" />
              Start over
            </Button>
          )}
        </div>
      )}

      <SectionStack>
        <DetailSection title="Identity" description="True of the business whoever it trades as: name, identifiers and website.">
          <PartyIdentityFields idPrefix="party" values={identity} onChange={setIdentity} errors={errors} codeHint={isCreate ? 'Leave blank to generate one from the name.' : undefined} />
          {isCreate && <PartyCandidatePanel name={identity.name} onUse={(chosen) => leave(`/parties/${chosen.id}`)} />}
        </DetailSection>

        <div className="space-y-3">
          <MoreOptionsToggle open={showOptions} onOpenChange={setShowOptions} controls="party-more-options" summary={Object.keys(fields).length > 0 ? `${Object.keys(fields).length} custom ${Object.keys(fields).length === 1 ? 'field' : 'fields'}` : undefined} />
          <Collapse open={showOptions} id="party-more-options">
            <CustomFieldInputs entity="parties" value={fields} onChange={setFields} />
          </Collapse>
        </div>
      </SectionStack>

      {formError && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="party-form-error">
          {formError}
        </p>
      )}

      <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
        <Button type="button" variant="outline" className="h-11 md:h-9" disabled={busy} onClick={() => leave(party ? `/parties/${party.id}` : '/parties')}>
          Cancel
        </Button>
        <Button type="submit" className="h-11 md:h-9" disabled={busy}>
          {busy ? 'Saving…' : isCreate ? 'Register trading partner' : 'Save changes'}
        </Button>
      </div>
    </form>
  );

  const title = isCreate ? 'New trading partner' : `Edit ${party.name}`;
  const description = isCreate ? 'Check it is not already here before registering.' : `Partner ${party.code}`;

  return presentation === 'sheet' ? (
    <RecordSheet title={title} description={description} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={() => leave(party ? `/parties/${party.id}` : '/parties')}>
      <div className="px-4 pb-6 pt-4">{formBody}</div>
    </RecordSheet>
  ) : (
    <ScreenTemplate kind="form" className="erp-screen">
      <PageHeader title={title} description={description} backTo={party ? `/parties/${party.id}${search}` : `/parties${search}`} />
      {formBody}
    </ScreenTemplate>
  );
}
