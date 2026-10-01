import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { SlidersHorizontal } from 'lucide-react';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useAssembliesIndex,
  useAssemblyGroupsIndex,
  type AssemblyResource,
  type AssembliesIndexSort,
  type AssembliesIndexFilterStatus,
} from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { lookupState, type LookupState } from '../../lib/api-errors';
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
  type ListQueryState,
} from '../../lib/list-query-state';
import { useListViewPreference } from '../../lib/use-view-preference';
import { usePermission } from '../../hooks/use-permission';
import { assemblyColumns } from './assemblies-columns';

interface Filters {
  search: string;
  status: string;
  assembly_group_id: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'ACTIVE', 'ARCHIVED'] as const;

export function AssembliesListScreen() {
  const navigate = useNavigate();
  const mayManage = usePermission('estimating.assembly.manage');
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('assemblies-list');
  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const groupsQuery = useAssemblyGroupsIndex({ per_page: 100, sort: 'code' });
  const groups = groupsQuery.data?.status === 200 ? groupsQuery.data.data.data : [];
  const result = useAssembliesIndex({
    'filter[search]': query.filters.search || undefined,
    'filter[assembly_group_id]': query.filters.assembly_group_id ? Number(query.filters.assembly_group_id) : undefined,
    'filter[status]': (query.filters.status as AssembliesIndexFilterStatus | undefined) || undefined,
    include: 'assemblyGroup',
    sort: query.sort as AssembliesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(assemblyColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, assemblyColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };
  const activeFilterCount = [query.filters.status, query.filters.assembly_group_id].filter(Boolean).length;

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Assemblies"
        description="Sellable units of work, priced per work unit — the recipe and cost roll-up land in a later step."
        primaryAction={
          mayManage
            ? {
                label: 'New assembly',
                onClick: () => navigate('/assemblies/new'),
              }
            : undefined
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label="Search assemblies"
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <AssemblyFilters query={query} groups={groups} groupsLookup={lookupState(groupsQuery, 'assembly groups')} />
        </div>
        <Button variant="outline" size="sm" className="h-9 shrink-0 md:hidden" onClick={() => setFiltersOpen(true)}>
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1">{activeFilterCount}</Badge>}
        </Button>
      </div>
      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <AssemblyFilters
              query={query}
              groups={groups}
              groupsLookup={lookupState(groupsQuery, 'assembly groups')}
              full
            />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() =>
                query.setFilters({
                  status: undefined,
                  assembly_group_id: undefined,
                })
              }
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
      <DataTable
        columns={columns}
        data={rows}
        rowCount={total}
        pagination={pagination}
        onPaginationChange={(u) => {
          const n = typeof u === 'function' ? u(pagination) : u;
          n.pageSize !== pagination.pageSize ? query.setPerPage(n.pageSize) : query.setPage(n.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(u) =>
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, assemblyColumns))
        }
        onRowClick={mayManage ? (row: AssemblyResource) => navigate(`/assemblies/${row.id}/edit`) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load assemblies."
        emptyMessage="No assemblies yet."
        view={view}
        onViewChange={setView}
        cardColumns={assemblyColumns}
      />
    </div>
  );
}

function AssemblyFilters({
  query,
  groups,
  groupsLookup,
  full = false,
}: {
  query: ListQueryState<Filters>;
  groups: { id: number; code: string; name: string }[];
  groupsLookup: LookupState;
  full?: boolean;
}) {
  return (
    <>
      <Combobox
        {...groupsLookup}
        aria-label="Assembly group"
        className={full ? 'w-full' : 'w-48'}
        value={query.filters.assembly_group_id ?? 'all'}
        onValueChange={(value) => query.setFilter('assembly_group_id', value === 'all' ? undefined : value)}
        placeholder="Assembly group"
        searchPlaceholder="Search groups…"
        emptyText="No groups found."
        options={[
          { value: 'all', label: 'All groups' },
          ...groups.map((group) => ({
            value: String(group.id),
            label: `${group.code} — ${group.name}`,
          })),
        ]}
      />
      <Select
        value={query.filters.status ?? 'all'}
        onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}
      >
        <SelectTrigger aria-label="Status" className={full ? 'h-9 w-full' : 'h-9 w-36'}>
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          {STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {status}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </>
  );
}
