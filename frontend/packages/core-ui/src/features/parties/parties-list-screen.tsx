import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { usePartiesIndex, type PartiesIndexSort, type PartyResource } from '@erp/core-api';
import { EllipsisVertical, Pencil, UsersRound } from 'lucide-react';

import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import {
  ActiveFilterChips,
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
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { partyColumns } from './parties-columns';

interface PartyFilters {
  search: string;
  status: string;
  [key: string]: string;
}

/**
 * Trading partners: the shared identity every customer and supplier role hangs off (ADR 0030). Listing is a reference lookup open to any signed-in user, but
 * this screen is reached by those who maintain partners or their roles. Archived partners are not listed (the API archives by soft delete); one is brought
 * back from "Register new" by its duplicate check, which offers Restore (ADR 0105).
 *
 * Bulk: only Print selected. Archiving a partner needs both role accounts archived first, so a mixed selection has no safe common action; each partner is
 * archived from its own record where that precondition is explained.
 */
export function PartiesListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayManage = usePermission('parties.party.manage');

  const query = useListQueryState<PartyFilters>({ defaultSort: 'name', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const { view, setView } = useListViewPreference('parties-list');
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('parties-list', Object.fromEntries(partyColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));

  const result = usePartiesIndex({
    include: 'customer_account,supplier_account',
    'filter[search]': query.filters.search || undefined,
    'filter[status]': (query.filters.status as 'ACTIVE' | 'ARCHIVED' | undefined) || undefined,
    sort: query.sort as PartiesIndexSort | undefined,
    page: query.page,
    per_page: query.perPage,
  });
  const parties = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<PartyResource>(), ...toTanStackColumns(partyColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => parties.filter((party) => rowSelection[String(party.id)]), [parties, rowSelection]);

  const sorting: SortingState = apiSortToTableSorting(query.sort, partyColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Trading Partners' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Trading Partners (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${query.filters.status === 'ACTIVE' ? 'Active' : 'Archived'}`, onRemove: () => query.setFilter('status', undefined) });
  const clearAll = () => query.setFilters({ search: undefined, status: undefined });

  const statusSelect = (className: string, ariaLabel: string) => (
    <Select value={query.filters.status || 'ALL'} onValueChange={(value) => query.setFilter('status', value === 'ALL' ? undefined : value)}>
      <SelectTrigger className={className} aria-label={ariaLabel}>
        <SelectValue placeholder="All statuses" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="ALL">All statuses</SelectItem>
        <SelectItem value="ACTIVE">Active</SelectItem>
        <SelectItem value="ARCHIVED">Archived</SelectItem>
      </SelectContent>
    </Select>
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Trading partners"
        description="Everyone you trade with. A partner can be a customer, a supplier or both; each role has its own account."
        primaryAction={mayManage ? { label: 'New trading partner', onClick: () => navigate({ pathname: '/parties/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput
          input={searchBox.input}
          searchProps={searchBox.searchProps}
          onClear={() => query.setFilter('search', undefined)}
          placeholder="Search name or code…"
          ariaLabel="Search trading partners"
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {statusSelect('h-10 w-36', 'Status')}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={query.filters.status ? 1 : 0} onClear={clearAll}>
          <FilterField label="Status">{statusSelect('h-11 w-full', 'Status (filters sheet)')}</FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="trading partner" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={parties}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, partyColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(party: PartyResource) => navigate({ pathname: `/parties/${party.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load trading partners."
        emptyMessage={chips.length > 0 ? 'No trading partners match these filters.' : 'No trading partners yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={partyColumns}
        recordNoun="trading partners"
        actionsHeader="Actions"
        numberedPagination
        rowActions={(party: PartyResource) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-11 w-11 md:h-8 md:w-8" aria-label={`Actions for ${party.code}`} onClick={(event) => event.stopPropagation()}>
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem
                onClick={(event) => {
                  event.stopPropagation();
                  navigate({ pathname: `/parties/${party.id}`, search: listSearch });
                }}
              >
                <UsersRound className="mr-2 h-4 w-4 text-muted-foreground" />
                View details
              </DropdownMenuItem>
              {mayManage && (
                <DropdownMenuItem
                  onClick={(event) => {
                    event.stopPropagation();
                    navigate({ pathname: `/parties/${party.id}/edit`, search: listSearch });
                  }}
                >
                  <Pencil className="mr-2 h-4 w-4 text-muted-foreground" />
                  Edit partner
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="TRADING PARTNERS"
            printedAt={new Date()}
            recordCount={parties.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, partyColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={partyColumns} rows={parties} getRowKey={(party) => party.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="TRADING PARTNERS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected trading partners` }]}>
            <PrintableTable columns={partyColumns} rows={selected} getRowKey={(party) => party.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
