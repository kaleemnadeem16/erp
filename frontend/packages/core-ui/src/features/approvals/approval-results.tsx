import { useMemo } from "react";
import {
  getCoreRowModel,
  useReactTable,
  type PaginationState,
  type OnChangeFn,
  type SortingState,
} from "@tanstack/react-table";
import type { ApprovalRequestResource } from "@erp/core-api";
import { ArrowRight } from "lucide-react";
import {
  DataTable,
  DataTablePagination,
  toTanStackColumns,
  type DataColumn,
} from "../../components/data-table";
import { Button } from "../../components/ui/button";
import { StatusBadge } from "../../components/ui/status-badge";
import type { ListView } from "../../lib/use-view-preference";
import { formatDateTime } from "../../lib/format-date";
import {
  ApprovalRequestCard,
  approvalSummary,
  isOverdue,
  requestTone,
  subjectLabel,
} from "./approval-request-card";
import {
  readableApprovalAmount,
  REQUEST_STATUS_LABELS,
} from "./approval-labels";

export function currentApprovalStep(request: ApprovalRequestResource) {
  return (
    request.steps.find((step) => step.id === Number(request.my_step_id)) ??
    request.steps.find((step) => step.status === "OPEN")
  );
}

/** Both presentations consume the same page and reuse the shared list footer. */
export function ApprovalResults({
  rows,
  total,
  pagination,
  onPaginationChange,
  layout,
  history,
  sort,
  onSort,
  onOpen,
  emptyMessage,
}: {
  rows: ApprovalRequestResource[];
  total: number;
  pagination: PaginationState;
  onPaginationChange: OnChangeFn<PaginationState>;
  emptyMessage: string;
  layout: ListView;
  history: boolean;
  sort: string;
  onSort: (value: string) => void;
  onOpen: (request: ApprovalRequestResource) => void;
}) {
  const showsAmounts = rows.some((request) => request.amount_base != null);
  const columns = useMemo(() => {
    const definition: DataColumn<ApprovalRequestResource>[] = [
      {
        key: "document",
        header: "Document",
        value: (request) =>
          approvalSummary(request).number ?? `#${request.subject_id}`,
        cell: (request) => (
          <div className="space-y-1">
            <Button
              type="button"
              variant="link"
              className="ui-order-link h-auto p-0"
              data-approval-open={request.id}
              onClick={() => onOpen(request)}
            >
              {approvalSummary(request).number ?? `#${request.subject_id}`}
            </Button>
            <p className="text-xs text-muted-foreground">
              {subjectLabel(request.subject_type)}
            </p>
          </div>
        ),
      },
      {
        key: "supplier",
        header: "Supplier / subject",
        value: (request) =>
          approvalSummary(request).supplier ??
          subjectLabel(request.subject_type),
      },
      {
        key: "status",
        header: "Status",
        value: (request) =>
          REQUEST_STATUS_LABELS[request.status] ?? request.status,
        cell: (request) => (
          <div className="flex flex-wrap gap-1">
            <StatusBadge tone={requestTone(request.status)}>
              {REQUEST_STATUS_LABELS[request.status] ?? request.status}
            </StatusBadge>
            {isOverdue(request) && (
              <StatusBadge tone="danger">Overdue</StatusBadge>
            )}
          </div>
        ),
      },
      {
        key: "step",
        header: "Current step",
        value: (request) =>
          currentApprovalStep(request)?.name ??
          (request.status === "APPROVED" ? "Complete" : "—"),
        cell: (request) => {
          const step = currentApprovalStep(request);
          return (
            <div className="space-y-1">
              <p className="font-medium">
                {step?.name ??
                  (request.status === "APPROVED" ? "Complete" : "—")}
              </p>
              <p className="text-xs text-muted-foreground">
                {step
                  ? `${request.can_decide ? "Your decision · " : ""}${step.approved_count}/${step.required_approvals} approvals`
                  : `${request.steps.filter((item) => item.status === "APPROVED").length}/${request.steps.length} steps complete`}
              </p>
            </div>
          );
        },
      },
      {
        key: "submitted",
        header: "Submitted",
        sortable: !history,
        value: (request) => request.submitted_at,
        cell: (request) => (
          <div className="space-y-1">
            <p>{request.submitted_by.name}</p>
            <p className="text-xs text-muted-foreground">
              {formatDateTime(request.submitted_at)}
            </p>
          </div>
        ),
      },
    ];
    if (showsAmounts)
      definition.splice(3, 0, {
        key: "amount",
        header: "Amount (base)",
        align: "right",
        value: (request) =>
          request.amount_base == null
            ? null
            : readableApprovalAmount(String(request.amount_base)),
        cell: (request) => (
          <span className="font-semibold tabular-nums">
            {request.amount_base == null
              ? "—"
              : readableApprovalAmount(String(request.amount_base))}
          </span>
        ),
      });
    return toTanStackColumns(definition);
  }, [showsAmounts, history, onOpen]);
  const sorting: SortingState = history
    ? []
    : [{ id: "submitted", desc: sort !== "oldest" }];
  const footerTable = useReactTable({
    data: rows,
    columns,
    state: { pagination },
    manualPagination: true,
    rowCount: total,
    pageCount: Math.max(1, Math.ceil(total / pagination.pageSize)),
    onPaginationChange,
    getCoreRowModel: getCoreRowModel(),
  });
  const action = (request: ApprovalRequestResource) => (
    <Button
      type="button"
      size="sm"
      variant="link"
      className="approval-review-button px-0"
      data-approval-open={request.id}
      onClick={() => onOpen(request)}
    >
      {request.can_decide ? "Review & decide" : "View details"}
      <ArrowRight className="size-3.5" aria-hidden="true" />
    </Button>
  );
  return (
    <div key={layout} className="approval-results-enter">
      {layout === "table" ? (
        <DataTable
          columns={columns}
          data={rows}
          rowCount={total}
          pagination={pagination}
          onPaginationChange={onPaginationChange}
          sorting={sorting}
          onSortingChange={(updater) => {
            const next =
              typeof updater === "function" ? updater(sorting) : updater;
            onSort(next[0]?.desc === false ? "oldest" : "newest");
          }}
          getRowId={(request) => String(request.id)}
          rowActions={action}
          actionsHeader="Review"
          emptyMessage={emptyMessage}
          paginationLabel={
            history ? "Approval history pages" : "Approval pages"
          }
          scrollRegionLabel="Approval table"
          recordNoun="approval requests"
          numberedPagination
        />
      ) : (
        <div className="space-y-4">
          <div className="approval-card-grid">
            {rows.length === 0 && (
              <div className="col-span-full rounded-lg border bg-card p-6 text-center">
                <h2 className="font-semibold">{emptyMessage}</h2>
              </div>
            )}
            {rows.map((request) => (
              <ApprovalRequestCard
                key={request.id}
                request={request}
                onOpen={() => onOpen(request)}
              />
            ))}
          </div>
          <DataTablePagination
            table={footerTable}
            navigationLabel={
              history ? "Approval history pages" : "Approval pages"
            }
            recordNoun="approval requests"
            numbered
          />
        </div>
      )}
    </div>
  );
}
