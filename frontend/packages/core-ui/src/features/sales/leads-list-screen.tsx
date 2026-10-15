import { useEffect, useMemo, useState } from 'react';
import { ChevronUp, EllipsisVertical, Eye, Search, SlidersHorizontal, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useLeadsIndex, usePartiesShow, useSalesOwnersIndex, type LeadResource, type LeadsIndexSort } from '@erp/core-api';

import { DataTable, isColumnVisibleByDefault, selectionColumn, toTanStackColumns } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Collapse } from '../../components/ui/collapse';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import { FilterToggle } from '../../components/ui/filter-toggle';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { usePermission } from '../../hooks/use-permission';
import { formatDate } from '../../lib/format-date';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { LeadBulkBar } from './lead-bulk-actions';
import { LEAD_LABEL, LeadStatusBadge, SALES } from './sales-common';

interface Filters {
  search: string;
  status: string;
  party_id: string;
  assigned_to: string;
  unassigned: string;
  [key: string]: string;
}

type Row = LeadResource;

/** The name behind a filtered-by id, for its chip — looked up by id, so it survives a page reload and never depends on a picker's first page. */
function useFilterLabels(partyId: string | undefined, assignedTo: string | undefined) {
  const party = usePartiesShow(Number(partyId), undefined, { query: { enabled: Boolean(partyId) } });
  const owner = useSalesOwnersIndex({ 'filter[id]': Number(assignedTo) }, { query: { enabled: Boolean(assignedTo) } });

  return {
    party: party.data?.status === 200 ? party.data.data.data.name : undefined,
    owner: owner.data?.status === 200 ? owner.data.data.data[0]?.name : undefined,
  };
}

