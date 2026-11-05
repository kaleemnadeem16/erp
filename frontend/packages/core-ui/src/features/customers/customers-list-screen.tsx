import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useCurrenciesIndex,
  useCustomerAccountsIndex,
  useCustomerAccountsUpdate,
  usePartiesShow,
  type CustomerAccountResource,
  type CustomerAccountsIndexSort,
} from '@erp/core-api';
import { Archive, Building2, EllipsisVertical, Pencil, RefreshCw } from 'lucide-react';

import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import {
  ActiveFilterChips,
  AdvancedFiltersPanel,
  AdvancedFiltersToggle,
  BulkActionBar,
  DataTable,
  FilterField,
  ListFiltersSheet,
  ListSearchInput,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { BulkRunDialog } from '../../components/bulk-run-dialog';
import { AccountCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { useAuthStore } from '../../stores/auth-store';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useAccountsById } from '../../lib/use-records-by-id';
import { useListViewPreference } from '../../lib/use-view-preference';
import { getCustomerColumns } from './customers-columns';
import { customerAuthority, customerStatusLabel } from './customer-rules';

interface CustomerFilters {
  search: string;
  status: string;
  party_id: string;
  default_currency_id: string;
  receivable_account_id: string;
  [key: string]: string;
}

const STATUS_OPTIONS = [
  { value: 'ACTIVE', label: 'Active' },
  { value: 'ARCHIVED', label: 'Archived' },
] as const;

export function CustomersListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const queryClient = useQueryClient();

  const permissions = useAuthStore((state) => state.user?.permissions);
  const authority = useMemo(() => customerAuthority((permission) => Array.isArray(permissions) && permissions.includes(permission)), [permissions]);
  const { create: canCreate, edit: mayManage, viewMapping: mayViewMapping, viewCredit: mayViewCredit } = authority;

  const query = useListQueryState<CustomerFilters>({ defaultSort: 'code', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const { view, setView } = useListViewPreference('customers-list');

  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyLabels = useMemo(() => new Map(currencies.map((currency) => [currency.id, `${currency.code} — ${currency.name}`])), [currencies]);

  // The chosen partner / account are named for their chips by their own id, not by whatever first page a picker happened to load.
  const partyId = Number(query.filters.party_id) || 0;
  const partyQuery = usePartiesShow(partyId, undefined, { query: { enabled: partyId > 0 } });
  const partyName = partyQuery.data?.status === 200 ? partyQuery.data.data.data.name : undefined;
  const accountId = Number(query.filters.receivable_account_id) || 0;


  const [advancedOpen, setAdvancedOpen] = useState(() => Boolean(query.filters.party_id || query.filters.default_currency_id || query.filters.receivable_account_id));

  const customersQuery = useCustomerAccountsIndex({
    include: 'party',
    'filter[search]': query.filters.search || undefined,
    'filter[status]': (query.filters.status as 'ACTIVE' | 'ARCHIVED' | undefined) || undefined,
    'filter[party_id]': partyId > 0 ? partyId : undefined,
    'filter[default_currency_id]': query.filters.default_currency_id ? Number(query.filters.default_currency_id) : undefined,
    // Protected mapping: the server ignores this filter for someone who may not read it, so the screen does not offer it either.
    'filter[receivable_account_id]': mayViewMapping && accountId > 0 ? accountId : undefined,
    sort: query.sort as CustomerAccountsIndexSort | undefined,
    page: query.page,
    per_page: query.perPage,
  });
  const customers = customersQuery.data?.status === 200 ? customersQuery.data.data.data : [];
  const meta = customersQuery.data?.status === 200 ? customersQuery.data.data.meta : undefined;

  // Receivable accounts are named by their own ids (a chart can be larger than one page), for the rows shown and for the filtered account.
  const accountIds = useMemo(
    () => (mayViewMapping ? [...customers.map((customer) => customer.receivable_account_id).filter((id): id is number => Boolean(id)), ...(accountId > 0 ? [accountId] : [])] : []),
    [customers, accountId, mayViewMapping],
  );
  const accounts = useAccountsById<{ id: number; code: string; name: string }>(accountIds, [], mayViewMapping);
  const accountLabels = useMemo(() => new Map(accounts.map((account) => [account.id, `${account.code} — ${account.name}`])), [accounts]);
  const accountLabel = accountLabels.get(accountId);

  const columns = useMemo(
    () => getCustomerColumns({ currencyLabels, accountLabels, mayViewCredit, mayViewMapping }),
    [currencyLabels, accountLabels, mayViewCredit, mayViewMapping],
  );
  // Defaults come from the full column set, not the permitted one: permissions can still be loading on the first render, and a column absent from the
  // defaults would otherwise appear switched on once it exists.
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'customers-list',
    Object.fromEntries(getCustomerColumns({ mayViewCredit: true, mayViewMapping: true }).map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );

  const update = useCustomerAccountsUpdate();
  const [rowNotice, setRowNotice] = useState<string | null>(null);

  const tanStackColumns = useMemo(() => [selectionColumn<CustomerAccountResource>(), ...toTanStackColumns(columns)], [columns]);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => customers.filter((customer) => rowSelection[String(customer.id)]), [customers, rowSelection]);
  const [bulk, setBulk] = useState<{ target: 'ACTIVE' | 'ARCHIVED'; rows: CustomerAccountResource[] } | null>(null);

  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Customer Directory' });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({ documentTitle: 'Customer Directory (Selected)' });

  const advancedCount = [query.filters.party_id, query.filters.default_currency_id, mayViewMapping && query.filters.receivable_account_id].filter(Boolean).length;
  const filterCount = advancedCount + (query.filters.status ? 1 : 0);

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${customerStatusLabel(query.filters.status)}`, onRemove: () => query.setFilter('status', undefined) });
  if (partyId > 0) chips.push({ id: 'party', label: `Partner: ${partyName ?? `#${partyId}`}`, onRemove: () => query.setFilter('party_id', undefined) });
  if (query.filters.default_currency_id) {
    const id = Number(query.filters.default_currency_id);
    chips.push({ id: 'currency', label: `Currency: ${currencyLabels.get(id) ?? `#${id}`}`, onRemove: () => query.setFilter('default_currency_id', undefined) });
  }
  if (mayViewMapping && accountId > 0) {
    chips.push({ id: 'account', label: `Receivable account: ${accountLabel ?? `#${accountId}`}`, onRemove: () => query.setFilter('receivable_account_id', undefined) });
  }

  const clearAdvanced = () => query.setFilters({ party_id: undefined, default_currency_id: undefined, receivable_account_id: undefined });
  const clearAll = () => query.setFilters({ search: undefined, status: undefined, party_id: undefined, default_currency_id: undefined, receivable_account_id: undefined });

  async function toggleStatus(customer: CustomerAccountResource) {
    setRowNotice(null);
    try {
      await update.mutateAsync({ customerAccount: customer.id, data: { status: customer.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' } });
      await queryClient.invalidateQueries();
    } catch (failure) {
      setRowNotice(`${customer.code}: ${getApiErrorMessage(failure) ?? 'The change was refused.'}`);
    }
  }

  const filterControls = (id: string) => (
    <>
      <FilterField label="Trading partner" htmlFor={`${id}-party`}>
        <PartyCombobox
          id={`${id}-party`}
          label="Trading partner"
          allLabel="All trading partners"
          placeholder="All trading partners"
          className="h-11 w-full md:h-10"
          value={query.filters.party_id ?? 'all'}
          onValueChange={(value) => query.setFilter('party_id', value === 'all' ? undefined : value)}
        />
      </FilterField>
      <FilterField label="Default currency" htmlFor={`${id}-currency`}>
        <Combobox
          {...lookupState(currenciesQuery, 'currencies')}
          id={`${id}-currency`}
          className="h-11 w-full md:h-10"
          aria-label="Default currency"
          value={query.filters.default_currency_id ?? 'all'}
          onValueChange={(value) => query.setFilter('default_currency_id', value === 'all' ? undefined : value)}
          searchPlaceholder="Search currencies…"
          emptyText="No currencies found."
          options={[{ value: 'all', label: 'All currencies' }, ...currencies.map((currency) => ({ value: String(currency.id), label: `${currency.code} — ${currency.name}` }))]}
        />
      </FilterField>
      {mayViewMapping && (
        <FilterField label="Receivable account" htmlFor={`${id}-account`}>
          <AccountCombobox
            id={`${id}-account`}
            label="Receivable account"
            allLabel="All accounts"
            placeholder="All accounts"
            className="h-11 w-full md:h-10"
            value={query.filters.receivable_account_id ?? 'all'}
            onValueChange={(value) => query.setFilter('receivable_account_id', value === 'all' ? undefined : value)}
          />
        </FilterField>
      )}
    </>
  );

  const statusSelect = (className: string, ariaLabel = 'Status') => (
    <Select value={query.filters.status || 'ALL'} onValueChange={(value) => query.setFilter('status', value === 'ALL' ? undefined : value)}>
      <SelectTrigger className={className} aria-label={ariaLabel}>
        <SelectValue placeholder="All statuses" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="ALL">All statuses</SelectItem>
        {STATUS_OPTIONS.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );

  const archivable = selected.filter((customer) => customer.status === 'ACTIVE');
  const reactivatable = selected.filter((customer) => customer.status !== 'ACTIVE');

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Customers"
        description="Trading partners you sell to: their account terms, credit and receivable mapping."
        primaryAction={canCreate ? { label: 'New customer', onClick: () => navigate({ pathname: '/customers/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput
          input={searchBox.input}
          searchProps={searchBox.searchProps}
          onClear={() => query.setFilter('search', undefined)}
          placeholder="Search code, name, tax ID…"
          ariaLabel="Search customers"
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {statusSelect('h-10 w-36')}
          <AdvancedFiltersToggle open={advancedOpen} activeCount={advancedCount} onToggle={() => setAdvancedOpen((open) => !open)} controlsId="customers-advanced-filters" />
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Status">{statusSelect('h-11 w-full', 'Status (filters sheet)')}</FilterField>
          {filterControls('sheet')}
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <AdvancedFiltersPanel id="customers-advanced-filters" open={advancedOpen} onClose={() => setAdvancedOpen(false)} activeCount={advancedCount} onReset={clearAdvanced}>
        {filterControls('panel')}
      </AdvancedFiltersPanel>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      {rowNotice && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="customers-row-notice">
          {rowNotice}
        </p>
      )}

      <BulkActionBar count={selected.length} noun="customer" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
        {mayManage && (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 md:h-8"
              disabled={archivable.length === 0}
              title={archivable.length === 0 ? 'None of the selected customers is active.' : undefined}
              onClick={() => setBulk({ target: 'ARCHIVED', rows: archivable })}
            >
              <Archive aria-hidden="true" className="mr-1.5 h-3.5 w-3.5" />
              Archive selected
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 md:h-8"
              disabled={reactivatable.length === 0}
              title={reactivatable.length === 0 ? 'None of the selected customers is archived.' : undefined}
              onClick={() => setBulk({ target: 'ACTIVE', rows: reactivatable })}
            >
              <RefreshCw aria-hidden="true" className="mr-1.5 h-3.5 w-3.5" />
              Activate selected
            </Button>
          </>
        )}
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={customers}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, columns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(customer: CustomerAccountResource) => navigate({ pathname: `/customers/${customer.id}`, search: listSearch })}
        isLoading={customersQuery.isLoading}
        isError={customersQuery.isError}
        error={customersQuery.error}
        onRetry={() => void customersQuery.refetch()}
        errorMessage="Couldn't load customers."
        emptyMessage={chips.length > 0 ? 'No customers match these filters.' : 'No customers yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={columns}
        recordNoun="customers"
        actionsHeader="Actions"
        numberedPagination
        rowActions={(customer: CustomerAccountResource) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="h-11 w-11 md:h-8 md:w-8"
                aria-label={`Actions for ${customer.code}`}
                onClick={(event) => event.stopPropagation()}
              >
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem
                onClick={(event) => {
                  event.stopPropagation();
                  navigate({ pathname: `/customers/${customer.id}`, search: listSearch });
                }}
              >
                <Building2 className="mr-2 h-4 w-4 text-muted-foreground" />
                View details
              </DropdownMenuItem>
              {mayManage && (
                <>
                  <DropdownMenuItem
                    onClick={(event) => {
                      event.stopPropagation();
                      navigate({ pathname: `/customers/${customer.id}/edit`, search: listSearch });
                    }}
                  >
                    <Pencil className="mr-2 h-4 w-4 text-muted-foreground" />
                    Edit customer
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={(event) => {
                      event.stopPropagation();
                      void toggleStatus(customer);
                    }}
                  >
                    {customer.status === 'ACTIVE' ? (
                      <>
                        <Archive className="mr-2 h-4 w-4 text-muted-foreground" />
                        Archive customer
                      </>
                    ) : (
                      <>
                        <RefreshCw className="mr-2 h-4 w-4 text-muted-foreground" />
                        Activate customer
                      </>
                    )}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      />

      {bulk && (
        <BulkRunDialog
          key={`${bulk.target}-${bulk.rows.map((row) => row.id).join(',')}`}
          open
          title={bulk.target === 'ARCHIVED' ? 'Archive customers' : 'Activate customers'}
          intro={
            bulk.target === 'ARCHIVED'
              ? `Archive ${bulk.rows.length} ${bulk.rows.length === 1 ? 'customer' : 'customers'}? Archived customers stay on record and can be activated again; they are no longer offered for new work.`
              : `Activate ${bulk.rows.length} archived ${bulk.rows.length === 1 ? 'customer' : 'customers'}?`
          }
          rows={bulk.rows}
          ready
          run={(row) => update.mutateAsync({ customerAccount: row.id, data: { status: bulk.target } })}
          labelOf={(row) => `${row.code}${row.party?.name ? ` (${row.party.name})` : ''}`}
          onClose={() => setBulk(null)}
          onChanged={async () => {
            await queryClient.invalidateQueries();
            setRowSelection({});
          }}
        />
      )}

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="CUSTOMER DIRECTORY"
            printedAt={new Date()}
            recordCount={customers.length}
            meta={[
              ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, columns) ?? query.sort }] : []),
              ...chips.map((chip) => ({ label: 'Filter', value: chip.label })),
            ]}
          >
            <PrintableTable columns={columns} rows={customers} getRowKey={(customer) => customer.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedPrintRef}>
          <PrintLayout
            title="CUSTOMER DIRECTORY (SELECTED)"
            printedAt={new Date()}
            recordCount={selected.length}
            meta={[{ label: 'Selection', value: `${selected.length} selected customers` }]}
          >
            <PrintableTable columns={columns} rows={selected} getRowKey={(customer) => customer.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
