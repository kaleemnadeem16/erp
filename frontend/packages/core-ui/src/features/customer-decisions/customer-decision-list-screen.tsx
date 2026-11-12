import { useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { usePartiesShow } from "@erp/core-api";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { PaginationState, SortingState } from "@tanstack/react-table";
import { AccessDenied } from "../../routes/permission-route";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import {
  DataTable,
  toTanStackColumns,
  type DataColumn,
} from "../../components/data-table";
import {
  ActiveFilterChips,
  FilterField,
  ListFiltersSheet,
  ListSearchInput,
  type FilterChip,
} from "../../components/data-table";
import { PartyCombobox } from "../../components/lookups/entity-comboboxes";
import { Button } from "../../components/ui/button";
import { formatDate } from "../../lib/format-date";
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { shouldRetryQuery } from "../../lib/api-errors";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useAuthStore } from "../../stores/auth-store";
import { IntentKey } from "../quotations/request-key";
import { QUOTATION_VIEW } from "../quotations/quotation-access";
import { customerDecisionAccess } from "./customer-decision-access";
import {
  type CaseListPage,
  type CaseSummary,
  type CustomerDecisionGateway,
  type OpenCaseInput,
} from "./customer-decision-contract";
import { liveCustomerDecisionGateway } from "./customer-decision-gateway";
import { OpenCaseForm } from "./customer-decision-forms";

export const customerDecisionColumns: DataColumn<CaseSummary>[] = [
  {
    key: "id",
    header: "Customer’s reference",
    sortable: true,
    cardRole: "title",
    value: (row) => row.intake_identity,
  },
  {
    key: "customer",
    header: "Customer",
    value: (row) => row.party_name ?? `Customer reference ${row.party_id}`,
  },
  {
    key: "observations",
    header: "Observations",
    value: (row) => String(row.observation_count),
  },
  {
    key: "components",
    header: "Quotations linked",
    value: (row) => String(row.component_count),
  },
  {
    key: "recorded_at",
    header: "Opened",
    value: (row) => formatDate(row.recorded_at),
  },
];

export interface CustomerDecisionListViewProps {
  rows: CaseSummary[];
  total: number;
  pagination: PaginationState;
  onPaginationChange: (next: PaginationState) => void;
  sorting: SortingState;
  onSortingChange: (next: SortingState) => void;
  search: string;
  onSearch: (search: string | undefined) => void;
  /** The customer (trading partner) id filtered to, as the URL carries it; empty for all customers. */
  partyId: string;
  /** The filtered customer's name for its chip; the id stands in until it loads. */
  partyName?: string;
  onPartyChange: (partyId: string | undefined) => void;
  onClearAll: () => void;
  isLoading: boolean;
  error: unknown;
  onRetry?: () => void;
  onOpen: (row: CaseSummary) => void;
  /** Whether the person may open a case; the action is hidden otherwise. */
  mayRecord: boolean;
  onRecord: () => void;
}

