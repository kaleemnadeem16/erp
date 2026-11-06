import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useChartOfAccountsIndex,
  useChartOfAccountsShow,
  useChartOfAccountsUpdate,
  type ChartOfAccountResource,
  type ChartOfAccountsIndexSort,
} from '@erp/core-api';
import { Archive, EllipsisVertical, Pencil, RefreshCw } from 'lucide-react';

import { BulkRunDialog } from '../../components/bulk-run-dialog';
import { AccountCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import {
  ActiveFilterChips,
  AdvancedFiltersPanel,
  AdvancedFiltersToggle,
  BulkActionBar,
  DataTable,
  FilterField,
  FilterSelect,
  ListFiltersSheet,
  ListSearchInput,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { accountColumns } from './account-columns';
import { ACCOUNT_TYPES, accountLabel, accountTypeLabel } from './account-rules';

interface AccountFilters {
  search: string;
  type: string;
  status: string;
  parent_id: string;
  [key: string]: string;
}

const MANAGE = 'accounting.chart-of-account.manage';
const STATUSES = [
  { value: 'ACTIVE', label: 'Active' },
  { value: 'ARCHIVED', label: 'Archived' },
];
const LEVELS = [{ value: '0', label: 'Top level only' }];

/**
 * The chart of accounts. Bulk: Archive / Activate selected (reversible, one request per account through `BulkRunDialog`, partial failures listed with the
 * server's reason) and Print selected. Deleting an account is not offered: postings may reference it, so it is archived instead.
 */
export function ChartOfAccountsListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const queryClient = useQueryClient();
  const mayManage = usePermission(MANAGE);
  const query = useListQueryState<AccountFilters>({ defaultSort: 'code', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('chart-of-accounts-list', Object.fromEntries(accountColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('chart-of-accounts-list');
  const [advancedOpen, setAdvancedOpen] = useState(() => Boolean(query.filters.parent_id));

  const parentId = query.filters.parent_id && query.filters.parent_id !== '0' ? Number(query.filters.parent_id) : 0;
  const parentQuery = useChartOfAccountsShow(parentId, undefined, { query: { enabled: parentId > 0 } });
  const parentName = parentQuery.data?.status === 200 ? accountLabel(parentQuery.data.data.data) : undefined;

  const result = useChartOfAccountsIndex({
    include: 'parent',
    'filter[search]': query.filters.search || undefined,
    'filter[type]': (query.filters.type as (typeof ACCOUNT_TYPES)[number] | undefined) || undefined,
    'filter[status]': (query.filters.status as 'ACTIVE' | 'ARCHIVED' | undefined) || undefined,
    'filter[parent_id]': query.filters.parent_id !== undefined && query.filters.parent_id !== '' ? Number(query.filters.parent_id) : undefined,
    sort: query.sort as ChartOfAccountsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const accounts = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const update = useChartOfAccountsUpdate();
  const [rowNotice, setRowNotice] = useState<string | null>(null);
  const tanStackColumns = useMemo(() => [selectionColumn<ChartOfAccountResource>(), ...toTanStackColumns(accountColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => accounts.filter((account) => rowSelection[String(account.id)]), [accounts, rowSelection]);
  const [bulk, setBulk] = useState<{ target: 'ACTIVE' | 'ARCHIVED'; rows: ChartOfAccountResource[] } | null>(null);
  const sorting: SortingState = apiSortToTableSorting(query.sort, accountColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Chart of Accounts' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Chart of Accounts (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.type) chips.push({ id: 'type', label: `Type: ${accountTypeLabel(query.filters.type)}`, onRemove: () => query.setFilter('type', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${query.filters.status === 'ACTIVE' ? 'Active' : 'Archived'}`, onRemove: () => query.setFilter('status', undefined) });
  if (query.filters.parent_id === '0') chips.push({ id: 'parent', label: 'Level: Top level only', onRemove: () => query.setFilter('parent_id', undefined) });
  else if (parentId > 0) chips.push({ id: 'parent', label: `Parent: ${parentName ?? `#${parentId}`}`, onRemove: () => query.setFilter('parent_id', undefined) });
  const advancedCount = query.filters.parent_id ? 1 : 0;
  const clearAll = () => query.setFilters({ search: undefined, type: undefined, status: undefined, parent_id: undefined });

  async function toggle(account: ChartOfAccountResource) {
    setRowNotice(null);
    try {
      await update.mutateAsync({ chartOfAccount: account.id, data: { status: account.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' } });
      await queryClient.invalidateQueries();
    } catch (failure) {
      setRowNotice(`${account.code}: ${getApiErrorMessage(failure) ?? 'The change was refused.'}`);
    }
  }

  const typeSelect = (full: boolean) => (
    <FilterSelect label={`Type${full ? ' (filters sheet)' : ''}`} allLabel="All types" value={query.filters.type} options={ACCOUNT_TYPES.map((type) => ({ value: type, label: accountTypeLabel(type) }))} onChange={(value) => query.setFilter('type', value)} className={full ? '' : 'md:w-40'} />
  );
  const statusSelect = (full: boolean) => (
    <FilterSelect label={`Status${full ? ' (filters sheet)' : ''}`} allLabel="All statuses" value={query.filters.status} options={STATUSES} onChange={(value) => query.setFilter('status', value)} className={full ? '' : 'md:w-40'} />
  );
  const parentControls = (id: string) => (
    <>
      <FilterField label="Level" htmlFor={`${id}-level`}>
        <FilterSelect label={`Level (${id})`} allLabel="All levels" value={query.filters.parent_id === '0' ? '0' : undefined} options={LEVELS} onChange={(value) => query.setFilter('parent_id', value)} />
      </FilterField>
      <FilterField label="Under account" htmlFor={`${id}-parent`}>
        <AccountCombobox
          id={`${id}-parent`}
          label="Under account"
          allLabel="Any parent"
          placeholder="Any parent"
          className="h-11 w-full md:h-10"
          value={parentId > 0 ? String(parentId) : 'all'}
          onValueChange={(value) => query.setFilter('parent_id', value === 'all' ? undefined : value)}
        />
      </FilterField>
    </>
  );

  const archivable = selected.filter((account) => account.status === 'ACTIVE');
  const reactivatable = selected.filter((account) => account.status !== 'ACTIVE');

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Chart of accounts"
        description="The accounts customers, suppliers and items map to. A heading groups accounts; only postable accounts take entries."
        primaryAction={mayManage ? { label: 'New account', onClick: () => navigate({ pathname: '/settings/chart-of-accounts/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search code or name…" ariaLabel="Search accounts" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {typeSelect(false)}
          {statusSelect(false)}
          <AdvancedFiltersToggle open={advancedOpen} activeCount={advancedCount} onToggle={() => setAdvancedOpen((open) => !open)} controlsId="accounts-advanced-filters" />
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={chips.filter((chip) => chip.id !== 'search').length} onClear={clearAll}>
          <FilterField label="Type">{typeSelect(true)}</FilterField>
          <FilterField label="Status">{statusSelect(true)}</FilterField>
          {parentControls('sheet')}
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <AdvancedFiltersPanel id="accounts-advanced-filters" open={advancedOpen} onClose={() => setAdvancedOpen(false)} activeCount={advancedCount} onReset={() => query.setFilter('parent_id', undefined)}>
        {parentControls('panel')}
      </AdvancedFiltersPanel>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      {rowNotice && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="accounts-row-notice">
          {rowNotice}
        </p>
      )}

      <BulkActionBar count={selected.length} noun="account" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
        {mayManage && (
          <>
            <Button type="button" variant="outline" size="sm" className="h-9 md:h-8" disabled={archivable.length === 0} title={archivable.length === 0 ? 'None of the selected accounts is active.' : undefined} onClick={() => setBulk({ target: 'ARCHIVED', rows: archivable })}>
              <Archive aria-hidden="true" className="mr-1.5 h-3.5 w-3.5" />
              Archive selected
            </Button>
            <Button type="button" variant="outline" size="sm" className="h-9 md:h-8" disabled={reactivatable.length === 0} title={reactivatable.length === 0 ? 'None of the selected accounts is archived.' : undefined} onClick={() => setBulk({ target: 'ACTIVE', rows: reactivatable })}>
              <RefreshCw aria-hidden="true" className="mr-1.5 h-3.5 w-3.5" />
              Activate selected
            </Button>
          </>
        )}
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={accounts}
        rowCount={meta?.total ?? 0}
        recordNoun="accounts"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, accountColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(account) => String(account.id)}
        onRowClick={(account: ChartOfAccountResource) => navigate({ pathname: `/settings/chart-of-accounts/${account.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load the chart of accounts."
        emptyMessage={chips.length > 0 ? 'No accounts match these filters.' : 'No accounts yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={accountColumns}
        actionsHeader="Actions"
        numberedPagination
        rowActions={
          mayManage
            ? (account: ChartOfAccountResource) => (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="h-11 w-11 md:h-8 md:w-8" aria-label={`Actions for ${account.code}`} onClick={(event) => event.stopPropagation()}>
                      <EllipsisVertical className="h-4 w-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem
                      onClick={(event) => {
                        event.stopPropagation();
                        navigate({ pathname: `/settings/chart-of-accounts/${account.id}`, search: listSearch });
                      }}
                    >
                      <Pencil className="mr-2 h-4 w-4 text-muted-foreground" />
                      Edit account
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={(event) => {
                        event.stopPropagation();
                        void toggle(account);
                      }}
                    >
                      {account.status === 'ACTIVE' ? (
                        <>
                          <Archive className="mr-2 h-4 w-4 text-muted-foreground" />
                          Archive account
                        </>
                      ) : (
                        <>
                          <RefreshCw className="mr-2 h-4 w-4 text-muted-foreground" />
                          Activate account
                        </>
                      )}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              )
            : undefined
        }
      />

      {bulk && (
        <BulkRunDialog
          key={`${bulk.target}-${bulk.rows.map((row) => row.id).join(',')}`}
          open
          title={bulk.target === 'ARCHIVED' ? 'Archive accounts' : 'Activate accounts'}
          intro={
            bulk.target === 'ARCHIVED'
              ? `Archive ${bulk.rows.length} ${bulk.rows.length === 1 ? 'account' : 'accounts'}? Archived accounts stay on record and can be activated again; they are no longer offered when choosing an account.`
              : `Activate ${bulk.rows.length} archived ${bulk.rows.length === 1 ? 'account' : 'accounts'}?`
          }
          rows={bulk.rows}
          ready
          run={(row) => update.mutateAsync({ chartOfAccount: row.id, data: { status: bulk.target } })}
          labelOf={(row) => accountLabel(row)}
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
            title="CHART OF ACCOUNTS"
            printedAt={new Date()}
            recordCount={accounts.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, accountColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={accountColumns} rows={accounts} getRowKey={(account) => account.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="CHART OF ACCOUNTS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected accounts` }]}>
            <PrintableTable columns={accountColumns} rows={selected} getRowKey={(account) => account.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
