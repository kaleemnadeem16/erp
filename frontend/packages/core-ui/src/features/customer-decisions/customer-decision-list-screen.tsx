import { useMemo, useRef, useState, type FormEvent } from "react";
import { useNavigate } from "react-router";
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
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { formatDate } from "../../lib/format-date";
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { shouldRetryQuery } from "../../lib/api-errors";
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
  onSearch: (search: string) => void;
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
  const [text, setText] = useState(props.search);
  const failed = props.error !== undefined && props.error !== null;
  function submit(event: FormEvent) {
    event.preventDefault();
    props.onSearch(text.trim());
  }
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
      <form className="flex flex-wrap items-end gap-3" onSubmit={submit}>
        <div className="min-w-0 space-y-1">
          <Label htmlFor="decision-search">Search by reference</Label>
          <Input
            id="decision-search"
            className="min-h-11 w-64 max-w-full"
            maxLength={120}
            value={text}
            onChange={(e) => setText(e.target.value)}
          />
        </div>
        <Button className="min-h-11" type="submit" variant="outline">
          Search
        </Button>
        {props.search && (
          <Button
            className="min-h-11"
            type="button"
            variant="ghost"
            onClick={() => {
              setText("");
              props.onSearch("");
            }}
          >
            Clear search
          </Button>
        )}
      </form>
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
          props.search
            ? "No customer messages match that reference."
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
  const query = useListQueryState<{ search: string }>({
    defaultSort: "-id",
    defaultPerPage: 25,
  });
  const sort = query.sort === "id" ? "id" : "-id";
  const search = query.filters.search ?? "";
  const perPage = [10, 25, 50, 100].includes(query.perPage)
    ? query.perPage
    : 25;
  const params = {
    search: search || undefined,
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
      void navigate(`/customer-decisions/${outcome.case_id}`);
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
        isLoading={result.isPending}
        error={result.isError ? result.error : null}
        onRetry={() => void result.refetch()}
        onOpen={(row) => void navigate(`/customer-decisions/${row.id}`)}
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
