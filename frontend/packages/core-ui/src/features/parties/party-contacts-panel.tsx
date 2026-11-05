import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Mail, Pencil, Phone, Plus, UserRound } from 'lucide-react';
import {
  getPartiesContactsIndexQueryKey,
  usePartiesContactsIndex,
  usePartiesContactsStore,
  usePartyContactsUpdate,
  type PartyContactResource,
} from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Skeleton } from '../../components/ui/skeleton';
import { describeLoadError, getApiErrorMessage, isApiError } from '../../lib/api-errors';

/** Capabilities that let a person read a Party's contacts (the server's `PartyCandidateAccess::mayAsk`: Party maintenance, or a Sales duty that registers itself there). */
export const CONTACT_READ_PERMISSIONS = ['parties.party.manage', 'sales.lead.manage', 'sales.opportunity.manage'] as const;

interface ContactDraft {
  name: string;
  title: string;
  email: string;
  phone: string;
  isPrimary: boolean;
}

const EMPTY: ContactDraft = { name: '', title: '', email: '', phone: '', isPrimary: false };

function toDraft(contact: PartyContactResource | null): ContactDraft {
  if (!contact) return EMPTY;

  return {
    name: contact.name,
    title: contact.title ?? '',
    email: contact.email ?? '',
    phone: contact.phone ?? '',
    isPrimary: contact.is_primary,
  };
}

/** Field-level messages from a 422, keyed by the request field. */
function fieldErrors(failure: unknown): Record<string, string> {
  if (!isApiError(failure) || !failure.body?.errors) return {};

  return Object.fromEntries(Object.entries(failure.body.errors).map(([field, messages]) => [field, messages[0] ?? '']));
}

/**
 * The people at a trading partner: list, add and edit. Removing a contact is deliberately not offered (parties.md §Contacts: a deal or an enquiry may point at
 * one, and the archive rule is undecided). `canManage` follows `parties.party.manage`; the server still refuses without it. One primary contact at most — making
 * another primary demotes the previous one on the server, and the list is refreshed from there rather than guessed.
 */
