import { useMemo, useState } from "react";
import { Link } from "react-router";
import type {
  OnChangeFn,
  PaginationState,
  SortingState,
} from "@tanstack/react-table";
import type { DataColumn } from "../../components/data-table";
import { DataTable, toTanStackColumns } from "../../components/data-table";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { AccessDenied } from "../../routes/permission-route";
import { usePermission } from "../../hooks/use-permission";
import { formatDate } from "../../lib/format-date";
import type { ListView } from "../../lib/use-view-preference";
import type { QuotationListSummary } from "./quotation-list-contract";

/** Deferred screen: no route, navigation entry, live transport or generated list endpoint exists yet. */
export const QUOTATION_LIST_PREVIEW_ENABLED = false;
type Props = {
  rows: QuotationListSummary[];
  total: number;
  pagination: PaginationState;
  onPaginationChange: OnChangeFn<PaginationState>;
  sorting: SortingState;
  onSortingChange: OnChangeFn<SortingState>;
  isLoading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  mayReview: boolean;
  stateFilter: "" | "DRAFT" | "CANCELLED_DRAFT";
  onStateFilterChange: (value: "" | "DRAFT" | "CANCELLED_DRAFT") => void;
};
export function QuotationListScreen(props: Props) {
  const mayView = usePermission("sales.quotation.view");
  return QUOTATION_LIST_PREVIEW_ENABLED && mayView ? (
    <QuotationListPresentation {...props} />
  ) : (
    <AccessDenied />
  );
}

/** Mock-fixture presentation only. Counts and ordering come from the future authorised server, never local filtering. */
export function QuotationListPresentation({
  rows,
  total,
  pagination,
  onPaginationChange,
  sorting,
  onSortingChange,
  isLoading,
  error,
  onRetry,
  mayReview,
  stateFilter,
  onStateFilterChange,
}: Props) {
  const [view, setView] = useState<ListView>("table");
  const columns = useMemo<DataColumn<QuotationListSummary>[]>(
    () => [
      {
        key: "revision",
        header: "Quotation",
        value: (row) => `Revision #${row.revision_id}`,
        cardRole: "title",
        cell: (row) => (
          <Link
            className="ui-order-link font-medium"
            to={`/quotations/${row.revision_id}`}
          >
            Revision #{row.revision_id}
          </Link>
        ),
      },
      {
        key: "state",
        header: "Status",
        value: (row) => (row.state === "DRAFT" ? "Draft" : "Cancelled draft"),
        cardRole: "subtitle",
      },
      {
        key: "recipient",
        header: "Recipient",
        value: (row) =>
          row.recipient_party_id
            ? `Party reference #${row.recipient_party_id}`
            : "Not set",
      },
      {
        key: "currency",
        header: "Currency",
        value: (row) => `Currency reference #${row.currency_id}`,
      },
      {
        key: "pricing_date",
        header: "Pricing date",
        value: (row) => formatDate(row.pricing_date),
        sortable: true,
      },
      {
        key: "valid_through_local_date",
        header: "Valid through",
        value: (row) => formatDate(row.valid_through_local_date),
        sortable: true,
      },
      { key: "line_count", header: "Lines", value: (row) => row.line_count },
    ],
    [],
  );
  const tableColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  return (
    <ScreenTemplate kind="list" className="erp-screen space-y-4">
      <PageHeader
        title="Quotations"
        description="Quotation drafts available to you."
      />
      <div className="flex flex-wrap items-center gap-3">
        <label htmlFor="quotation-state-filter" className="text-sm font-medium">
          Status
        </label>
        <select
          id="quotation-state-filter"
          className="h-10 rounded-md border bg-background px-3"
          value={stateFilter}
          onChange={(event) =>
            onStateFilterChange(event.target.value as Props["stateFilter"])
          }
        >
          <option value="">All statuses</option>
          <option value="DRAFT">Draft</option>
          <option value="CANCELLED_DRAFT">Cancelled draft</option>
        </select>
      </div>
      <DataTable
        columns={tableColumns}
        data={rows}
        rowCount={total}
        pagination={pagination}
        onPaginationChange={onPaginationChange}
        sorting={sorting}
        onSortingChange={onSortingChange}
        getRowId={(row) => String(row.revision_id)}
        isLoading={isLoading}
        isError={!!error}
        error={error}
        onRetry={onRetry}
        errorMessage="Couldn't load quotations."
        emptyMessage="No quotations match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={columns}
        recordNoun="quotations"
        numberedPagination
        rowActions={
          mayReview
            ? (row) => (
                <Link
                  className="ui-link-action"
                  to={`/quotations/${row.revision_id}/review`}
                >
                  Review
                </Link>
              )
            : undefined
        }
      />
    </ScreenTemplate>
  );
}
