import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { useJobsIndex, type JobsIndexParams } from "@erp/core-api";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import {
  ActiveFilterChips,
  DataTable,
  FilterField,
  FilterSelect,
  ListFiltersSheet,
  ListSearchInput,
  AdvancedFiltersToggle,
  AdvancedFiltersPanel,
  toTanStackColumns,
  isColumnVisibleByDefault,
} from "../../components/data-table";
import { Input } from "../../components/ui/input";
import { DateField } from "../../components/ui/date-field";
import {
  useListQueryState,
  apiSortToTableSorting,
  tableSortingToApiSort,
} from "../../lib/list-query-state";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useColumnVisibilityPreference } from "../../lib/use-column-visibility-preference";
import { useListViewPreference } from "../../lib/use-view-preference";
import { PermissionRoute } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { jobCommandDuties } from "./job-operation-contract";
import { JobOperationActions } from "./job-operation-actions";
import { jobColumns, jobStates } from "./job-columns";

const tableColumns = toTanStackColumns(jobColumns);
const emptyRows: import("@erp/core-api").JobSummaryResource[] = [];

export function JobsListScreen() {
  return (
    <PermissionRoute permission="job.view">
      <JobsListContent />
    </PermissionRoute>
  );
}
function JobsListContent() {
  const mayOperate = useAuthStore((s) =>
    jobCommandDuties.some((duty) => s.user?.permissions?.includes(duty)),
  );
  const [advanced, setAdvanced] = useState(false);
  const navigate = useNavigate();
  const { search } = useLocation();
  const q = useListQueryState<Record<string, string>>({
    defaultSort: "-created_at",
    defaultPerPage: 25,
  });
  const searchBox = useDebouncedSearch(q.filters.search, (value) =>
    q.setFilter("search", value),
  );
  const { view, setView } = useListViewPreference("jobs-list");
  const [visibility, setVisibility] = useColumnVisibilityPreference(
    "jobs-list",
    Object.fromEntries(
      jobColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]),
    ),
  );
  const result = useJobsIndex({
    page: q.page,
    per_page: q.perPage,
    sort: q.sort as JobsIndexParams["sort"],
    "filter[search]": q.filters.search || undefined,
    "filter[state]": q.filters.state as JobsIndexParams["filter[state]"],
    "filter[responsible_member_id]": q.filters.responsible_member_id
      ? Number(q.filters.responsible_member_id)
      : undefined,
    "filter[has_open_hold]": q.filters.has_open_hold
      ? q.filters.has_open_hold === "true"
      : undefined,
    "filter[created_from]": q.filters.created_from || undefined,
    "filter[created_until]": q.filters.created_until || undefined,
  });
  const data = result.data?.status === 200 ? result.data.data : undefined;
  const pagination = { pageIndex: q.page - 1, pageSize: q.perPage };
  const sorting = apiSortToTableSorting(q.sort, jobColumns);
  function changeSort(next: typeof sorting) {
    const params = new URLSearchParams(search);
    const sort = tableSortingToApiSort(next, jobColumns);
    if (sort) params.set("sort", sort);
    else params.delete("sort");
    params.set("page", "1");
    navigate({ search: params.toString() }, { replace: true });
  }
  const chips = Object.entries(q.filters)
    .filter(([, v]) => v)
    .map(([key, value]) => ({
      id: key,
      label: `${({ search: "Search", state: "Status", responsible_member_id: "Employee ID", has_open_hold: "Open hold", created_from: "Created from", created_until: "Created until" } as Record<string, string>)[key] ?? key}: ${value}`,
      onRemove: () => q.setFilter(key, undefined),
    }));
  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Jobs"
        description="Find agreed work and review its progress and history."
      />
      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput
          input={searchBox.input}
          searchProps={searchBox.searchProps}
          onClear={() => q.setFilter("search", undefined)}
          placeholder="Search number or description…"
          ariaLabel="Search Jobs by number or description"
        />
        <div className="hidden md:flex md:gap-2">
          <AdvancedFiltersToggle
            open={advanced}
            activeCount={chips.length}
            onToggle={() => setAdvanced((v) => !v)}
            controlsId="job-advanced-filters"
          />
          <FilterSelect
            label="Job status"
            allLabel="All statuses"
            value={q.filters.state}
            options={jobStates}
            onChange={(v) => q.setFilter("state", v)}
          />
        </div>
        <ListFiltersSheet activeCount={chips.length} onClear={q.clearFilters}>
          <FilterField label="Status">
            <FilterSelect
              label="Job status filter"
              allLabel="All statuses"
              value={q.filters.state}
              options={jobStates}
              onChange={(v) => q.setFilter("state", v)}
            />
          </FilterField>
          <JobFilters q={q} suffix="mobile" />
        </ListFiltersSheet>
      </div>
      <AdvancedFiltersPanel
        id="job-advanced-filters"
        open={advanced}
        onClose={() => setAdvanced(false)}
        activeCount={chips.length}
        onReset={q.clearFilters}
      >
        <JobFilters q={q} suffix="desktop" />
      </AdvancedFiltersPanel>
      <ActiveFilterChips chips={chips} onClearAll={q.clearFilters} />
      <DataTable
        columns={tableColumns}
        rowActions={
          mayOperate
            ? (row) => (
                <JobOperationActions
                  compact
                  jobId={row.id}
                  state={row.state}
                  held={row.has_open_hold}
                />
              )
            : undefined
        }
        data={data?.data ?? emptyRows}
        rowCount={data?.meta.total ?? 0}
        recordNoun="Jobs"
        pagination={pagination}
        onPaginationChange={(u) => {
          const n = typeof u === "function" ? u(pagination) : u;
          if (n.pageSize !== q.perPage) q.setPerPage(n.pageSize);
          else q.setPage(n.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(u) =>
          changeSort(typeof u === "function" ? u(sorting) : u)
        }
        columnVisibility={visibility}
        onColumnVisibilityChange={setVisibility}
        view={view}
        onViewChange={setView}
        cardColumns={jobColumns}
        getRowId={(r) => String(r.id)}
        onRowClick={(r) => navigate({ pathname: `/jobs/${r.id}`, search })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        errorMessage="Couldn’t load Jobs."
        onRetry={() => void result.refetch()}
        emptyMessage={
          chips.length
            ? "No Jobs match these filters. Clear filters to see more."
            : "No Jobs have been released yet."
        }
      />
    </ScreenTemplate>
  );
}

function JobFilters({
  q,
  suffix,
}: {
  q: ReturnType<typeof useListQueryState<Record<string, string>>>;
  suffix: string;
}) {
  return (
    <>
      <FilterField label="Open hold">
        <FilterSelect
          label="Open hold"
          allLabel="Any hold status"
          value={q.filters.has_open_hold}
          options={[
            { value: "true", label: "Has open hold" },
            { value: "false", label: "No open hold" },
          ]}
          onChange={(v) => q.setFilter("has_open_hold", v)}
        />
      </FilterField>
      <FilterField
        label="Responsible employee ID"
        htmlFor={`job-employee-${suffix}`}
      >
        <Input
          id={`job-employee-${suffix}`}
          type="number"
          min="1"
          value={q.filters.responsible_member_id ?? ""}
          onChange={(e) => q.setFilter("responsible_member_id", e.target.value)}
        />
      </FilterField>
      <FilterField label="Created from">
        <DateField
          aria-label="Created from"
          value={q.filters.created_from ?? ""}
          onChange={(v) => q.setFilter("created_from", v)}
        />
      </FilterField>
      <FilterField label="Created until">
        <DateField
          aria-label="Created until"
          value={q.filters.created_until ?? ""}
          onChange={(v) => q.setFilter("created_until", v)}
        />
      </FilterField>
    </>
  );
}