export function PartyContactsPanel({ partyId, canManage }: { partyId: number; canManage: boolean }) {
  const queryClient = useQueryClient();
  const contactsQuery = usePartiesContactsIndex(partyId, { per_page: 100 }, { query: { retry: false } });
  const contacts = contactsQuery.data?.status === 200 ? contactsQuery.data.data.data : [];
  const [editing, setEditing] = useState<PartyContactResource | 'new' | null>(null);

  async function refresh() {
    await queryClient.invalidateQueries({ queryKey: getPartiesContactsIndexQueryKey(partyId, { per_page: 100 }) });
    await contactsQuery.refetch();
  }

  return (
    <div className="space-y-3 sm:col-span-2" data-testid="party-contacts">
      {contactsQuery.isLoading ? (
        <div className="space-y-2" role="status" aria-label="Loading contacts">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      ) : contactsQuery.isError ? (
        <div role="alert" className="space-y-2 text-sm">
          <p className="font-medium text-destructive">Couldn't load the contacts.</p>
          <p className="text-muted-foreground">{describeLoadError(contactsQuery.error)}</p>
          {isApiError(contactsQuery.error) && contactsQuery.error.status === 403 ? null : (
            <Button type="button" variant="outline" size="sm" className="h-9" onClick={() => void contactsQuery.refetch()}>
              Try again
            </Button>
          )}
        </div>
      ) : contacts.length === 0 ? (
        <p className="text-sm text-muted-foreground" data-testid="party-contacts-empty">
          No contacts recorded yet.
        </p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {contacts.map((contact) => (
            <li key={contact.id} className="rounded-lg border bg-card p-3 text-sm text-card-foreground shadow-xs" data-testid="party-contact">
              <div className="flex items-start justify-between gap-2">
                <span className="flex min-w-0 items-center gap-1.5 font-medium">
                  <UserRound aria-hidden="true" className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 break-words">{contact.name}</span>
                </span>
                <div className="flex shrink-0 items-center gap-1">
                  {contact.is_primary && <Badge variant="secondary">Primary</Badge>}
                  {canManage && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-11 w-11 md:h-8 md:w-8"
                      aria-label={`Edit contact ${contact.name}`}
                      onClick={() => setEditing(contact)}
                    >
                      <Pencil className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              </div>
              {contact.title && <p className="mt-0.5 text-xs text-muted-foreground">{contact.title}</p>}
              <div className="mt-1.5 space-y-0.5 text-xs">
                {contact.email && (
                  <a href={`mailto:${contact.email}`} className="flex items-center gap-1.5 break-all text-primary hover:underline">
                    <Mail aria-hidden="true" className="h-3 w-3 shrink-0" />
                    {contact.email}
                  </a>
                )}
                {contact.phone && (
                  <a href={`tel:${contact.phone}`} className="flex items-center gap-1.5 text-primary hover:underline">
                    <Phone aria-hidden="true" className="h-3 w-3 shrink-0" />
                    {contact.phone}
                  </a>
                )}
                {!contact.email && !contact.phone && <p className="text-muted-foreground">No email or phone recorded.</p>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {canManage && (
        <Button type="button" variant="outline" size="sm" className="h-11 md:h-9" onClick={() => setEditing('new')}>
          <Plus aria-hidden="true" className="mr-1.5 h-3.5 w-3.5" />
          Add contact
        </Button>
      )}
      {editing !== null && (
        <ContactDialog
          key={editing === 'new' ? 'new' : editing.id}
          partyId={partyId}
          contact={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await refresh();
          }}
        />
      )}
    </div>
  );
}

function ContactDialog({
  partyId,
  contact,
  onClose,
  onSaved,
}: {
  partyId: number;
  contact: PartyContactResource | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [draft, setDraft] = useState<ContactDraft>(() => toDraft(contact));
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const store = usePartiesContactsStore();
  const update = usePartyContactsUpdate();
  const pending = store.isPending || update.isPending;
  const set = <K extends keyof ContactDraft>(key: K, value: ContactDraft[K]) => setDraft((current) => ({ ...current, [key]: value }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setErrors({});
    if (!draft.name.trim()) {
      setErrors({ name: 'Enter the contact’s name.' });
      return;
    }
    const data = {
      name: draft.name.trim(),
      title: draft.title.trim() || null,
      email: draft.email.trim() || null,
      phone: draft.phone.trim() || null,
      is_primary: draft.isPrimary,
    };
    try {
      if (contact) await update.mutateAsync({ partyContact: contact.id, data });
      else await store.mutateAsync({ party: partyId, data });
      await onSaved();
    } catch (failure) {
      const fields = fieldErrors(failure);
      setErrors(fields);
      setError(Object.keys(fields).length > 0 ? null : (getApiErrorMessage(failure) ?? 'The contact could not be saved.'));
    }
  }

  const field = (id: string, label: string, key: keyof ContactDraft, extra: { type?: string; autoComplete?: string; required?: boolean } = {}) => (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        className="h-11 md:h-10"
        type={extra.type ?? 'text'}
        autoComplete={extra.autoComplete}
        required={extra.required}
        value={draft[key] as string}
        aria-invalid={errors[key === 'isPrimary' ? 'is_primary' : key] ? true : undefined}
        aria-describedby={errors[key] ? `${id}-error` : undefined}
        onChange={(event) => set(key, event.target.value as never)}
      />
      {errors[key] && (
        <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
          {errors[key]}
        </p>
      )}
    </div>
  );

  return (
    <Dialog open onOpenChange={(open) => !open && !pending && onClose()}>
      <DialogContent className="max-h-[90dvh] max-w-md overflow-y-auto" data-testid="contact-dialog">
        <form onSubmit={(event) => void submit(event)} className="space-y-4" noValidate>
          <DialogHeader>
            <DialogTitle>{contact ? 'Edit contact' : 'Add contact'}</DialogTitle>
            <DialogDescription>A person at this trading partner. Only one contact can be primary; choosing another replaces it.</DialogDescription>
          </DialogHeader>
          {field('contact-name', 'Name', 'name', { required: true, autoComplete: 'off' })}
          {field('contact-title', 'Job title', 'title', { autoComplete: 'off' })}
          {field('contact-email', 'Email', 'email', { type: 'email', autoComplete: 'off' })}
          {field('contact-phone', 'Phone', 'phone', { type: 'tel', autoComplete: 'off' })}
          <div className="flex min-h-11 items-center gap-2">
            <Checkbox id="contact-primary" checked={draft.isPrimary} onCheckedChange={(checked) => set('isPrimary', checked === true)} />
            <Label htmlFor="contact-primary" className="font-normal">
              Primary contact
            </Label>
          </div>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" className="h-11 md:h-9" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" className="h-11 md:h-9" disabled={pending}>
              {pending ? 'Saving…' : contact ? 'Save contact' : 'Add contact'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