/** Optional enquiries: nothing requires one before a deal. Newest first. */
export function LeadsListScreen() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayCreate = usePermission(SALES.lead);
  const mayAssign = usePermission(SALES.assign);
  const query = useListQueryState<Filters>({ defaultSort: '-received_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const { view, setView } = useListViewPreference('leads-list');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() => Boolean(query.filters.party_id || query.filters.assigned_to));
  const labels = useFilterLabels(query.filters.party_id, query.filters.assigned_to);
  const owners = useSalesOwnersIndex();

  const list = useLeadsIndex({
    'filter[status]': (query.filters.status || undefined) as 'NEW' | undefined,
    'filter[party_id]': query.filters.party_id ? Number(query.filters.party_id) : undefined,
    'filter[assigned_to]': query.filters.assigned_to ? Number(query.filters.assigned_to) : undefined,
    'filter[search]': query.filters.search || undefined,
    'filter[unassigned]': query.filters.unassigned === 'true' ? true : undefined,
    sort: query.sort as LeadsIndexSort | undefined,
    include: 'party',
    per_page: query.perPage,
    page: query.page,
  });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const meta = list.data?.status === 200 ? list.data.data.meta : undefined;

  // One person to hand an enquiry to needs no assignee column, filter or bulk assignment; they appear once there is a choice (or something is already assigned/filtered).
  const ownerCount = owners.data?.status === 200 ? owners.data.data.data.length : undefined;
  const usesOwners = ownerCount === undefined || ownerCount > 1 || Boolean(query.filters.assigned_to || query.filters.unassigned);

  const open = (row: Row) => navigate({ pathname: `/leads/${row.id}`, search });

  const allColumns = useMemo<DataColumn<Row>[]>(
    () => [
      { key: 'number', header: 'Number', value: (row) => row.number ?? `#${row.id}`, cell: (row) => <span className="ui-order-link font-medium">{row.number ?? `#${row.id}`}</span>, sortable: true },
      { key: 'enquirer', header: 'Enquiry from', value: (row) => row.enquirer_name, cardRole: 'title' },
      { key: 'organisation', header: 'Organisation', value: (row) => row.enquirer_organisation, cardRole: 'subtitle' },
      { key: 'source', header: 'Source', value: (row) => row.source },
      { key: 'assigned', header: 'Assigned to', value: (row) => row.assigned_name ?? 'Unassigned' },
      { key: 'received', header: 'Received', value: (row) => formatDate(row.received_at), sortable: true, sortKey: 'received_at' },
      { key: 'status', header: 'Status', value: (row) => LEAD_LABEL[row.status] ?? row.status, cell: (row) => <LeadStatusBadge status={row.status} />, filterable: true },
      // Optional columns: everything else the enquiry carries, one toggle away in Columns.
      { key: 'email', header: 'Email', value: (row) => row.enquirer_email, screenVisible: false },
      { key: 'phone', header: 'Phone', value: (row) => row.enquirer_phone, screenVisible: false },
      { key: 'description', header: 'What they want', value: (row) => row.description, screenVisible: false },
      { key: 'party', header: 'Trading partner', value: (row) => row.party?.name ?? null, screenVisible: false },
      { key: 'qualified', header: 'Qualified on', value: (row) => formatDate(row.qualified_at), screenVisible: false },
      { key: 'disqualified', header: 'Disqualified on', value: (row) => formatDate(row.disqualified_at), screenVisible: false },
      { key: 'reason', header: 'Disqualification reason', value: (row) => row.disqualification_reason, screenVisible: false },
      { key: 'created', header: 'Recorded', value: (row) => formatDate(row.created_at), sortable: true, sortKey: 'created_at', screenVisible: false },
    ],
    [],
  );
  const columns = useMemo(() => allColumns.filter((column) => column.key !== 'assigned' || usesOwners), [allColumns, usesOwners]);
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('leads-list', Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const tanStackColumns = useMemo(() => [selectionColumn<Row>(), ...toTanStackColumns(columns)], [columns]);
  const printedColumns = columns.filter((column) => columnVisibility[column.key] !== false);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  // Selection is the ticked rows of the page in view: it never outlives a change of page, sort or filter.
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const viewKey = JSON.stringify([query.filters, query.sort, query.page, query.perPage]);
  useEffect(() => setRowSelection({}), [viewKey]);
  const selectedRows = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Enquiries' });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({ documentTitle: 'Enquiries (selected)' });

  const advancedCount = [query.filters.party_id, query.filters.assigned_to].filter(Boolean).length;
  const mobileCount = [query.filters.status, query.filters.party_id, query.filters.assigned_to, query.filters.unassigned].filter(Boolean).length;

  const chips: Array<{ id: string; label: string; onRemove: () => void }> = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${LEAD_LABEL[query.filters.status] ?? query.filters.status}`, onRemove: () => query.setFilter('status', undefined) });
  if (query.filters.party_id) chips.push({ id: 'party', label: `Partner: ${labels.party ?? `#${query.filters.party_id}`}`, onRemove: () => query.setFilter('party_id', undefined) });
  if (query.filters.assigned_to) chips.push({ id: 'assigned', label: `Assigned to: ${labels.owner ?? `#${query.filters.assigned_to}`}`, onRemove: () => query.setFilter('assigned_to', undefined) });
  if (query.filters.unassigned === 'true') chips.push({ id: 'unassigned', label: 'Unassigned only', onRemove: () => query.setFilter('unassigned', undefined) });

  const filterMeta = chips.map((chip) => ({ label: 'Filter', value: chip.label }));
  const clearAll = () => query.setFilters({ search: undefined, status: undefined, party_id: undefined, assigned_to: undefined, unassigned: undefined });
  const clearAdvanced = () => query.setFilters({ party_id: undefined, assigned_to: undefined });

  const statusSelect = (className: string) => (
    <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
      <SelectTrigger className={className} aria-label="Status">
        <SelectValue placeholder="Status" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All statuses</SelectItem>
        {Object.entries(LEAD_LABEL).map(([value, label]) => (
          <SelectItem key={value} value={value}>
            {label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
  const unassignedToggle = (className?: string) => (
    <FilterToggle pressed={query.filters.unassigned === 'true'} onPressedChange={(pressed) => query.setFilter('unassigned', pressed ? 'true' : undefined)} className={className}>
      Unassigned
    </FilterToggle>
  );
  const partyFilter = (idPrefix: string) => (
    <div className="space-y-1.5">
      <Label htmlFor={`${idPrefix}-party`} className="text-xs font-medium text-muted-foreground">
        Trading partner
      </Label>
      <PartyCombobox
        id={`${idPrefix}-party`}
        className="w-full"
        label="Trading partner"
        allLabel="All trading partners"
        value={query.filters.party_id ?? 'all'}
        onValueChange={(value) => query.setFilter('party_id', value === 'all' ? undefined : value)}
      />
    </div>
  );
  const assigneeFilter = (idPrefix: string) => (
    <div className="space-y-1.5">
      <Label htmlFor={`${idPrefix}-assignee`} className="text-xs font-medium text-muted-foreground">
        Assigned to
      </Label>
      <OwnerCombobox
        id={`${idPrefix}-assignee`}
        className="w-full"
        label="Assigned to"
        allLabel="Anyone"
        value={query.filters.assigned_to ?? 'all'}
        onValueChange={(value) => query.setFilter('assigned_to', value === 'all' ? undefined : value)}
      />
    </div>
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="leads-list">
        <PageHeader
          title="Enquiries"
          description="Optional intake. Qualify an enquiry and turn it into one or more opportunities — or start a deal directly."
          primaryAction={mayCreate ? { label: 'Record an enquiry', onClick: () => navigate({ pathname: '/leads/new', search }) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 md:w-80 md:flex-none">
            <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input className="h-10 w-full pl-10" placeholder="Search name, organisation or number…" aria-label="Search" {...searchBox.searchProps} />
          </div>
          <div className="hidden items-center gap-3 md:flex md:flex-wrap">
            {statusSelect('h-10 w-40')}
            {usesOwners && unassignedToggle()}
            <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
            <Button
              variant={advancedOpen || advancedCount > 0 ? 'secondary' : 'outline'}
              size="sm"
              className={`relative h-10 px-4 ${advancedOpen || advancedCount > 0 ? 'ui-btn-filters-active' : ''}`}
              aria-expanded={advancedOpen}
              onClick={() => setAdvancedOpen((current) => !current)}
            >
              <SlidersHorizontal className="mr-1.5 h-4 w-4" aria-hidden="true" />
              Filters
              {advancedCount > 0 && <Badge className="ui-filter-badge absolute -right-2 -top-2 h-5 min-w-5 justify-center rounded-full px-1 text-[11px]">{advancedCount}</Badge>}
            </Button>
          </div>
          <PrintTrigger onPrint={() => print()} label="" aria-label="Print" className="h-10 shrink-0 md:hidden" />
          <Button variant="outline" size="sm" className="h-10 shrink-0 md:hidden" onClick={() => setFiltersOpen(true)}>
            <SlidersHorizontal className="mr-1 h-4 w-4" aria-hidden="true" />
            Filters
            {mobileCount > 0 && <Badge className="ui-filter-badge ml-1">{mobileCount}</Badge>}
          </Button>
        </div>

        <Collapse open={advancedOpen} className="hidden md:grid">
          <div className="ui-filter-panel rounded-lg border bg-card p-4 text-card-foreground shadow-xs">
            <div className="mb-4 flex items-center justify-between">
              <span className="ui-filter-panel-title flex items-center gap-2 text-sm font-semibold">
                <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
                Advanced filters
              </span>
              <div className="flex items-center gap-2">
                {advancedCount > 0 && (
                  <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground" onClick={clearAdvanced}>
                    Reset filters
                  </Button>
                )}
                <Button variant="ghost" size="sm" className="h-7 gap-1 px-2 text-xs" onClick={() => setAdvancedOpen(false)} aria-label="Close filters">
                  Hide filters
                  <ChevronUp aria-hidden="true" className="h-4 w-4" />
                </Button>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {partyFilter('adv')}
              {usesOwners && assigneeFilter('adv')}
            </div>
          </div>
        </Collapse>

        <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
          <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto md:hidden">
            <SheetHeader>
              <SheetTitle>Filters</SheetTitle>
            </SheetHeader>
            <div className="flex flex-col gap-4 py-4">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</Label>
                {statusSelect('w-full')}
              </div>
              <div className="border-t pt-3">{partyFilter('mob')}</div>
              {usesOwners && <div className="border-t pt-3">{assigneeFilter('mob')}</div>}
              {usesOwners && <div className="border-t pt-3">{unassignedToggle('w-full justify-start')}</div>}
            </div>
            <SheetFooter className="gap-2 sm:space-x-0">
              <Button
                variant="outline"
                onClick={() => {
                  query.setFilters({ status: undefined, party_id: undefined, assigned_to: undefined, unassigned: undefined });
                  setFiltersOpen(false);
                }}
              >
                Clear filters
              </Button>
              <Button onClick={() => setFiltersOpen(false)}>Done</Button>
            </SheetFooter>
          </SheetContent>
        </Sheet>

        {chips.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 pt-1" data-testid="active-filters">
            {chips.map((chip) => (
              <Badge key={chip.id} variant="secondary" className="ui-chip-active h-7 gap-1 px-2.5 text-xs font-normal">
                {chip.label}
                <button type="button" onClick={chip.onRemove} className="rounded-full p-0.5 hover:bg-muted" aria-label={`Remove filter: ${chip.label}`}>
                  <X className="h-3 w-3" aria-hidden="true" />
                </button>
              </Badge>
            ))}
            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground" onClick={clearAll}>
              Clear all
            </Button>
          </div>
        )}

        {selectedRows.length > 0 && (
          <LeadBulkBar
            selected={selectedRows}
            showOwner={usesOwners}
            mayManage={mayCreate}
            mayAssign={mayAssign}
            onPrintSelected={() => printSelected()}
            onClear={() => setRowSelection({})}
            onChanged={async () => {
              await list.refetch();
            }}
          />
        )}

        <DataTable
          columns={tanStackColumns}
          data={rows}
          rowCount={meta?.total ?? 0}
          pagination={pagination}
          onPaginationChange={(updater) => {
            const next = typeof updater === 'function' ? updater(pagination) : updater;
            if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
            else query.setPage(next.pageIndex + 1);
          }}
          sorting={sorting}
          onSortingChange={(updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            query.setSort(tableSortingToApiSort(next, columns));
          }}
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          rowSelection={rowSelection}
          onRowSelectionChange={setRowSelection}
          getRowId={(row) => String(row.id)}
          onRowClick={open}
          isLoading={list.isLoading}
          isError={list.isError}
          error={list.error}
          onRetry={() => void list.refetch()}
          errorMessage="Couldn't load enquiries."
          emptyMessage="No enquiries match these filters."
          view={view}
          onViewChange={setView}
          cardColumns={columns}
          recordNoun="enquiries"
          actionsHeader="Actions"
          numberedPagination
          rowActions={(row: Row) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Actions for ${row.number ?? row.enquirer_name}`} onClick={(event) => event.stopPropagation()}>
                  <EllipsisVertical className="h-4 w-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem
                  onClick={(event) => {
                    event.stopPropagation();
                    open(row);
                  }}
                >
                  <Eye className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  View details
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        />

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout title="ENQUIRIES" printedAt={new Date()} recordCount={rows.length} meta={[...filterMeta, { label: 'Page', value: `${rows.length} of ${meta?.total ?? rows.length} shown` }]}>
              <PrintableTable columns={printedColumns} rows={rows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
        <div className="hidden">
          <div ref={selectedPrintRef}>
            <PrintLayout title="ENQUIRIES (SELECTED)" printedAt={new Date()} recordCount={selectedRows.length} meta={[{ label: 'Selection', value: `${selectedRows.length} selected enquiries` }]}>
              <PrintableTable columns={printedColumns} rows={selectedRows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
      </div>
    </ScreenTemplate>
  );
}
