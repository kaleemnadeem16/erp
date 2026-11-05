import { useMemo, useState, type ReactNode } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useChartOfAccountsShow, useCurrenciesShow, useCustomerAccountsShow, useCustomerAccountsUpdate } from '@erp/core-api';
import { ArrowLeft, ExternalLink, Pencil, UsersRound } from 'lucide-react';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { getApiErrorMessage, isApiError } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { useAuthStore } from '../../stores/auth-store';
import { ItemCustomFieldDisplay } from '../custom-fields/custom-field-inputs';
import { CONTACT_READ_PERMISSIONS, PartyContactsPanel } from '../parties/party-contacts-panel';
import { CustomerStatusBadge } from './customers-columns';
import { customerAuthority, CUSTOMER_MANAGE_PERMISSIONS } from './customer-rules';

export interface CustomerDetailScreenProps {
  presentation?: Presentation;
}

/** A customer account with its trading-partner identity and contacts. Side sheet over the list by default; the page alternative is kept (presentation.ts). */
export function CustomerDetailScreen({ presentation = 'sheet' }: CustomerDetailScreenProps) {
  const { id } = useParams<{ id: string }>();
  const customerId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();
  const permissions = useAuthStore((state) => state.user?.permissions);
  const has = (permission: string) => Array.isArray(permissions) && permissions.includes(permission);
  const authority = useMemo(() => customerAuthority((permission) => Array.isArray(permissions) && permissions.includes(permission)), [permissions]);
  const mayManageContacts = has(CUSTOMER_MANAGE_PERMISSIONS.party);
  const mayReadContacts = CONTACT_READ_PERMISSIONS.some(has);
  const mayOpenPartners = has(CUSTOMER_MANAGE_PERMISSIONS.party);

  const query = useCustomerAccountsShow(customerId, { include: 'party' }, { query: { enabled: Number.isFinite(customerId) && customerId > 0 } });
  const customer = query.data?.status === 200 ? query.data.data.data : undefined;
  const currencyQuery = useCurrenciesShow(customer?.default_currency_id ?? 0, { query: { enabled: Boolean(customer?.default_currency_id) } });
  const currency = currencyQuery.data?.status === 200 ? currencyQuery.data.data.data : undefined;
  const accountQuery = useChartOfAccountsShow(customer?.receivable_account_id ?? 0, undefined, {
    query: { enabled: Boolean(authority.viewMapping && customer?.receivable_account_id) },
  });
  const account = accountQuery.data?.status === 200 ? accountQuery.data.data.data : undefined;

  const update = useCustomerAccountsUpdate();
  const [actionError, setActionError] = useState<string | null>(null);
  const { contentRef, print } = useQuickPrint({ documentTitle: customer ? `Customer ${customer.code}` : 'Customer' });

  const close = () => navigate({ pathname: '/customers', search });

  async function toggleStatus() {
    if (!customer) return;
    setActionError(null);
    try {
      await update.mutateAsync({ customerAccount: customer.id, data: { status: customer.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' } });
      await queryClient.invalidateQueries();
    } catch (failure) {
      setActionError(getApiErrorMessage(failure) ?? 'The change was refused.');
    }
  }

  function present(content: ReactNode, header?: { title: string; description?: string; actions?: ReactNode }) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={header?.title ?? 'Customer'} description={header?.description} actions={header?.actions} size="xl" className="erp-record" onClose={close}>
          <div className="space-y-4 px-4 pb-6 pt-4">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        {header && (
          <PageHeader
            title={header.title}
            description={header.description}
            breadcrumbs={[{ label: 'Customers', to: `/customers${search}` }, { label: customer?.code ?? 'Detail' }]}
            actions={header.actions}
          />
        )}
        {content}
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return present(
      <div className="space-y-4" role="status" aria-label="Loading customer">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>,
      { title: 'Customer' },
    );
  }

  if (query.isError || !customer) {
    // A missing record and one the person may not see read the same ("isn't available") so the screen never confirms that an id exists.
    const unavailable = !isApiError(query.error) || query.error.status === 404 || query.error.status === 403;

    return present(
      <div className="space-y-3" role="alert">
        <p className="text-sm text-destructive">{unavailable ? "This customer isn't available." : "Couldn't load this customer."}</p>
        <div className="flex flex-wrap gap-2">
          {!unavailable && (
            <Button variant="outline" className="h-11 md:h-9" onClick={() => void query.refetch()}>
              Try again
            </Button>
          )}
          <Button variant="ghost" className="h-11 md:h-9" onClick={close}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to customers
          </Button>
        </div>
      </div>,
      { title: 'Customer' },
    );
  }

  const party = customer.party;
  const currencyText = customer.default_currency_id ? (currency ? `${currency.code} — ${currency.name}` : `Currency #${customer.default_currency_id}`) : 'Company default';
  const accountText = account ? `${account.code} — ${account.name}` : customer.receivable_account_id ? `Account #${customer.receivable_account_id}` : undefined;

  const printSections: DetailPrintSection[] = [
    {
      title: 'Customer account',
      fields: [
        { label: 'Customer code', value: customer.code },
        { label: 'Status', value: customer.status === 'ACTIVE' ? 'Active' : 'Archived' },
        { label: 'Payment terms', value: customer.payment_terms_days != null ? `${customer.payment_terms_days} days` : '—' },
        ...(authority.viewCredit ? [{ label: 'Credit limit', value: customer.credit_limit ?? 'No limit' }] : []),
        { label: 'Default currency', value: currencyText },
        ...(authority.viewMapping ? [{ label: 'Receivable account', value: accountText ?? '—' }] : []),
      ],
    },
    {
      title: 'Trading partner',
      fields: [
        { label: 'Partner code', value: party?.code ?? '—' },
        { label: 'Business name', value: party?.name ?? '—' },
        { label: 'Tax identifier', value: party?.tax_id ?? '—' },
        { label: 'Registration number', value: party?.registration_no ?? '—' },
        { label: 'Website', value: party?.website ?? '—' },
      ],
    },
  ];

  const actions = (
    <div className="flex flex-wrap items-center gap-2">
      <PrintTrigger onPrint={() => print()} />
      {authority.edit && (
        <>
          <Button variant="outline" size="sm" className="h-11 md:h-8" disabled={update.isPending} onClick={() => void toggleStatus()}>
            {customer.status === 'ACTIVE' ? 'Archive' : 'Activate'}
          </Button>
          <Button asChild size="sm" className="h-11 md:h-8">
            <Link to={{ pathname: `/customers/${customer.id}/edit`, search }}>
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
      {actionError && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="customer-action-error">
          {actionError}
        </p>
      )}
      <DetailPageLayout>
        <DetailSection title="Customer account">
          <DetailField label="Customer code">{customer.code}</DetailField>
          <DetailField label="Status">
            <CustomerStatusBadge status={customer.status} />
          </DetailField>
          <DetailField label="Payment terms">
            {customer.payment_terms_days != null ? <span className="tabular-nums">{customer.payment_terms_days} days</span> : undefined}
          </DetailField>
          {authority.viewCredit && (
            <DetailField label="Credit limit">
              {customer.credit_limit != null ? <span className="tabular-nums">{customer.credit_limit}</span> : <span className="text-muted-foreground">No limit</span>}
            </DetailField>
          )}
          <DetailField label="Default currency">{currencyText}</DetailField>
          {authority.viewMapping && <DetailField label="Receivable control account">{accountText}</DetailField>}
          {customer.custom_fields && Object.keys(customer.custom_fields).length > 0 && (
            <DetailField label="Custom fields">
              <ItemCustomFieldDisplay value={customer.custom_fields} />
            </DetailField>
          )}
        </DetailSection>

        <DetailSection title="Trading partner">
          <DetailField label="Business name">{party?.name}</DetailField>
          <DetailField label="Partner code">{party?.code}</DetailField>
          <DetailField label="Kind">{party?.kind === 'INDIVIDUAL' ? 'Individual' : party?.kind === 'ORGANISATION' ? 'Organisation' : undefined}</DetailField>
          <DetailField label="Tax identifier">{party?.tax_id ?? undefined}</DetailField>
          <DetailField label="Registration number">{party?.registration_no ?? undefined}</DetailField>
          <DetailField label="Website">
            {party?.website ? (
              <a href={party.website} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-1 text-primary hover:underline">
                {party.website}
                <ExternalLink aria-hidden="true" className="h-3 w-3" />
              </a>
            ) : undefined}
          </DetailField>
          {mayOpenPartners && party && (
            <div className="sm:col-span-2">
              <Button asChild variant="outline" size="sm" className="h-11 md:h-9">
                <Link to={{ pathname: `/parties/${party.id}`, search: '' }}>
                  <UsersRound aria-hidden="true" className="mr-1.5 h-4 w-4" />
                  Open trading partner
                </Link>
              </Button>
            </div>
          )}
        </DetailSection>

        {mayReadContacts && party && (
          <DetailSection title="Contacts" description="People at this trading partner." fullWidth>
            <PartyContactsPanel partyId={party.id} canManage={mayManageContacts} />
          </DetailSection>
        )}
      </DetailPageLayout>

      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title={`CUSTOMER: ${customer.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </>,
    {
      title: party?.name ? `${party.name} (${customer.code})` : customer.code,
      description: `Customer account #${customer.id}`,
      actions,
    },
  );
}
