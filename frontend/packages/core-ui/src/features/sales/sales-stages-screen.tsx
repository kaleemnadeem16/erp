import { useEffect, useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, Archive, EllipsisVertical, Eye, Pencil, RefreshCw, Search, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState } from '@tanstack/react-table';
import { useSalesStagesSeedSuggested } from '@erp/core-api';

import { DataTable, isColumnVisibleByDefault, selectionColumn, toTanStackColumns } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { StatusBadge } from '../../components/ui/status-badge';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { SALES, useSalesStages } from './sales-common';
import { StageBulkBar } from './stage-bulk-actions';
import { STAGE_STATUS_LABEL, useStageActions, type StageRow } from './stage-common';

interface Filters {
  search: string;
  status: string;
  [key: string]: string;
}

/**
 * The steps an open deal moves through. A stage is archived, never deleted: what was recorded about it keeps the name it had. The server returns the whole (short)
 * list in order, so searching, the status filter and paging work on what is loaded, and the position shown is the stage's place in the full order.
 */
export function SalesStagesScreen() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayManage = usePermission(SALES.stage);
  const { stages, query: stagesQuery } = useSalesStages();
  const seed = useSalesStagesSeedSuggested();
  const query = useListQueryState<Filters>({ defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const { view, setView } = useListViewPreference('sales-stages');
  const actions = useStageActions(stages, async () => {
    await stagesQuery.refetch();
  });
  const [seedError, setSeedError] = useState<string | null>(null);

  const needle = (query.filters.search ?? '').trim().toLowerCase();
  const filtered = useMemo(
    () =>
      stages.filter(
        (stage) => (!query.filters.status || stage.status === query.filters.status) && (needle === '' || stage.name.toLowerCase().includes(needle) || stage.code.toLowerCase().includes(needle)),
      ),
    [stages, query.filters.status, needle],
  );
  const pageIndex = Math.min(query.page, Math.max(1, Math.ceil(filtered.length / query.perPage))) - 1;
  const rows = filtered.slice(pageIndex * query.perPage, (pageIndex + 1) * query.perPage);
  const pagination: PaginationState = { pageIndex, pageSize: query.perPage };

  const open = (stage: StageRow) => navigate({ pathname: `/sales-stages/${stage.id}`, search });
  const columns = useMemo<DataColumn<StageRow>[]>(
    () => [
      { key: 'position', header: 'Order', value: (stage) => actions.indexOf(stage) + 1 },
      { key: 'name', header: 'Stage', value: (stage) => stage.name, cell: (stage) => <span className="ui-order-link font-medium">{stage.name}</span>, cardRole: 'title' },
      { key: 'code', header: 'Code', value: (stage) => stage.code, cardRole: 'subtitle' },
      {
        key: 'status',
        header: 'Status',
        value: (stage) => STAGE_STATUS_LABEL[stage.status] ?? stage.status,
        cell: (stage) => <StatusBadge tone={stage.status === 'ACTIVE' ? 'success' : 'draft'}>{STAGE_STATUS_LABEL[stage.status] ?? stage.status}</StatusBadge>,
        filterable: true,
      },
      { key: 'sort', header: 'Sort value', value: (stage) => stage.sort_order, screenVisible: false },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [stages],
  );
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('sales-stages', Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const tanStackColumns = useMemo(() => [selectionColumn<StageRow>(), ...toTanStackColumns(columns)], [columns]);
  const printedColumns = columns.filter((column) => columnVisibility[column.key] !== false);

  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const viewKey = JSON.stringify([query.filters, query.page, query.perPage]);
  useEffect(() => setRowSelection({}), [viewKey]);
  const selectedRows = useMemo(() => rows.filter((stage) => rowSelection[String(stage.id)]), [rows, rowSelection]);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Pipeline stages' });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({ documentTitle: 'Pipeline stages (selected)' });

  const chips: Array<{ id: string; label: string; onRemove: () => void }> = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${STAGE_STATUS_LABEL[query.filters.status] ?? query.filters.status}`, onRemove: () => query.setFilter('status', undefined) });
  const filterMeta = chips.map((chip) => ({ label: 'Filter', value: chip.label }));

  const statusSelect = (
    <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
      <SelectTrigger className="h-10 w-40" aria-label="Status">
        <SelectValue placeholder="Status" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All statuses</SelectItem>
        <SelectItem value="ACTIVE">Active</SelectItem>
        <SelectItem value="ARCHIVED">Archived</SelectItem>
      </SelectContent>
    </Select>
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="sales-stages">
        <PageHeader
          title="Pipeline stages"
          description="The steps an open deal moves through. Archiving a stage keeps what was recorded about it."
          primaryAction={mayManage ? { label: 'New stage', onClick: () => navigate({ pathname: '/sales-stages/new', search }) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 md:w-80 md:flex-none">
            <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input className="h-10 w-full pl-10" placeholder="Search stage name or code…" aria-label="Search" {...searchBox.searchProps} />
          </div>
          {statusSelect}
          <PrintTrigger onPrint={() => print()} className="hidden h-10 px-4 md:inline-flex" />
          <PrintTrigger onPrint={() => print()} label="" aria-label="Print" className="h-10 shrink-0 md:hidden" />
        </div>

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
            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground" onClick={() => query.setFilters({ search: undefined, status: undefined })}>
              Clear all
            </Button>
          </div>
        )}

        {(actions.error || seedError) && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="stage-error">
            {actions.error ?? seedError}
          </div>
        )}

        {stages.length === 0 && !stagesQuery.isLoading && !stagesQuery.isError && (
          <div className="space-y-2 rounded-md border border-dashed p-3 text-sm" data-testid="stages-empty">
            <p>No stages yet.</p>
            {mayManage && (
              <Button
                size="sm"
                variant="outline"
                data-testid="stages-seed"
                onClick={() =>
                  void seed
                    .mutateAsync()
                    .then(() => stagesQuery.refetch())
                    .catch((failure) => setSeedError(getApiErrorMessage(failure)))
                }
              >
                Start with Qualification, Proposal and Negotiation
              </Button>
            )}
          </div>
        )}

        <StageBulkBar
          selected={selectedRows}
          mayManage={mayManage}
          onPrintSelected={() => printSelected()}
          onClear={() => setRowSelection({})}
          onChanged={async () => {
            await stagesQuery.refetch();
          }}
        />

        <DataTable
          columns={tanStackColumns}
          data={rows}
          rowCount={filtered.length}
          pagination={pagination}
          onPaginationChange={(updater) => {
            const next = typeof updater === 'function' ? updater(pagination) : updater;
            if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
            else query.setPage(next.pageIndex + 1);
          }}
          sorting={[]}
          onSortingChange={() => undefined}
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          rowSelection={rowSelection}
          onRowSelectionChange={setRowSelection}
          getRowId={(stage) => String(stage.id)}
          onRowClick={open}
          isLoading={stagesQuery.isLoading}
          isError={stagesQuery.isError}
          error={stagesQuery.error}
          onRetry={() => void stagesQuery.refetch()}
          errorMessage="Couldn't load the pipeline stages."
          emptyMessage={stages.length === 0 ? 'No stages yet.' : 'No stages match these filters.'}
          view={view}
          onViewChange={setView}
          cardColumns={columns}
          recordNoun="stages"
          actionsHeader="Actions"
          numberedPagination
          rowActions={(stage: StageRow) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Actions for ${stage.name}`} onClick={(event) => event.stopPropagation()}>
                  <EllipsisVertical className="h-4 w-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem
                  onClick={(event) => {
                    event.stopPropagation();
                    open(stage);
                  }}
                >
                  <Eye className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  View details
                </DropdownMenuItem>
                {mayManage && (
                  <>
                    <DropdownMenuItem
                      onClick={(event) => {
                        event.stopPropagation();
                        navigate({ pathname: `/sales-stages/${stage.id}/edit`, search });
                      }}
                    >
                      <Pencil className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      Rename stage
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      disabled={!actions.canMoveUp(stage)}
                      onClick={(event) => {
                        event.stopPropagation();
                        void actions.moveUp(stage);
                      }}
                    >
                      <ArrowUp className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      Move up
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      disabled={!actions.canMoveDown(stage)}
                      onClick={(event) => {
                        event.stopPropagation();
                        void actions.moveDown(stage);
                      }}
                    >
                      <ArrowDown className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      Move down
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      data-testid={`stage-toggle-${stage.code}`}
                      onClick={(event) => {
                        event.stopPropagation();
                        void actions.setStatus(stage, stage.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE');
                      }}
                    >
                      {stage.status === 'ACTIVE' ? (
                        <Archive className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      ) : (
                        <RefreshCw className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      )}
                      {stage.status === 'ACTIVE' ? 'Archive stage' : 'Restore stage'}
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        />

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout title="PIPELINE STAGES" printedAt={new Date()} recordCount={filtered.length} meta={filterMeta}>
              <PrintableTable columns={printedColumns} rows={filtered} getRowKey={(stage) => stage.id} />
            </PrintLayout>
          </div>
        </div>
        <div className="hidden">
          <div ref={selectedPrintRef}>
            <PrintLayout title="PIPELINE STAGES (SELECTED)" printedAt={new Date()} recordCount={selectedRows.length} meta={[{ label: 'Selection', value: `${selectedRows.length} selected stages` }]}>
              <PrintableTable columns={printedColumns} rows={selectedRows} getRowKey={(stage) => stage.id} />
            </PrintLayout>
          </div>
        </div>
      </div>
    </ScreenTemplate>
  );
}
