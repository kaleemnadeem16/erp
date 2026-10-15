import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, ChevronUp, EllipsisVertical, Eye, Pencil, Search, SlidersHorizontal, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useCurrenciesIndex, useOpportunitiesIndex, usePartiesShow, useSalesOwnersIndex, type OpportunitiesIndexSort, type OpportunityResource } from '@erp/core-api';

import { DataTable, isColumnVisibleByDefault, selectionColumn, toTanStackColumns } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Badge } from '../../components/ui/badge';
import { FilterToggle } from '../../components/ui/filter-toggle';
import { Button } from '../../components/ui/button';
import { Collapse } from '../../components/ui/collapse';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
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
import { trimQty } from '../package-stock/quantity';
import { OpportunityBulkBar } from './opportunity-bulk-actions';
import { OUTCOME_LABEL, OutcomeBadge, SALES, useSalesStages } from './sales-common';

/** Whether a day (ISO date) has passed — shown beside an open deal's expected close or next action so nothing quietly goes stale. */
const isPast = (day: string | null | undefined) => Boolean(day) && (day as string) < new Date().toLocaleDateString('en-CA');

interface Filters {
  search: string;
  outcome: string;
  stage_id: string;
  party_id: string;
  owner_id: string;
  mine: string;
  needs_attention: string;
  [key: string]: string;
}

type Row = OpportunityResource;

/** The name behind a filtered-by id, for its chip — looked up by id, so it survives a page reload and never depends on a picker's first page. */
function useFilterLabels(partyId: string | undefined, ownerId: string | undefined) {
  const party = usePartiesShow(Number(partyId), undefined, { query: { enabled: Boolean(partyId) } });
  const owner = useSalesOwnersIndex({ 'filter[id]': Number(ownerId) }, { query: { enabled: Boolean(ownerId) } });

  return {
    party: party.data?.status === 200 ? party.data.data.data.name : undefined,
    owner: owner.data?.status === 200 ? owner.data.data.data[0]?.name : undefined,
  };
}

