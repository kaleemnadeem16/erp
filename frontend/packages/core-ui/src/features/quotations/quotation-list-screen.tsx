import { useMemo } from "react";
import { Link, useSearchParams } from "react-router";
import { useQuery } from "@tanstack/react-query";
import type { PaginationState, SortingState } from "@tanstack/react-table";
import { AccessDenied } from "../../routes/permission-route";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import {
  DataTable,
  toTanStackColumns,
  type DataColumn,
} from "../../components/data-table";
import { FilterToggle } from "../../components/ui/filter-toggle";
import { Label } from "../../components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { formatDate } from "../../lib/format-date";
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { shouldRetryQuery } from "../../lib/api-errors";
import { useAuthStore } from "../../stores/auth-store";
import { quotationMilestoneAccess } from "./quotation-milestone-access";
import { QuotationStatus } from "./quotation-display";
import {
  fetchQuotationList,
  isListSort,
  isListState,
  type QuotationListGateway,
  type QuotationListPage,
  type QuotationListParams,
  type QuotationListState,
  type QuotationSummary,
} from "./quotation-list-contract";
import { QuotationListPanel } from "./quotation-list-panel";

const ALL = "ALL";
const STATE_LABELS: Record<QuotationListState, string> = {
  DRAFT: "Draft",
  CANCELLED_DRAFT: "Cancelled draft",
  ISSUED: "Issued",
};

/**
 * Columns come only from the safe summary the list contract provides. Party name and net
 * amount are intentionally absent until the contract carries a safe field for them.
 */
export const quotationListColumns: DataColumn<QuotationSummary>[] = [
  {
    key: "id",
    header: "Quotation",
    sortable: true,
    cardRole: "title",
    value: (row) =>
      `Quotation ${row.revision_id} · Revision ${row.revision_ordinal}`,
  },
  {
    key: "recipient",
    header: "Recipient",
    // The contract returns a party reference only; a name lookup is contract pending.
    value: (row) =>
      row.recipient_party_id === null
        ? "Not specified"
        : `Party reference ${row.recipient_party_id}`,
  },
  {
    key: "state",
    header: "Status",
    sortable: true,
    cell: (row) => <QuotationStatus state={row.state} />,
    value: (row) => row.state,
  },
  {
    key: "valid_through",
    header: "Valid through",
    value: (row) => formatDate(row.valid_through_local_date),
  },
  {
    key: "created_at",
    header: "Created",
    sortable: true,
    value: (row) => formatDate(row.created_at),
  },
];

export function quotationListColumnDefs() {
  return toTanStackColumns(quotationListColumns);
}

export interface QuotationListViewProps {
  rows: QuotationSummary[];
  total: number;
  pagination: PaginationState;
  onPaginationChange: (next: PaginationState) => void;
  sorting: SortingState;
  onSortingChange: (next: SortingState) => void;
  state: QuotationListState | undefined;
  onStateChange: (state: QuotationListState | undefined) => void;
  mine: boolean;
  onMineChange: (mine: boolean) => void;
  isLoading: boolean;
  error: unknown;
  onRetry?: () => void;
  onOpen: (row: QuotationSummary) => void;
  /** Whether the person may create drafts; the action is hidden otherwise. */
  mayCreate: boolean;
}

/** Presentational list: no data fetching, so it renders the same for tests, the preview and the real screen. */
export function QuotationListView(props: QuotationListViewProps) {
  const columns = useMemo(() => quotationListColumnDefs(), []);
  const failed = props.error !== undefined && props.error !== null;
  return (
    <ScreenTemplate kind="list" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Quotations"
        description="Draft and issued quotations you can see."
        actions={
          props.mayCreate ? (
            <Link
              className="ui-link-action inline-flex min-h-11 items-center"
              to="/quotations/new"
            >
              Create draft
            </Link>
          ) : undefined
        }
      />
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-0 space-y-1">
          <Label htmlFor="quotation-filter-state">Status</Label>
          <Select
            value={props.state ?? ALL}
            onValueChange={(value) =>
              props.onStateChange(isListState(value) ? value : undefined)
            }
          >
            <SelectTrigger id="quotation-filter-state" className="w-48">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All statuses</SelectItem>
              {(Object.keys(STATE_LABELS) as QuotationListState[]).map((s) => (
                <SelectItem key={s} value={s}>
                  {STATE_LABELS[s]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <FilterToggle pressed={props.mine} onPressedChange={props.onMineChange}>
          Mine
        </FilterToggle>
      </div>
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
        getRowId={(row) => String(row.revision_id)}
        onRowClick={props.onOpen}
        isLoading={props.isLoading}
        isError={failed}
        error={props.error}
        errorMessage="Couldn’t load quotations."
        onRetry={props.onRetry}
        emptyMessage={
          props.state || props.mine
            ? "No quotations match these filters."
            : "No quotations yet."
        }
        recordNoun="quotations"
        scrollRegionLabel="Quotations"
        paginationLabel="Quotations pages"
      />
    </ScreenTemplate>
  );
}

/**
 * The gated list screen. Not registered in the router in Phase 1. The gate is checked before
 * any query mounts, so a denied person (and every production build) issues no request.
 */
export function QuotationListScreen({
  gateway = fetchQuotationList,
}: {
  gateway?: QuotationListGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  const access = quotationMilestoneAccess(permissions);
  if (!access.list) return <AccessDenied />;
  return <GatedQuotationList gateway={gateway} mayCreate={access.create} />;
}

function GatedQuotationList({
  gateway,
  mayCreate,
}: {
  gateway: QuotationListGateway;
  mayCreate: boolean;
}) {
  const query = useListQueryState<{ state: string; mine: string }>({
    defaultSort: "-id",
    defaultPerPage: 25,
  });
  const [search, setSearch] = useSearchParams();
  const sort = isListSort(query.sort) ? query.sort : "-id";
  const state = isListState(query.filters.state)
    ? query.filters.state
    : undefined;
  const mine = query.filters.mine === "1";
  const perPage = [10, 25, 50, 100].includes(query.perPage)
    ? query.perPage
    : 25;
  const params: QuotationListParams = {
    state,
    mine,
    sort,
    page: query.page,
    perPage,
  };
  const result = useQuery<QuotationListPage>({
    queryKey: ["quotation-list", params],
    queryFn: () => gateway(params),
    retry: shouldRetryQuery,
    placeholderData: (previous) => previous,
  });
  const columns = quotationListColumns;
  const openParam = search.get("open");
  const openId =
    openParam &&
    /^[1-9]\d*$/.test(openParam) &&
    Number.isSafeInteger(Number(openParam))
      ? Number(openParam)
      : null;
  function setOpen(id: number | null) {
    setSearch(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (id === null) next.delete("open");
        else next.set("open", String(id));
        return next;
      },
      { replace: true },
    );
  }
  return (
    <>
      <QuotationListView
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
        state={state}
        onStateChange={(value) => query.setFilter("state", value)}
        mine={mine}
        onMineChange={(value) =>
          query.setFilter("mine", value ? "1" : undefined)
        }
        isLoading={result.isPending}
        error={result.isError ? result.error : null}
        onRetry={() => void result.refetch()}
        onOpen={(row) => setOpen(row.revision_id)}
        mayCreate={mayCreate}
      />
      {openId !== null && (
        <QuotationListPanel revisionId={openId} onClose={() => setOpen(null)} />
      )}
    </>
  );
}
