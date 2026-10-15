import { useState } from 'react';
import { usePartiesContactsIndex, usePartiesContactsStore, type PartyContactResource } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';

/** The contact people of one Party, read only by those whose duty involves choosing or maintaining a Party (a refusal means "not for you", and the picker simply steps away). */
export function usePartyContacts(partyId: number | undefined) {
  const query = usePartiesContactsIndex(partyId ?? 0, { per_page: 100 }, { query: { enabled: Boolean(partyId), retry: false } });
  const contacts = query.data?.status === 200 ? query.data.data.data : [];

  return { query, contacts, readable: query.data?.status === 200 };
}

/** One contact of a Party, by id — for showing who a deal or an enquiry names. Undefined when it cannot be read (no permission, or not found). */
export function usePartyContact(partyId: number | undefined, contactId: number | null | undefined) {
  const { contacts, readable, query } = usePartyContacts(contactId ? partyId : undefined);

  return { contact: contacts.find((candidate) => candidate.id === contactId), readable, isLoading: query.isLoading };
}

/** "Sara Khan · Purchasing lead" — the name with the role, when there is one. */
export const contactLabel = (contact: PartyContactResource) => (contact.title ? `${contact.name} · ${contact.title}` : contact.name);

/**
 * The person at the trading partner this deal or enquiry is with. Optional, and offered only once a partner is chosen and only to someone who may read contacts; a new
 * contact can be added without leaving the form (needs Party maintenance). `value` is the contact's id as text, '' for none.
 */
export function ContactPicker({ partyId, value, onValueChange, id = 'contact-picker' }: { partyId: number | undefined; value: string; onValueChange: (value: string) => void; id?: string }) {
  const { query, contacts, readable } = usePartyContacts(partyId);
  const mayAdd = usePermission('parties.party.manage');
  const [adding, setAdding] = useState(false);
  if (!partyId || !readable) return null;

  return (
    <div className="space-y-1.5 sm:col-span-2">
      <Label htmlFor={id}>Contact person (optional)</Label>
      <div className="flex flex-wrap items-center gap-2">
        <div className="min-w-0 flex-1">
          <Combobox
            {...lookupState(query, 'contacts')}
            id={id}
            value={value || 'none'}
            onValueChange={(next) => onValueChange(next === 'none' ? '' : next)}
            options={[{ value: 'none', label: 'No contact person' }, ...contacts.map((contact) => ({ value: String(contact.id), label: contactLabel(contact) }))]}
            placeholder="Choose a contact"
            searchPlaceholder="Search contacts…"
            emptyText="No contacts yet."
          />
        </div>
        {mayAdd && (
          <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)} data-testid="add-contact">
            Add contact…
          </Button>
        )}
      </div>
      <AddContactDialog
        partyId={partyId}
        open={adding}
        onOpenChange={setAdding}
        onAdded={(contact) => {
          void query.refetch();
          onValueChange(String(contact.id));
        }}
      />
    </div>
  );
}

function AddContactDialog({ partyId, open, onOpenChange, onAdded }: { partyId: number; open: boolean; onOpenChange: (open: boolean) => void; onAdded: (contact: PartyContactResource) => void }) {
  const store = usePartiesContactsStore();
  const [form, setForm] = useState({ name: '', title: '', email: '', phone: '' });
  const [error, setError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const set = (field: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [field]: event.target.value });

  async function save() {
    setShowErrors(true);
    setError(null);
    if (form.name.trim() === '') return;
    try {
      const created = await store.mutateAsync({
        party: partyId,
        data: { name: form.name.trim(), title: form.title.trim() || null, email: form.email.trim() || null, phone: form.phone.trim() || null },
      });
      if (created.status === 201) {
        onAdded(created.data.data);
        setForm({ name: '', title: '', email: '', phone: '' });
        setShowErrors(false);
        onOpenChange(false);
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="add-contact-dialog">
        <DialogHeader>
          <DialogTitle>Add a contact person</DialogTitle>
          <DialogDescription>Someone at this trading partner you deal with. It is added to the partner, not only to this record.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="contact-name">Name</Label>
            <Input id="contact-name" value={form.name} onChange={set('name')} aria-invalid={showErrors && form.name.trim() === ''} />
            {showErrors && form.name.trim() === '' && <p className="text-xs text-destructive">Give the contact a name.</p>}
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="contact-title">Role (optional)</Label>
            <Input id="contact-title" value={form.title} onChange={set('title')} placeholder="For example: Purchasing lead" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="contact-email">Email (optional)</Label>
            <Input id="contact-email" type="email" value={form.email} onChange={set('email')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="contact-phone">Phone (optional)</Label>
            <Input id="contact-phone" value={form.phone} onChange={set('phone')} />
          </div>
          {error && (
            <p role="alert" className="text-sm text-destructive sm:col-span-2">
              {error}
            </p>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={() => void save()} disabled={store.isPending} data-testid="contact-save">
            {store.isPending ? 'Adding…' : 'Add contact'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