/** The pipeline: open deals by default, with the ones whose owner can no longer be responsible flagged. */
export function OpportunitiesListScreen() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayCreate = usePermission(SALES.opportunity);
  const mayAssign = usePermission(SALES.assign);
  const mayClose = usePermission(SALES.close);
  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const { stages, active: activeStages, query: stagesQuery } = useSalesStages();
  const owners = useSalesOwnersIndex();
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' });
  const currencyCode = useMemo(() => new Map((currencies.data?.status === 200 ? currencies.data.data.data : []).map((currency) => [currency.id, currency.code])), [currencies.data]);
  const outcome = query.filters.outcome ?? 'OPEN';
  const { view, setView } = useListViewPreference('opportunities-list');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() => Boolean(query.filters.party_id || query.filters.owner_id));
  const labels = useFilterLabels(query.filters.party_id, query.filters.owner_id);

  const list = useOpportunitiesIndex({
    'filter[outcome]': outcome === 'ALL' ? undefined : (outcome as 'OPEN'),
    'filter[stage_id]': query.filters.stage_id ? Number(query.filters.stage_id) : undefined,
    'filter[party_id]': query.filters.party_id ? Number(query.filters.party_id) : undefined,
    'filter[owner_id]': query.filters.owner_id ? Number(query.filters.owner_id) : undefined,
    'filter[search]': query.filters.search || undefined,
    'filter[mine]': query.filters.mine === 'true' ? true : undefined,
    'filter[needs_attention]': query.filters.needs_attention === 'true' ? true : undefined,
    sort: query.sort as OpportunitiesIndexSort | undefined,
    include: 'party,stage,owner',
    per_page: query.perPage,
    page: query.page,
  });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const meta = list.data?.status === 200 ? list.data.data.meta : undefined;

  // The pipeline grows with the company: one stage or one person to own deals needs no stage or owner columns, filters or bulk moves — they appear as soon as there is a choice.
  const ownerCount = owners.data?.status === 200 ? owners.data.data.data.length : undefined;
  const hasOwnerFacts = Boolean(query.filters.owner_id || query.filters.needs_attention || rows.some((row) => row.owner_needs_reassignment));
  const usesOwners = ownerCount === undefined || ownerCount > 1 || hasOwnerFacts;
  const usesStages = stagesQuery.isLoading || stages.length > 1 || Boolean(query.filters.stage_id);

  const open = (row: Row) => navigate({ pathname: `/opportunities/${row.id}`, search });

  const allColumns = useMemo<DataColumn<Row>[]>(
    () => [
      { key: 'number', header: 'Number', value: (row) => row.number ?? `#${row.id}`, cell: (row) => <span className="ui-order-link font-medium">{row.number ?? `#${row.id}`}</span>, sortable: true },
      { key: 'title', header: 'Opportunity', value: (row) => row.title, cardRole: 'title', sortable: true },
      { key: 'party', header: 'For', value: (row) => row.party?.name ?? null, cardRole: 'subtitle' },
      { key: 'stage', header: 'Stage', value: (row) => (row.outcome === 'OPEN' ? (row.stage?.name ?? null) : (OUTCOME_LABEL[row.outcome] ?? row.outcome)) },
      {
        key: 'owner',
        header: 'Owner',
        value: (row) => row.owner_name ?? null,
        cell: (row) => (
          <span className="inline-flex flex-wrap items-center gap-1">
            {row.owner_name}
            {row.owner_needs_reassignment && (
              <span className="inline-flex items-center gap-1 text-xs text-destructive" data-testid="owner-needs-reassignment">
                <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> needs a new owner
              </span>
            )}
          </span>
        ),
      },
      {
        key: 'value',
        header: 'Expected value',
        align: 'right',
        value: (row) => (row.expected_amount ? `${trimQty(row.expected_amount)} ${currencyCode.get(row.expected_currency_id ?? 0) ?? ''}`.trim() : null),
      },
      {
        key: 'close',
        header: 'Expected close',
        value: (row) => formatDate(row.expected_close_on),
        cell: (row) => (
          <span className={row.outcome === 'OPEN' && isPast(row.expected_close_on) ? 'text-destructive' : undefined}>
            {formatDate(row.expected_close_on) ?? '—'}
            {row.outcome === 'OPEN' && isPast(row.expected_close_on) && <span className="ml-1 text-xs">overdue</span>}
          </span>
        ),
        sortable: true,
        sortKey: 'expected_close_on',
      },
      {
        key: 'next',
        header: 'Next action',
        value: (row) => (row.next_action ? `${row.next_action}${row.next_action_on ? ` · ${formatDate(row.next_action_on)}` : ''}` : null),
        cell: (row) => (
          <span className={row.outcome === 'OPEN' && isPast(row.next_action_on) ? 'text-destructive' : undefined}>
            {row.next_action ? `${row.next_action}${row.next_action_on ? ` · ${formatDate(row.next_action_on)}` : ''}` : '—'}
            {row.outcome === 'OPEN' && isPast(row.next_action_on) && <span className="ml-1 text-xs">overdue</span>}
          </span>
        ),
        sortable: true,
        sortKey: 'next_action_on',
      },
      // Optional columns: everything else the record carries, one toggle away in Columns.
      { key: 'source', header: 'Source', value: (row) => row.source ?? null, screenVisible: false },
      { key: 'enquiry', header: 'Came from an enquiry', value: (row) => (row.lead_id ? 'Yes' : 'No'), screenVisible: false },
      { key: 'description', header: 'Description', value: (row) => row.description ?? null, screenVisible: false },
      { key: 'created', header: 'Created', value: (row) => formatDate(row.created_at), sortable: true, sortKey: 'created_at', screenVisible: false },
      { key: 'updated', header: 'Last updated', value: (row) => formatDate(row.updated_at), screenVisible: false },
      { key: 'closed', header: 'Closed on', value: (row) => formatDate(row.closed_at), screenVisible: false },
      { key: 'closure', header: 'Closure reason', value: (row) => row.closure_reason ?? null, screenVisible: false },
      { key: 'outcome', header: 'Outcome', value: (row) => OUTCOME_LABEL[row.outcome] ?? row.outcome, cell: (row) => <OutcomeBadge outcome={row.outcome} />, filterable: true },
    ],
    [currencyCode],
  );
  const columns = useMemo(() => allColumns.filter((column) => (column.key !== 'stage' || usesStages) && (column.key !== 'owner' || usesOwners)), [allColumns, usesStages, usesOwners]);
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('opportunities-list', Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const tanStackColumns = useMemo(() => [selectionColumn<Row>(), ...toTanStackColumns(columns)], [columns]);
  const printedColumns = columns.filter((column) => columnVisibility[column.key] !== false);

  // Selection is the ticked rows of the page in view: it never outlives a change of page, sort or filter.
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const viewKey = JSON.stringify([query.filters, query.sort, query.page, query.perPage]);
  useEffect(() => setRowSelection({}), [viewKey]);
  const selectedRows = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);

  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Opportunities' });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({ documentTitle: 'Opportunities (selected)' });
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  const stageName = (id: string) => stages.find((stage) => String(stage.id) === id)?.name ?? `Stage #${id}`;
  const advancedCount = [query.filters.party_id, query.filters.owner_id].filter(Boolean).length;
  const mobileCount = [query.filters.outcome, query.filters.stage_id, query.filters.mine, query.filters.party_id, query.filters.owner_id, query.filters.needs_attention].filter(Boolean).length;

  const chips: Array<{ id: string; label: string; onRemove: () => void }> = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.outcome)
    chips.push({
      id: 'outcome',
      label: `Outcome: ${query.filters.outcome === 'ALL' ? 'All outcomes' : (OUTCOME_LABEL[query.filters.outcome] ?? query.filters.outcome)}`,
      onRemove: () => query.setFilter('outcome', undefined),
    });
  if (query.filters.stage_id) chips.push({ id: 'stage', label: `Stage: ${stageName(query.filters.stage_id)}`, onRemove: () => query.setFilter('stage_id', undefined) });
  if (query.filters.party_id) chips.push({ id: 'party', label: `For: ${labels.party ?? `Partner #${query.filters.party_id}`}`, onRemove: () => query.setFilter('party_id', undefined) });
  if (query.filters.owner_id) chips.push({ id: 'owner', label: `Owner: ${labels.owner ?? `Person #${query.filters.owner_id}`}`, onRemove: () => query.setFilter('owner_id', undefined) });
  if (query.filters.mine === 'true') chips.push({ id: 'mine', label: 'Mine only', onRemove: () => query.setFilter('mine', undefined) });
  if (query.filters.needs_attention === 'true') chips.push({ id: 'attention', label: 'Needs a new owner', onRemove: () => query.setFilter('needs_attention', undefined) });

  const filterMeta = chips.map((chip) => ({ label: 'Filter', value: chip.label }));
  const clearAll = () => query.setFilters({ search: undefined, outcome: undefined, stage_id: undefined, party_id: undefined, owner_id: undefined, mine: undefined, needs_attention: undefined });
  const clearAdvanced = () => query.setFilters({ party_id: undefined, owner_id: undefined });

  const outcomeSelect = (className: string) => (
    <Select value={outcome} onValueChange={(value) => query.setFilter('outcome', value === 'OPEN' ? undefined : value)}>
      <SelectTrigger className={className} aria-label="Outcome">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="OPEN">Open</SelectItem>
        <SelectItem value="WON">Won</SelectItem>
        <SelectItem value="LOST">Lost</SelectItem>
        <SelectItem value="ABANDONED">Abandoned</SelectItem>
        <SelectItem value="ALL">All outcomes</SelectItem>
      </SelectContent>
    </Select>
  );
  const stageSelect = (className: string) => (
    <Select value={query.filters.stage_id ?? 'all'} onValueChange={(value) => query.setFilter('stage_id', value === 'all' ? undefined : value)}>
      <SelectTrigger className={className} aria-label="Stage">
        <SelectValue placeholder="Stage" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All stages</SelectItem>
        {stages.map((stage) => (
          <SelectItem key={stage.id} value={String(stage.id)}>
            {stage.name}
            {stage.status === 'ARCHIVED' ? ' (archived)' : ''}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
  const flag = (label: string, key: 'mine' | 'needs_attention', className?: string) => (
    <FilterToggle pressed={query.filters[key] === 'true'} onPressedChange={(pressed) => query.setFilter(key, pressed ? 'true' : undefined)} className={className}>
      {label}
    </FilterToggle>
  );
  const partyFilter = (idPrefix: string) => (
    <div className="space-y-1.5">
      <Label htmlFor={`${idPrefix}-party`} className="text-xs font-medium text-muted-foreground">
        For (trading partner)
      </Label>
      <PartyCombobox
        id={`${idPrefix}-party`}
        label="Trading partner"
        className="w-full"
        allLabel="All trading partners"
        value={query.filters.party_id ?? 'all'}
        onValueChange={(value) => query.setFilter('party_id', value === 'all' ? undefined : value)}
      />
    </div>
  );
  const ownerFilter = (idPrefix: string) => (
    <div className="space-y-1.5">
      <Label htmlFor={`${idPrefix}-owner`} className="text-xs font-medium text-muted-foreground">
        Owner
      </Label>
      <OwnerCombobox
        id={`${idPrefix}-owner`}
        label="Owner"
        className="w-full"
        allLabel="Anyone"
        value={query.filters.owner_id ?? 'all'}
        onValueChange={(value) => query.setFilter('owner_id', value === 'all' ? undefined : value)}
      />
    </div>
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="opportunities-list">
        <PageHeader
          title="Opportunities"
          description="The deals being pursued, who is responsible for each and what happens next."
          primaryAction={mayCreate ? { label: 'New opportunity', onClick: () => navigate({ pathname: '/opportunities/new', search }) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 md:w-80 md:flex-none">
            <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input className="h-10 w-full pl-10" placeholder="Search title, number or customer…" aria-label="Search" {...searchBox.searchProps} />
          </div>
          <div className="hidden items-center gap-3 md:flex md:flex-wrap">
            {outcomeSelect('h-10 w-40')}
            {usesStages && stageSelect('h-10 w-44')}
            {usesOwners && flag('Mine', 'mine')}
            {usesOwners && flag('Needs a new owner', 'needs_attention')}
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
              {usesOwners && ownerFilter('adv')}
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
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Outcome</Label>
                {outcomeSelect('w-full')}
              </div>
              {usesStages && (
                <div className="space-y-1.5 border-t pt-3">
                  <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Stage</Label>
                  {stageSelect('w-full')}
                </div>
              )}
              <div className="border-t pt-3">{partyFilter('mob')}</div>
              {usesOwners && <div className="border-t pt-3">{ownerFilter('mob')}</div>}
              {usesOwners && (
                <div className="flex flex-col gap-3 border-t pt-3">
                  {flag('Mine', 'mine', 'w-full justify-start')}
                  {flag('Needs a new owner', 'needs_attention', 'w-full justify-start')}
                </div>
              )}
            </div>
            <SheetFooter className="gap-2 sm:space-x-0">
              <Button
                variant="outline"
                onClick={() => {
                  query.setFilters({ outcome: undefined, stage_id: undefined, party_id: undefined, owner_id: undefined, mine: undefined, needs_attention: undefined });
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
          <OpportunityBulkBar
            selected={selectedRows}
            stages={activeStages}
            showStage={usesStages}
            showOwner={usesOwners}
            mayManage={mayCreate}
            mayAssign={mayAssign}
            mayClose={mayClose}
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
          errorMessage="Couldn't load opportunities."
          emptyMessage="No opportunities match these filters."
          view={view}
          onViewChange={setView}
          cardColumns={columns}
          recordNoun="opportunities"
          actionsHeader="Actions"
          numberedPagination
          rowActions={(row: Row) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Actions for ${row.number ?? row.title}`} onClick={(event) => event.stopPropagation()}>
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
                {mayCreate && row.outcome === 'OPEN' && (
                  <DropdownMenuItem
                    onClick={(event) => {
                      event.stopPropagation();
                      navigate({ pathname: `/opportunities/${row.id}/edit`, search });
                    }}
                  >
                    <Pencil className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                    Edit deal
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        />

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout
              title="OPPORTUNITIES"
              printedAt={new Date()}
              recordCount={rows.length}
              meta={[...filterMeta, { label: 'Page', value: `${rows.length} of ${meta?.total ?? rows.length} shown` }]}
            >
              <PrintableTable columns={printedColumns} rows={rows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
        <div className="hidden">
          <div ref={selectedPrintRef}>
            <PrintLayout
              title="OPPORTUNITIES (SELECTED)"
              printedAt={new Date()}
              recordCount={selectedRows.length}
              meta={[{ label: 'Selection', value: `${selectedRows.length} selected opportunities` }]}
            >
              <PrintableTable columns={printedColumns} rows={selectedRows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
      </div>
    </ScreenTemplate>
  );
}
