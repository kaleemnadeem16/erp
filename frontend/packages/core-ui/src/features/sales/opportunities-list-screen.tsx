import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useCurrenciesIndex, useOpportunitiesIndex, type OpportunitiesIndexSort, type OpportunityResource } from '@erp/core-api';

import { DataTable, isColumnVisibleByDefault, toTanStackColumns } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { usePermission } from '../../hooks/use-permission';
import { formatDate } from '../../lib/format-date';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { trimQty } from '../package-stock/quantity';
import { OUTCOME_LABEL, OutcomeBadge, SALES, useSalesStages } from './sales-common';


interface Filters {
  search: string;
  outcome: string;
  stage_id: string;
  mine: string;
  needs_attention: string;
  [key: string]: string;
}

type Row = OpportunityResource;

/** The pipeline: open deals by default, with the ones whose owner can no longer be responsible flagged. */
export function OpportunitiesListScreen() {
  const navigate = useNavigate();
  const mayCreate = usePermission(SALES.opportunity);
  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const { stages } = useSalesStages();
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' });
  const currencyCode = useMemo(() => new Map((currencies.data?.status === 200 ? currencies.data.data.data : []).map((currency) => [currency.id, currency.code])), [currencies.data]);
  const outcome = query.filters.outcome ?? 'OPEN';
  const { view, setView } = useListViewPreference('opportunities-list');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const list = useOpportunitiesIndex({
    'filter[outcome]': outcome === 'ALL' ? undefined : (outcome as 'OPEN'),
    'filter[stage_id]': query.filters.stage_id ? Number(query.filters.stage_id) : undefined,
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

  const columns = useMemo<DataColumn<Row>[]>(
    () => [
      { key: 'number', header: 'Number', value: (row) => row.number ?? `#${row.id}`, sortable: true },
      { key: 'title', header: 'Opportunity', value: (row) => row.title, cardRole: 'title', sortable: true },
      { key: 'party', header: 'For', value: (row) => row.party?.name ?? null, cardRole: 'subtitle' },
      { key: 'stage', header: 'Stage', value: (row) => (row.outcome === 'OPEN' ? (row.stage?.name ?? null) : (OUTCOME_LABEL[row.outcome] ?? row.outcome)) },
      {
        key: 'owner',
        header: 'Owner',
        value: (row) => row.owner_name ?? null,
        cell: (row) => (
          <span className="inline-flex items-center gap-1">
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
        value: (row) => (row.expected_amount ? `${trimQty(row.expected_amount)} ${currencyCode.get(row.expected_currency_id ?? 0) ?? ''}`.trim() : null),
      },
      { key: 'close', header: 'Expected close', value: (row) => formatDate(row.expected_close_on), sortable: true, sortKey: 'expected_close_on' },
      { key: 'next', header: 'Next action', value: (row) => (row.next_action ? `${row.next_action}${row.next_action_on ? ` · ${formatDate(row.next_action_on)}` : ''}` : null), sortable: true, sortKey: 'next_action_on' },
      { key: 'outcome', header: 'Outcome', value: (row) => OUTCOME_LABEL[row.outcome] ?? row.outcome, cell: (row) => <OutcomeBadge outcome={row.outcome} />, filterable: true },
    ],
    [currencyCode],
  );
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('opportunities-list', Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const tanStackColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="opportunities-list">
        <PageHeader
          title="Opportunities"
          description="The deals being pursued, who is responsible for each and what happens next."
          primaryAction={mayCreate ? { label: 'New opportunity', onClick: () => navigate('/opportunities/new') } : undefined}
        />
        <div className="flex flex-wrap items-center gap-2">
          <Input placeholder="Search title, number or customer…" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} className="h-9 min-w-0 flex-1 md:w-64 md:flex-none" aria-label="Search" />
          <Select value={outcome} onValueChange={(value) => query.setFilter('outcome', value === 'OPEN' ? undefined : value)}>
            <SelectTrigger className="h-9 w-36" aria-label="Outcome">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="OPEN">Open</SelectItem>
              <SelectItem value="LOST">Lost</SelectItem>
              <SelectItem value="ABANDONED">Abandoned</SelectItem>
              <SelectItem value="WON">Won</SelectItem>
              <SelectItem value="ALL">All outcomes</SelectItem>
            </SelectContent>
          </Select>
          <Select value={query.filters.stage_id ?? 'all'} onValueChange={(value) => query.setFilter('stage_id', value === 'all' ? undefined : value)}>
            <SelectTrigger className="h-9 w-44" aria-label="Stage">
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
          <div className="flex items-center gap-2">
            <Checkbox id="mine" checked={query.filters.mine === 'true'} onCheckedChange={(checked) => query.setFilter('mine', checked === true ? 'true' : undefined)} />
            <Label htmlFor="mine" className="text-sm">
              Mine
            </Label>
          </div>
          <div className="flex items-center gap-2">
            <Checkbox id="attention" checked={query.filters.needs_attention === 'true'} onCheckedChange={(checked) => query.setFilter('needs_attention', checked === true ? 'true' : undefined)} />
            <Label htmlFor="attention" className="text-sm">
              Needs a new owner
            </Label>
          </div>
        </div>
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
          onRowClick={(row: Row) => navigate(`/opportunities/${row.id}`)}
          isLoading={list.isLoading}
          isError={list.isError}
          error={list.error}
          onRetry={() => void list.refetch()}
          errorMessage="Couldn't load opportunities."
          emptyMessage="No opportunities match these filters."
          view={view}
          onViewChange={setView}
          cardColumns={columns}
        />
      </div>
    </ScreenTemplate>
  );
}
