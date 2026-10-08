import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useWarehousesIndex } from '@erp/core-api';

import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { StatusBadge } from '../../components/ui/status-badge';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { usePermission } from '../../hooks/use-permission';
import { EXIT_CONFIG, useExitIndex, type ExitDocument, type ExitKind } from './rejected-exit-api';
import { EXIT_STATUS_LABEL, EXIT_STATUS_TONE } from './rejected-exit-detail-screen';

type Row = ExitDocument & { warehouse?: { name: string } };

interface Filters {
  search: string;
  warehouse_id: string;
  status: string;
  [key: string]: string;
}

const COLUMNS: DataColumn<Row>[] = [
  { key: 'number', header: 'Number', value: (row) => row.number ?? `Draft #${row.id}`, cardRole: 'title', sortable: true },
  { key: 'warehouse', header: 'Warehouse', value: (row) => row.warehouse?.name ?? null, cardRole: 'subtitle' },
  { key: 'reason', header: 'Reason', value: (row) => row.reason },
  {
    key: 'status',
    header: 'Status',
    value: (row) => EXIT_STATUS_LABEL[row.status] ?? row.status,
    cell: (row) => <StatusBadge tone={EXIT_STATUS_TONE[row.status] ?? 'draft'}>{EXIT_STATUS_LABEL[row.status] ?? row.status}</StatusBadge>,
  },
  { key: 'created_at', header: 'Created', value: (row) => formatDateTime(row.created_at), sortable: true },
  { key: 'posted_at', header: 'Posted', value: (row) => formatDateTime(row.posted_at), sortable: true, screenVisible: false },
];

export function ExitListScreen({ kind }: { kind: ExitKind }) {
  const config = EXIT_CONFIG[kind];
  const navigate = useNavigate();
  const mayCreate = usePermission(config.createPermission);
  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    `${config.basePath.slice(1)}-list`,
    Object.fromEntries(COLUMNS.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference(`${config.basePath.slice(1)}-list`);
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];
  const list = useExitIndex(kind, {
    warehouseId: query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    status: query.filters.status,
    search: query.filters.search,
    sort: query.sort,
    perPage: query.perPage,
    page: query.page,
  });

  const columns = useMemo(() => toTanStackColumns(COLUMNS), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, COLUMNS);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="exit-list">
        <PageHeader
          title={config.plural}
          description={
            kind === 'SUPPLIER_RETURN'
              ? 'Rejected stock sent back to the supplier. Start one from a delivery’s rejected stock.'
              : 'Rejected stock written off. Start one from a delivery’s rejected stock.'
          }
          primaryAction={mayCreate ? { label: `New ${config.noun}`, onClick: () => navigate(`${config.basePath}/new`) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <Input placeholder="Search number or reason…" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} className="h-9 min-w-0 flex-1 md:w-56 md:flex-none" />
          <Combobox
            {...lookupState(warehousesQuery, 'warehouses')}
            className="h-9 w-44"
            aria-label="Warehouse"
            value={query.filters.warehouse_id ?? 'all'}
            onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
            placeholder="Warehouse"
            searchPlaceholder="Search warehouses…"
            emptyText="No warehouses found."
            options={[{ value: 'all', label: 'All warehouses' }, ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))]}
          />
          <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
            <SelectTrigger className="h-9 w-36" aria-label="Status">
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {Object.entries(EXIT_STATUS_LABEL).map(([value, label]) => (
                <SelectItem key={value} value={value}>{label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <DataTable
          columns={columns}
          data={list.rows as Row[]}
          rowCount={list.total}
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
          onRowClick={(row: Row) => navigate(`${config.basePath}/${row.id}`)}
          isLoading={list.query.isLoading}
          isError={list.query.isError}
          error={list.query.error}
          onRetry={() => void list.query.refetch()}
          errorMessage={`Couldn't load ${config.plural.toLowerCase()}.`}
          emptyMessage={`No ${config.plural.toLowerCase()} match these filters.`}
          view={view}
          onViewChange={setView}
          cardColumns={COLUMNS}
        />
      </div>
    </ScreenTemplate>
  );
}
