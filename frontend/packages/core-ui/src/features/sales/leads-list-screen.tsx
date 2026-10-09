import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useLeadsIndex, type LeadResource, type LeadsIndexSort } from '@erp/core-api';

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
import { LEAD_LABEL, LeadStatusBadge, SALES } from './sales-common';

interface Filters {
  search: string;
  status: string;
  unassigned: string;
  [key: string]: string;
}

const COLUMNS: DataColumn<LeadResource>[] = [
  { key: 'number', header: 'Number', value: (row) => row.number ?? `#${row.id}`, sortable: true },
  { key: 'enquirer', header: 'Enquiry from', value: (row) => row.enquirer_name, cardRole: 'title' },
  { key: 'organisation', header: 'Organisation', value: (row) => row.enquirer_organisation, cardRole: 'subtitle' },
  { key: 'source', header: 'Source', value: (row) => row.source },
  { key: 'assigned', header: 'Assigned to', value: (row) => row.assigned_name ?? null },
  { key: 'received', header: 'Received', value: (row) => formatDate(row.received_at), sortable: true, sortKey: 'received_at' },
  { key: 'status', header: 'Status', value: (row) => LEAD_LABEL[row.status] ?? row.status, cell: (row) => <LeadStatusBadge status={row.status} /> },
];

/** Optional enquiries: nothing requires one before a deal. Newest first. */
export function LeadsListScreen() {
  const navigate = useNavigate();
  const mayCreate = usePermission(SALES.lead);
  const query = useListQueryState<Filters>({ defaultSort: '-received_at', defaultPerPage: 25 });
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const { view, setView } = useListViewPreference('leads-list');
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('leads-list', Object.fromEntries(COLUMNS.map((column) => [column.key, isColumnVisibleByDefault(column)])));

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const list = useLeadsIndex({
    'filter[status]': (query.filters.status || undefined) as 'NEW' | undefined,
    'filter[search]': query.filters.search || undefined,
    'filter[unassigned]': query.filters.unassigned === 'true' ? true : undefined,
    sort: query.sort as LeadsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const meta = list.data?.status === 200 ? list.data.data.meta : undefined;
  const columns = useMemo(() => toTanStackColumns(COLUMNS), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, COLUMNS);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="leads-list">
        <PageHeader
          title="Enquiries"
          description="Optional intake. Qualify an enquiry and turn it into one or more opportunities — or start a deal directly."
          primaryAction={mayCreate ? { label: 'Record an enquiry', onClick: () => navigate('/leads/new') } : undefined}
        />
        <div className="flex flex-wrap items-center gap-2">
          <Input placeholder="Search name, organisation or number…" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} className="h-9 min-w-0 flex-1 md:w-64 md:flex-none" aria-label="Search" />
          <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
            <SelectTrigger className="h-9 w-40" aria-label="Status">
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
          <div className="flex items-center gap-2">
            <Checkbox id="unassigned" checked={query.filters.unassigned === 'true'} onCheckedChange={(checked) => query.setFilter('unassigned', checked === true ? 'true' : undefined)} />
            <Label htmlFor="unassigned" className="text-sm">
              Unassigned
            </Label>
          </div>
        </div>
        <DataTable
          columns={columns}
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
            query.setSort(tableSortingToApiSort(next, COLUMNS));
          }}
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          onRowClick={(row: LeadResource) => navigate(`/leads/${row.id}`)}
          isLoading={list.isLoading}
          isError={list.isError}
          error={list.error}
          onRetry={() => void list.refetch()}
          errorMessage="Couldn't load enquiries."
          emptyMessage="No enquiries match these filters."
          view={view}
          onViewChange={setView}
          cardColumns={COLUMNS}
        />
      </div>
    </ScreenTemplate>
  );
}
