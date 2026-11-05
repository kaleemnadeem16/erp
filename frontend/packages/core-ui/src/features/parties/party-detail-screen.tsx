import { useState, type ReactNode } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { usePartiesDestroy, usePartiesShow } from '@erp/core-api';
import { Archive, ArrowLeft, ExternalLink, Pencil } from 'lucide-react';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Skeleton } from '../../components/ui/skeleton';
import { StatusBadge } from '../../components/ui/status-badge';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, isApiError } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { useAuthStore } from '../../stores/auth-store';
import { ItemCustomFieldDisplay } from '../custom-fields/custom-field-inputs';
import { CustomerStatusBadge } from '../customers/customers-columns';
import { kindLabel, partyRoles } from './parties-columns';
import { CONTACT_READ_PERMISSIONS, PartyContactsPanel } from './party-contacts-panel';

export interface PartyDetailScreenProps {
  presentation?: Presentation;
}

/** A trading partner: identity, the roles it holds (each with its own account) and its contacts. */
export function PartyDetailScreen({ presentation = 'sheet' }: PartyDetailScreenProps) {
  const { id } = useParams<{ id: string }>();
  const partyId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();
  const permissions = useAuthStore((state) => state.user?.permissions);
  const has = (permission: string) => Array.isArray(permissions) && permissions.includes(permission);
  const mayManage = usePermission('parties.party.manage');
  const mayCreateCustomer = has('parties.customer-account.manage') && has('parties.payment-terms.manage') && has('accounting.account-mapping.manage');
  const mayReadContacts = CONTACT_READ_PERMISSIONS.some(has);

  const query = usePartiesShow(partyId, { include: 'customer_account,supplier_account' }, { query: { enabled: Number.isFinite(partyId) && partyId > 0 } });
  const party = query.data?.status === 200 ? query.data.data.data : undefined;
  const destroy = usePartiesDestroy();
  const [confirmArchive, setConfirmArchive] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);
  const { contentRef, print } = useQuickPrint({ documentTitle: party ? `Trading partner ${party.code}` : 'Trading partner' });
  const close = () => navigate({ pathname: '/parties', search });

  async function archive() {
    if (!party) return;
    setArchiveError(null);
    try {
      await destroy.mutateAsync({ party: party.id });
      await queryClient.invalidateQueries();
      setConfirmArchive(false);
      close();
    } catch (failure) {
      setArchiveError(getApiErrorMessage(failure) ?? 'The trading partner could not be archived.');
    }
  }

  function present(content: ReactNode, header?: { title: string; description?: string; actions?: ReactNode }) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={header?.title ?? 'Trading partner'} description={header?.description} actions={header?.actions} size="xl" className="erp-record" onClose={close}>
          <div className="space-y-4 px-4 pb-6 pt-4">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        {header && <PageHeader title={header.title} description={header.description} breadcrumbs={[{ label: 'Trading partners', to: `/parties${search}` }, { label: party?.code ?? 'Detail' }]} actions={header.actions} />}
        {content}
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return present(
      <div className="space-y-4" role="status" aria-label="Loading trading partner">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>,
      { title: 'Trading partner' },
    );
  }

  if (query.isError || !party) {
    const unavailable = !isApiError(query.error) || query.error.status === 404 || query.error.status === 403;

    return present(
      <div className="space-y-3" role="alert">
        <p className="text-sm text-destructive">{unavailable ? "This trading partner isn't available." : "Couldn't load this trading partner."}</p>
        <div className="flex flex-wrap gap-2">
          {!unavailable && (
            <Button variant="outline" className="h-11 md:h-9" onClick={() => void query.refetch()}>
              Try again
            </Button>
          )}
          <Button variant="ghost" className="h-11 md:h-9" onClick={close}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to trading partners
          </Button>
        </div>
      </div>,
      { title: 'Trading partner' },
    );
  }

  const roles = partyRoles(party);
  // The API archives a partner only when it holds no role at all; say so up front instead of offering an action that is certain to be refused.
  const archiveBlocked = roles.length > 0;

  const printSections: DetailPrintSection[] = [
    {
      title: 'Identity',
      fields: [
        { label: 'Partner code', value: party.code },
        { label: 'Business name', value: party.name },
        { label: 'Kind', value: kindLabel(party.kind) },
        { label: 'Tax identifier', value: party.tax_id ?? '—' },
        { label: 'Registration number', value: party.registration_no ?? '—' },
        { label: 'Website', value: party.website ?? '—' },
        { label: 'Roles', value: roles.join(', ') || 'No role yet' },
      ],
    },
  ];

  const actions = (
    <div className="flex flex-wrap items-center gap-2">
      <PrintTrigger onPrint={() => print()} />
      {mayManage && (
        <>
          <Button
            variant="outline"
            size="sm"
            className="h-11 md:h-8"
            disabled={archiveBlocked}
            title={archiveBlocked ? 'A partner with a customer or supplier account cannot be archived.' : undefined}
            onClick={() => setConfirmArchive(true)}
          >
            <Archive aria-hidden="true" className="mr-1.5 h-4 w-4" />
            Archive
          </Button>
          <Button asChild size="sm" className="h-11 md:h-8">
            <Link to={{ pathname: `/parties/${party.id}/edit`, search }}>
              <Pencil className="mr-1.5 h-4 w-4" />
              Edit
            </Link>
          </Button>
        </>
      )}
    </div>
  );

  return present(
    <>
      <DetailPageLayout>
        <DetailSection title="Identity">
          <DetailField label="Business name">{party.name}</DetailField>
          <DetailField label="Partner code">{party.code}</DetailField>
          <DetailField label="Kind">{kindLabel(party.kind)}</DetailField>
          <DetailField label="Status">
            <StatusBadge tone={party.status === 'ACTIVE' ? 'active' : 'draft'}>{party.status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>
          </DetailField>
          <DetailField label="Tax identifier">{party.tax_id ?? undefined}</DetailField>
          <DetailField label="Registration number">{party.registration_no ?? undefined}</DetailField>
          <DetailField label="Website">
            {party.website ? (
              <a href={party.website} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-1 text-primary hover:underline">
                {party.website}
                <ExternalLink aria-hidden="true" className="h-3 w-3" />
              </a>
            ) : undefined}
          </DetailField>
          {party.custom_fields && Object.keys(party.custom_fields).length > 0 && (
            <DetailField label="Custom fields">
              <ItemCustomFieldDisplay value={party.custom_fields} />
            </DetailField>
          )}
        </DetailSection>

        <DetailSection title="Roles" description="Each role is its own account with its own terms; archiving one never touches the other.">
          <DetailField label="Customer">
            {party.customer_account ? (
              <span className="flex flex-wrap items-center gap-2">
                <Link to={`/customers/${party.customer_account.id}`} className="font-medium text-primary hover:underline">
                  {party.customer_account.code}
                </Link>
                <CustomerStatusBadge status={party.customer_account.status} />
              </span>
            ) : mayCreateCustomer ? (
              <Button asChild variant="outline" size="sm" className="h-11 md:h-9">
                <Link to={`/customers/new?party_id=${party.id}`}>Make a customer</Link>
              </Button>
            ) : (
              <span className="text-muted-foreground">Not a customer</span>
            )}
          </DetailField>
          <DetailField label="Supplier">
            {party.supplier_account ? (
              <span className="flex flex-wrap items-center gap-2">
                <Link to={`/suppliers/${party.supplier_account.id}`} className="font-medium text-primary hover:underline">
                  {party.supplier_account.code}
                </Link>
                <StatusBadge tone={party.supplier_account.status === 'ACTIVE' ? 'active' : 'draft'}>{party.supplier_account.status === 'ACTIVE' ? 'Active' : 'Archived'}</StatusBadge>
              </span>
            ) : (
              <span className="text-muted-foreground">Not a supplier</span>
            )}
          </DetailField>
        </DetailSection>

        {mayReadContacts && (
          <DetailSection title="Contacts" description="People at this trading partner." fullWidth>
            <PartyContactsPanel partyId={party.id} canManage={mayManage} />
          </DetailSection>
        )}
      </DetailPageLayout>

      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title={`TRADING PARTNER: ${party.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>

      <Dialog open={confirmArchive} onOpenChange={(open) => !open && !destroy.isPending && setConfirmArchive(false)}>
        <DialogContent className="max-h-[90dvh] max-w-md overflow-y-auto" data-testid="archive-party-dialog">
          <DialogHeader>
            <DialogTitle>Archive {party.name}?</DialogTitle>
            <DialogDescription>
              The partner leaves the list and can no longer be chosen. It can be brought back later by registering it again: the duplicate check offers to restore it. Its contacts are kept.
            </DialogDescription>
          </DialogHeader>
          {archiveError && (
            <p role="alert" className="text-sm text-destructive">
              {archiveError}
            </p>
          )}
          <DialogFooter className="gap-2 sm:gap-0">
            <Button variant="outline" className="h-11 md:h-9" disabled={destroy.isPending} onClick={() => setConfirmArchive(false)}>
              Cancel
            </Button>
            <Button variant="destructive" className="h-11 md:h-9" disabled={destroy.isPending} onClick={() => void archive()}>
              {destroy.isPending ? 'Archiving…' : 'Archive trading partner'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>,
    { title: `${party.name} (${party.code})`, description: `Trading partner #${party.id}`, actions },
  );
}