/** Presentational list: no data fetching, so it renders the same for tests, the preview and the real screen. */
export function CustomerDecisionListView(props: CustomerDecisionListViewProps) {
  const columns = useMemo(() => toTanStackColumns(customerDecisionColumns), []);
  const failed = props.error !== undefined && props.error !== null;
  const searchBox = useDebouncedSearch(props.search || undefined, (value) =>
    props.onSearch(value),
  );
  const partyFilter = (id: string, className: string) => (
    <PartyCombobox
      id={id}
      label="Customer"
      allLabel="All customers"
      placeholder="All customers"
      className={className}
      value={props.partyId || "all"}
      onValueChange={(value) =>
        props.onPartyChange(value === "all" ? undefined : value)
      }
    />
  );
  const chips: FilterChip[] = [];
  if (props.search)
    chips.push({
      id: "search",
      label: `Search: "${props.search}"`,
      onRemove: () => props.onSearch(undefined),
    });
  if (props.partyId)
    chips.push({
      id: "party",
      label: `Customer: ${props.partyName ?? `#${props.partyId}`}`,
      onRemove: () => props.onPartyChange(undefined),
    });
  return (
    <ScreenTemplate kind="list" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Customer messages"
        description="What customers have sent about our quotations. Recording a message is not accepting an offer."
        actions={
          props.mayRecord ? (
            <Button className="min-h-11" type="button" onClick={props.onRecord}>
              Record a customer message
            </Button>
          ) : undefined
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput
          input={searchBox.input}
          searchProps={searchBox.searchProps}
          onClear={() => props.onSearch(undefined)}
          placeholder="Search by reference…"
          ariaLabel="Search by reference"
        />
        <div className="hidden items-center gap-2 md:flex">
          {partyFilter("decision-party-filter", "h-10 w-64")}
        </div>
        <ListFiltersSheet
          activeCount={props.partyId ? 1 : 0}
          onClear={props.onClearAll}
        >
          <FilterField label="Customer" htmlFor="decision-party-filter-sheet">
            {partyFilter("decision-party-filter-sheet", "h-11 w-full")}
          </FilterField>
        </ListFiltersSheet>
      </div>
      <ActiveFilterChips chips={chips} onClearAll={props.onClearAll} />
      <DataTable
        columns={columns}
        data={props.rows}
        rowCount={props.total}
        pagination={props.pagination}
        onPaginationChange={(updater) =>
          props.onPaginationChange(
            typeof updater === "function" ? updater(props.pagination) : updater,
          )
        }
        sorting={props.sorting}
        onSortingChange={(updater) =>
          props.onSortingChange(
            typeof updater === "function" ? updater(props.sorting) : updater,
          )
        }
        getRowId={(row) => String(row.id)}
        onRowClick={props.onOpen}
        isLoading={props.isLoading}
        isError={failed}
        error={props.error}
        errorMessage="Couldn’t load customer messages."
        onRetry={props.onRetry}
        emptyMessage={
          chips.length > 0
            ? "No customer messages match these filters."
            : "No customer messages recorded yet."
        }
        recordNoun="customer messages"
        scrollRegionLabel="Customer messages"
        paginationLabel="Customer messages pages"
      />
    </ScreenTemplate>
  );
}

/** The gated list screen. The gate is checked before any query mounts, so a denied person (and every production build) issues no request. */
export function CustomerDecisionListScreen({
  gateway = liveCustomerDecisionGateway,
}: {
  gateway?: CustomerDecisionGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  const access = customerDecisionAccess(permissions);
  if (!access.view) return <AccessDenied />;
  return (
    <GatedList
      gateway={gateway}
      mayRecord={access.record}
      mayLinkQuotations={permissions.has(QUOTATION_VIEW)}
    />
  );
}

function GatedList({
  gateway,
  mayRecord,
  mayLinkQuotations,
}: {
  gateway: CustomerDecisionGateway;
  mayRecord: boolean;
  mayLinkQuotations: boolean;
}) {
  const navigate = useNavigate();
  const client = useQueryClient();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<{ search: string; party_id: string }>({
    defaultSort: "-id",
    defaultPerPage: 25,
  });
  const sort = query.sort === "id" ? "id" : "-id";
  const search = query.filters.search ?? "";
  const partyId = /^[1-9]\d*$/.test(query.filters.party_id ?? "")
    ? (query.filters.party_id as string)
    : "";
  // The chip names the customer by its own id, not by whatever first page the picker loaded.
  const partyQuery = usePartiesShow(Number(partyId), undefined, {
    query: { enabled: partyId !== "" },
  });
  const partyName =
    partyQuery.data?.status === 200
      ? partyQuery.data.data.data.name
      : undefined;
  const perPage = [10, 25, 50, 100].includes(query.perPage)
    ? query.perPage
    : 25;
  const params = {
    search: search || undefined,
    partyId: partyId ? Number(partyId) : undefined,
    sort,
    page: query.page,
    perPage,
  } as const;
  const result = useQuery<CaseListPage>({
    queryKey: ["customer-decision-cases", params],
    queryFn: () => gateway.list(params),
    retry: shouldRetryQuery,
    placeholderData: (previous) => previous,
  });
  const [recording, setRecording] = useState(false);
  // One key per exact intent: an uncertain failure keeps it so a retry replays instead of repeating; success or a definitive refusal ends it.
  const openKey = useRef(new IntentKey());
  const open = useMutation({
    mutationFn: (body: OpenCaseInput) =>
      gateway.open({ body, requestKey: openKey.current.for(body) }),
    onSuccess: (outcome) => {
      openKey.current.settle();
      setRecording(false);
      void client.invalidateQueries({ queryKey: ["customer-decision-cases"] });
      void navigate({
        pathname: `/customer-decisions/${outcome.case_id}`,
        search: listSearch,
      });
    },
    onError: (e) => openKey.current.settle(e),
  });
  const columns = customerDecisionColumns;
  return (
    <>
      <CustomerDecisionListView
        rows={result.data?.data ?? []}
        total={result.data?.meta.total ?? 0}
        pagination={{ pageIndex: query.page - 1, pageSize: perPage }}
        onPaginationChange={(next) => {
          if (next.pageSize !== perPage) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={apiSortToTableSorting(sort, columns)}
        onSortingChange={(next) =>
          query.setSort(tableSortingToApiSort(next, columns))
        }
        search={search}
        onSearch={(value) => query.setFilter("search", value || undefined)}
        partyId={partyId}
        partyName={partyName}
        onPartyChange={(value) => query.setFilter("party_id", value)}
        onClearAll={() =>
          query.setFilters({ search: undefined, party_id: undefined })
        }
        isLoading={result.isPending}
        error={result.isError ? result.error : null}
        onRetry={() => void result.refetch()}
        onOpen={(row) =>
          void navigate({
            pathname: `/customer-decisions/${row.id}`,
            search: listSearch,
          })
        }
        mayRecord={mayRecord}
        onRecord={() => {
          open.reset();
          setRecording(true);
        }}
      />
      {mayRecord && recording && (
        <RecordSheet
          title="Record a customer message"
          description="Open a case for what a customer sent."
          size="md"
          dismissOnOutsideClick={false}
          onClose={() => setRecording(false)}
        >
          <OpenCaseForm
            gateway={gateway}
            mayLinkQuotations={mayLinkQuotations}
            onSubmit={(body) => open.mutate(body)}
            onCancel={() => setRecording(false)}
            pending={open.isPending}
            error={open.error}
          />
        </RecordSheet>
      )}
    </>
  );
}
