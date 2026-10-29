import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { Eye } from "lucide-react";
import type {
  OnChangeFn,
  PaginationState,
  SortingState,
} from "@tanstack/react-table";
import type { DataColumn } from "../../components/data-table";
import { DataTable, toTanStackColumns } from "../../components/data-table";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Button } from "../../components/ui/button";
import { StatusBadge } from "../../components/ui/status-badge";
import { AccessDenied } from "../../routes/permission-route";
import { usePermission } from "../../hooks/use-permission";
import { formatDate } from "../../lib/format-date";
import type { ListView } from "../../lib/use-view-preference";
import type { QuotationListSummary } from "./quotation-list-contract";

/** No route or live transport until the authorised generated list contract lands. */
export const QUOTATION_LIST_PREVIEW_ENABLED = false;
export type ReferenceChoice = { value: string; label: string };
export type QuotationListFilters = {
  state: string;
  recipient: string;
  currency: string;
  owner: string;
};
type Props = {
  rows: QuotationListSummary[];
  total: number;
  pagination: PaginationState;
  onPaginationChange: OnChangeFn<PaginationState>;
  sorting: SortingState;
  onSortingChange: OnChangeFn<SortingState>;
  onFiltersChange: (filters: QuotationListFilters) => void;
  recipients: ReferenceChoice[];
  currencies: ReferenceChoice[];
  owners: ReferenceChoice[];
  ownerByRevision: Readonly<Record<number, string>>;
  isLoading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  mayReview: boolean;
  mayCreate: boolean;
};
export function QuotationListScreen(props: Props) {
  const mayView = usePermission("sales.quotation.view");
  const mayPrepare = usePermission("sales.quotation.prepare");
  return QUOTATION_LIST_PREVIEW_ENABLED && mayView ? (
    <QuotationListPresentation
      {...props}
      mayReview={mayPrepare}
      mayCreate={mayPrepare}
    />
  ) : (
    <AccessDenied />
  );
}
const statusLabels: Record<QuotationListSummary["state"], string> = {
  DRAFT: "Draft",
  CANCELLED_DRAFT: "Cancelled draft",
  ISSUED: "Issued",
};
/** Fixture-only presentation: list visibility, counts, sorting and filtering remain server-owned. */
export function QuotationListPresentation({
  rows,
  total,
  pagination,
  onPaginationChange,
  sorting,
  onSortingChange,
  onFiltersChange,
  recipients,
  currencies,
  owners,
  ownerByRevision,
  isLoading,
  error,
  onRetry,
  mayReview,
  mayCreate,
}: Props) {
  const [view, setView] = useState<ListView>("table");
  const [search, setSearch] = useSearchParams();
  const filters: QuotationListFilters = {
    state: search.get("state") ?? "",
    recipient: search.get("recipient") ?? "",
    currency: search.get("currency") ?? "",
    owner: search.get("owner") ?? "",
  };
  function setFilter(key: keyof QuotationListFilters, value: string) {
    const next = { ...filters, [key]: value };
    const params = new URLSearchParams(search);
    if (value) params.set(key, value);
    else params.delete(key);
    params.delete("page");
    setSearch(params);
    onPaginationChange({ ...pagination, pageIndex: 0 });
    onFiltersChange(next);
  }
  function updateSorting(updater: Parameters<OnChangeFn<SortingState>>[0]) {
    const next = typeof updater === "function" ? updater(sorting) : updater;
    const params = new URLSearchParams(search);
    if (next.length)
      params.set("sort", `${next[0]!.desc ? "-" : ""}${next[0]!.id}`);
    else params.delete("sort");
    params.delete("page");
    setSearch(params);
    onPaginationChange({ ...pagination, pageIndex: 0 });
    onSortingChange(next);
  }
  function updatePage(updater: Parameters<OnChangeFn<PaginationState>>[0]) {
    const next = typeof updater === "function" ? updater(pagination) : updater;
    const params = new URLSearchParams(search);
    params.set("page", String(next.pageIndex + 1));
    params.set("per_page", String(next.pageSize));
    setSearch(params);
    onPaginationChange(next);
  }
  const labels = (options: ReferenceChoice[], value: number | null) =>
    options.find((item) => item.value === String(value))?.label;
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
            to={`/quotations/${row.revision_id}${search.toString() ? `?${search}` : ""}`}
          >
            Revision #{row.revision_id}
          </Link>
        ),
      },
      {
        key: "state",
        header: "Status",
        value: (row) => statusLabels[row.state],
        cardRole: "subtitle",
        cell: (row) => (
          <StatusBadge
            tone={
              row.state === "ISSUED"
                ? "success"
                : row.state === "CANCELLED_DRAFT"
                  ? "danger"
                  : "draft"
            }
          >
            {statusLabels[row.state]}
          </StatusBadge>
        ),
      },
      {
        key: "recipient",
        header: "Recipient",
        value: (row) =>
          row.recipient_party_id === null
            ? "Not set"
            : (labels(recipients, row.recipient_party_id) ??
              "Recipient unavailable"),
      },
      {
        key: "owner",
        header: "Owner",
        value: (row) => ownerByRevision[row.revision_id] ?? "Owner unavailable",
      },
      {
        key: "currency",
        header: "Currency",
        value: (row) =>
          labels(currencies, row.currency_id) ?? "Currency unavailable",
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
    [recipients, currencies, ownerByRevision, search],
  );
  const tableColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const filterChoices: {
    key: keyof QuotationListFilters;
    label: string;
    options: ReferenceChoice[];
  }[] = [
    {
      key: "state",
      label: "Status",
      options: [
        { value: "DRAFT", label: "Draft" },
        { value: "ISSUED", label: "Issued" },
        { value: "CANCELLED_DRAFT", label: "Cancelled draft" },
      ],
    },
    { key: "owner", label: "Owner", options: owners },
    { key: "recipient", label: "Recipient", options: recipients },
    { key: "currency", label: "Currency", options: currencies },
  ];
  return (
    <ScreenTemplate kind="list" className="erp-screen space-y-4">
      <PageHeader
        title="Quotations"
        description="Quotation drafts and issued quotations available to you."
        actions={
          mayCreate ? (
            <Button asChild>
              <Link to="/quotations/new">Create draft</Link>
            </Button>
          ) : undefined
        }
      />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {filterChoices.map(({ key, label, options }) => (
          <div key={key} className="min-w-0 space-y-1">
            <label
              htmlFor={`quotation-filter-${key}`}
              className="text-sm font-medium"
            >
              {label}
            </label>
            <select
              id={`quotation-filter-${key}`}
              className="h-11 w-full rounded-md border bg-background px-3"
              value={filters[key]}
              onChange={(event) => setFilter(key, event.target.value)}
            >
              <option value="">
                {key === "state"
                  ? "All statuses"
                  : key === "currency"
                    ? "All currencies"
                    : key === "owner"
                      ? "All owners"
                      : "All recipients"}
              </option>
              {options.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        ))}
        <div className="min-w-0 space-y-1">
          <label htmlFor="quotation-sort" className="text-sm font-medium">
            Sort
          </label>
          <select
            id="quotation-sort"
            className="h-11 w-full rounded-md border bg-background px-3"
            value={
              search.get("sort") ??
              (sorting.length
                ? `${sorting[0]!.desc ? "-" : ""}${sorting[0]!.id}`
                : "-created_at")
            }
            onChange={(event) => {
              const value = event.target.value;
              updateSorting([
                { id: value.replace(/^-/, ""), desc: value.startsWith("-") },
              ]);
            }}
          >
            <option value="-created_at">Newest first</option>
            <option value="created_at">Oldest first</option>
            <option value="-updated_at">Recently updated</option>
            <option value="pricing_date">Pricing date</option>
            <option value="valid_through_local_date">Valid through</option>
          </select>
        </div>
      </div>
      {Object.values(filters).some(Boolean) && (
        <Button
          type="button"
          variant="ghost"
          onClick={() => {
            const params = new URLSearchParams(search);
            (
              ["state", "recipient", "currency", "owner", "page"] as const
            ).forEach((key) => params.delete(key));
            setSearch(params);
            onPaginationChange({ ...pagination, pageIndex: 0 });
            onFiltersChange({
              state: "",
              recipient: "",
              currency: "",
              owner: "",
            });
          }}
        >
          Clear filters
        </Button>
      )}
      <DataTable
        columns={tableColumns}
        data={rows}
        rowCount={total}
        pagination={pagination}
        onPaginationChange={updatePage}
        sorting={sorting}
        onSortingChange={updateSorting}
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
                <Button asChild size="icon" variant="ghost">
                  <Link
                    to={`/quotations/${row.revision_id}/review${search.toString() ? `?${search}` : ""}`}
                    aria-label={`Review quotation revision ${row.revision_id}`}
                  >
                    <Eye aria-hidden="true" className="h-4 w-4" />
                  </Link>
                </Button>
              )
            : undefined
        }
      />
    </ScreenTemplate>
  );
}
