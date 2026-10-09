import { useEffect } from "react";
import { MD_BREAKPOINT_QUERY, useMediaQuery } from "../../lib/use-media-query";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from "../../components/ui/sheet";
import { useSearchParams } from "react-router";
import {
  useApprovalRequestsIndex,
  useApprovalRequestsInbox,
  useApprovalRequestsShow,
  useApprovalSubjectTypesIndex,
  type ApprovalRequestsIndexParams,
} from "@erp/core-api";
import {
  Clock,
  History,
  Inbox,
  Search,
  SlidersHorizontal,
  LayoutGrid,
  List,
} from "lucide-react";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
import { DataTableViewToggle } from "../../components/data-table/data-table-view-toggle";
import { ListError } from "../../components/data-table";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { usePermission } from "../../hooks/use-permission";
import { SectionStack } from "../../components/ui/section-stack";
import { Collapse } from "../../components/ui/collapse";
import { useListViewPreference } from "../../lib/use-view-preference";
import { ApprovalResults } from "./approval-results";
import { ApprovalRequestPanel } from "./approval-request-panel";
import {
  approvalSummary,
  isOverdue,
  subjectLabel,
} from "./approval-request-card";
import { DelegationsPanel } from "./delegations-panel";
import { REQUEST_STATUS_LABELS } from "./approval-labels";

/** The actionable inbox and capability-gated audit history share the approved list and record shells. */
export function ApprovalsInboxScreen() {
  const isDesktop = useMediaQuery(MD_BREAKPOINT_QUERY);
  const mayAudit = usePermission("approvals.history.view");
  const [params, setParams] = useSearchParams();
  const view =
    mayAudit && params.get("view") === "history"
      ? "history"
      : params.get("view") === "overdue"
        ? "overdue"
        : "mine";
  const search = params.get("q") ?? "";
  const type = params.get("subject_type") ?? "";
  const subjectId = params.get("subject_id") ?? "";
  const rawStatus = params.get("status") ?? "";
  const status = Object.hasOwn(REQUEST_STATUS_LABELS, rawStatus)
    ? (rawStatus as ApprovalRequestsIndexParams["filter[status]"])
    : undefined;
  const requestedPage = Number(params.get("page"));
  const page =
    Number.isSafeInteger(requestedPage) && requestedPage > 0
      ? requestedPage
      : 1;
  const perPage = [10, 25, 50, 100].includes(Number(params.get("per_page")))
    ? Number(params.get("per_page"))
    : 10;
  const { view: layout, setView: setLayout } = useListViewPreference(
    "approvals-workspace",
    { allowDesktopCards: true },
  );
  const sort = params.get("sort") === "oldest" ? "oldest" : "newest";
  const filtersOpen = params.get("filters") === "open";
  const selectedId = Math.max(0, Number(params.get("request")) || 0);
  function patch(
    values: Record<string, string | undefined>,
    resetPage = false,
  ) {
    setParams(() => {
      // Search-param updaters do not queue: preserve a preceding navigation when controls change quickly.
      const next = new URLSearchParams(window.location.search);
      for (const [key, value] of Object.entries(values))
        value ? next.set(key, value) : next.delete(key);
      if (resetPage) next.delete("page");
      return next;
    });
  }
  const inbox = useApprovalRequestsInbox();
  const subjects = useApprovalSubjectTypesIndex({
    query: { enabled: mayAudit, retry: false },
  });
  const registeredTypes =
    subjects.data?.status === 200 ? subjects.data.data.data : [];
  // Laravel's paginator supports page even though the generated filter type does not declare it.
  const historyParams: ApprovalRequestsIndexParams & { page: number } = {
    per_page: perPage,
    page,
    ...(type ? { "filter[subject_type]": type } : {}),
    ...(subjectId && Number(subjectId) > 0
      ? { "filter[subject_id]": Number(subjectId) }
      : {}),
    ...(status ? { "filter[status]": status } : {}),
  };
  const history = useApprovalRequestsIndex(historyParams, {
    query: { enabled: mayAudit && view === "history" },
  });
  const detail = useApprovalRequestsShow(selectedId, {
    query: { enabled: selectedId > 0 },
  });
  const waiting = inbox.data?.status === 200 ? inbox.data.data.data : [];
  const historyResponse =
    history.data?.status === 200 ? history.data.data : undefined;
  const source = view === "history" ? (historyResponse?.data ?? []) : waiting;
  const types = [
    ...new Set([
      ...registeredTypes.map((subject) => subject.code),
      ...waiting.map((request) => request.subject_type),
      ...source.map((request) => request.subject_type),
    ]),
  ].sort();
  if (type && !types.includes(type)) types.push(type);
  const filteredRows = source
    .filter((request) => {
      if (view === "overdue" && !isOverdue(request)) return false;
      if (type && request.subject_type !== type) return false;
      if (subjectId && request.subject_id !== Number(subjectId)) return false;
      if (!search.trim()) return true;
      const summary = approvalSummary(request);
      return [
        summary.number,
        summary.supplier,
        request.subject_id,
        request.id,
        subjectLabel(request.subject_type),
        request.policy.name,
        request.submitted_by.name,
        ...request.steps.map((step) => step.name),
      ]
        .join(" ")
        .toLowerCase()
        .includes(search.trim().toLowerCase());
    })
    .sort((a, b) => {
      if (view === "history") return 0;
      const order =
        Date.parse(a.submitted_at) - Date.parse(b.submitted_at) || a.id - b.id;
      return sort === "oldest" ? order : -order;
    });
  const activeQuery = view === "history" ? history : inbox;
  const total =
    view === "history"
      ? (historyResponse?.meta.total ?? 0)
      : filteredRows.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const shownPage = Math.min(page, lastPage);
  const rows =
    view === "history"
      ? filteredRows
      : filteredRows.slice((shownPage - 1) * perPage, shownPage * perPage);
  const pagination = { pageIndex: shownPage - 1, pageSize: perPage };
  useEffect(() => {
    if (!activeQuery.isLoading && !activeQuery.isError && page > lastPage)
      patch({ page: String(lastPage) });
  }, [page, lastPage, activeQuery.isLoading, activeQuery.isError]);

  const activeFilters = [
    search,
    type,
    subjectId,
    view === "history" ? status : undefined,
  ].filter(Boolean).length;
  const selected =
    detail.data?.status === 200 ? detail.data.data.data : undefined;
  function refresh() {
    void inbox.refetch();
    if (view === "history") void history.refetch();
    void detail.refetch();
  }
  function closeReview() {
    patch({ request: undefined });
    window.setTimeout(
      () =>
        document
          .querySelector<HTMLButtonElement>(
            `[data-approval-open="${selectedId}"]`,
          )
          ?.focus(),
      0,
    );
  }
  const filterFields = (
          <section
            aria-label="Approval filters"
            className="ui-filter-panel grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
          >
            <div className="space-y-2">
              <label className="text-sm font-medium" id="approval-type-label">
                Document type
              </label>
              <Select
                value={type || "all"}
                onValueChange={(value) =>
                  patch(
                    { subject_type: value === "all" ? undefined : value },
                    true,
                  )
                }
              >
                <SelectTrigger aria-labelledby="approval-type-label">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All document types</SelectItem>
                  {types.map((code) => (
                    <SelectItem key={code} value={code}>
                      {registeredTypes.find((subject) => subject.code === code)
                        ?.label ?? subjectLabel(code)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {view === "history" && (
              <div className="space-y-2">
                <label
                  className="text-sm font-medium"
                  id="approval-status-label"
                >
                  Outcome
                </label>
                <Select
                  value={status || "all"}
                  onValueChange={(value) =>
                    patch({ status: value === "all" ? undefined : value }, true)
                  }
                >
                  <SelectTrigger aria-labelledby="approval-status-label">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All outcomes</SelectItem>
                    {Object.entries(REQUEST_STATUS_LABELS).map(
                      ([code, label]) => (
                        <SelectItem key={code} value={code}>
                          {label}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-2">
              <label
                htmlFor="approval-document-id"
                className="text-sm font-medium"
              >
                Document ID
              </label>
              <Input
                id="approval-document-id"
                type="number"
                min="1"
                placeholder="Any document"
                value={subjectId}
                onChange={(event) =>
                  patch({ subject_id: event.target.value }, true)
                }
              />
            </div>
            {view !== "history" && (
              <div className="space-y-2">
                <label className="text-sm font-medium" id="approval-sort-label">
                  Sort
                </label>
                <Select
                  value={sort}
                  onValueChange={(value) => patch({ sort: value })}
                >
                  <SelectTrigger aria-labelledby="approval-sort-label">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="newest">Newest first</SelectItem>
                    <SelectItem value="oldest">Oldest first</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </section>
  );
  return (
    <ScreenTemplate
      kind="list"
      className="approvals-screen approval-controls p-4 pb-24 md:p-6"
      header={
        <PageHeader
          title="Approvals"
          description="Review what needs your decision and follow the recorded approval history."
        />
      }
    >
      <SectionStack className="mt-5">
        <div className="approval-toolbar">
          <div
            role="group"
            aria-label="Which approvals to show"
            className="approval-view-tabs"
          >
            <Button
              type="button"
              variant="ghost"
              className="approval-view-button"
              aria-pressed={view === "mine"}
              onClick={() =>
                patch(
                  { view: undefined, status: undefined, q: undefined },
                  true,
                )
              }
            >
              <Inbox className="mr-2 size-4" aria-hidden="true" />
              <span className="hidden sm:inline">Waiting for me</span><span className="sm:hidden">For me</span>
              {inbox.data?.status === 200 && (
                <span className="approval-count">{waiting.length}</span>
              )}
            </Button>
            <Button
              type="button"
              variant="ghost"
              className="approval-view-button"
              aria-pressed={view === "overdue"}
              onClick={() =>
                patch({ view: "overdue", status: undefined }, true)
              }
            >
              <Clock className="mr-2 size-4" aria-hidden="true" />
              Overdue
              {inbox.data?.status === 200 && (
                <span className="approval-count">
                  {waiting.filter(isOverdue).length}
                </span>
              )}
            </Button>
            {mayAudit && (
              <Button
                type="button"
                variant="ghost"
                className="approval-view-button"
                aria-pressed={view === "history"}
                onClick={() =>
                  patch(
                    { view: "history", q: undefined, sort: undefined },
                    true,
                  )
                }
              >
                <History className="mr-2 size-4" aria-hidden="true" />
                <span className="hidden sm:inline">All approvals & history</span><span className="sm:hidden">History</span>
              </Button>
            )}
          </div>
          <div className="approval-search-controls flex min-w-0 flex-wrap items-center gap-2">
            <div className="relative min-w-0 flex-1 sm:min-w-64">
              <Search
                className="ui-field-icon absolute left-3 top-1/2 -translate-y-1/2 size-4"
                aria-hidden="true"
              />
              <Input
                aria-label={
                  view === "history"
                    ? "Search this history page"
                    : "Search approvals"
                }
                placeholder={
                  view === "history"
                    ? "Search this history page…"
                    : "Search approvals…"
                }
                className="pl-9"
                value={search}
                onChange={(event) =>
                  patch({ q: event.target.value }, view !== "history")
                }
              />
            </div>
            <Button
              type="button"
              variant="outline"
              className={
                filtersOpen || activeFilters
                  ? "ui-btn-filters-active"
                  : undefined
              }
              aria-expanded={filtersOpen}
              aria-controls={isDesktop ? "approval-filters" : "approval-mobile-filters"}
              onClick={() =>
                patch({ filters: filtersOpen ? undefined : "open" })
              }
            >
              <SlidersHorizontal className="mr-2 size-4" aria-hidden="true" />
              Filters
              {activeFilters > 0 && (
                <span className="approval-count">{activeFilters}</span>
              )}
            </Button>
          </div>
        </div>
        <Collapse open={isDesktop && filtersOpen} id="approval-filters">
          {isDesktop && filterFields}
        </Collapse>
        {!isDesktop && (
          <Sheet open={filtersOpen} onOpenChange={(open) => patch({ filters: open ? "open" : undefined })}>
            <SheetContent side="bottom" id="approval-mobile-filters" className="approval-controls approval-mobile-filters max-h-[85dvh] overflow-y-auto">
              <SheetHeader>
                <SheetTitle>Approval filters</SheetTitle>
                <SheetDescription>Filter the current approval view.</SheetDescription>
              </SheetHeader>
              {filterFields}
              <SheetFooter>
                <Button variant="outline" onClick={() => patch({ subject_type: undefined, subject_id: undefined, status: undefined, sort: undefined }, true)}>Clear filters</Button>
                <Button onClick={() => patch({ filters: undefined })}>Done</Button>
              </SheetFooter>
            </SheetContent>
          </Sheet>
        )}
        <div className="approval-results-toolbar flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
          <p className="hidden sm:block">
            {view === "history"
              ? "Company approval history · newest first. Filter by outcome to review previous decisions."
              : view === "overdue"
                ? "Overdue requests you can decide now."
                : "Requests you can decide now, including delegated approvals."}
          </p>
          <p className="sm:hidden">{view === "history" ? "Company history · newest first" : view === "overdue" ? "Overdue · your decisions" : "Waiting for your decision"}</p>
          <div
            className="approval-layout-toggle hidden md:inline-flex"
            role="group"
            aria-label="Approval layout"
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="approval-layout-button"
              aria-pressed={layout === "table"}
              onClick={() => setLayout("table")}
            >
              <List className="size-4" aria-hidden="true" />
              <span className="sr-only sm:not-sr-only">Table view</span>
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="approval-layout-button"
              aria-pressed={layout === "cards"}
              onClick={() => setLayout("cards")}
            >
              <LayoutGrid className="size-4" aria-hidden="true" />
              <span className="sr-only sm:not-sr-only">Card view</span>
            </Button>
          </div>
          <div className="md:hidden"><DataTableViewToggle value={layout} onChange={setLayout} /></div>
          {activeFilters > 0 && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() =>
                patch(
                  {
                    q: undefined,
                    subject_type: undefined,
                    subject_id: undefined,
                    status: undefined,
                  },
                  true,
                )
              }
            >
              Clear filters
            </Button>
          )}
        </div>
        {activeFilters > 0 && (
          <div
            className="flex flex-wrap gap-2"
            aria-label="Active approval filters"
          >
            {[
              ["q", search, `Search: ${search}`],
              ["subject_type", type, `Document: ${subjectLabel(type)}`],
              ["subject_id", subjectId, `Document ID: ${subjectId}`],
              [
                "status",
                view === "history" ? status : "",
                `Outcome: ${status ? REQUEST_STATUS_LABELS[status] : ""}`,
              ],
            ]
              .filter(([, value]) => value)
              .map(([key, , label]) => (
                <Button
                  key={key}
                  type="button"
                  size="sm"
                  variant="outline"
                  className="approval-filter-chip"
                  aria-label={`Remove ${label}`}
                  onClick={() => patch({ [key!]: undefined }, true)}
                >
                  {label}
                  <span className="ml-2" aria-hidden="true">
                    ×
                  </span>
                </Button>
              ))}
          </div>
        )}
        {activeQuery.isError ? (
          <ListError
            message="Couldn't load approvals."
            error={activeQuery.error}
            onRetry={() => void activeQuery.refetch()}
          />
        ) : activeQuery.isLoading ? (
          <p
            role="status"
            className="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
          >
            Loading approvals…
          </p>
        ) : (
          <ApprovalResults
            rows={rows}
            total={total}
            pagination={pagination}
            layout={layout}
            history={view === "history"}
            sort={sort}
            onSort={(value) => patch({ sort: value }, true)}
            onPaginationChange={(updater) => {
              const next =
                typeof updater === "function" ? updater(pagination) : updater;
              patch(
                {
                  page: String(next.pageIndex + 1),
                  per_page: String(next.pageSize),
                },
                next.pageSize !== perPage,
              );
            }}
            onOpen={(request) => patch({ request: String(request.id) })}
            emptyMessage={
              activeFilters
                ? "No approvals match these filters"
                : view === "mine"
                  ? "You’re all caught up"
                  : view === "overdue"
                    ? "No overdue approvals"
                    : "No approval history yet"
            }
          />
        )}
        {view === "history" &&
          search.trim() &&
          !activeQuery.isLoading &&
          !activeQuery.isError && (
            <p className="text-xs text-muted-foreground">
              {rows.length} text matches on this page. Outcome and document
              filters apply to all history.
            </p>
          )}
        <div className="border-t pt-5">
          <DelegationsPanel
            subjectTypes={
              registeredTypes.length
                ? registeredTypes.map((subject) => ({
                    code: subject.code,
                    label: subject.label,
                  }))
                : [
                    ...new Set(waiting.map((request) => request.subject_type)),
                  ].map((code) => ({ code, label: subjectLabel(code) }))
            }
          />
        </div>
        {selectedId > 0 && (
          <RecordSheet
            className="approval-controls"
            key={selectedId}
            title={
              selected
                ? `Approval of ${approvalSummary(selected).number ?? `#${selected.subject_id}`}`
                : "Approval review"
            }
            description="Review the submitted evidence, workflow and recorded decisions."
            onClose={closeReview}
            size="xl"
            dismissOnOutsideClick={false}
          >
            {detail.isError ? (
              <ListError
                message="Couldn't load this approval."
                error={detail.error}
                onRetry={() => void detail.refetch()}
              />
            ) : selected ? (
              <ApprovalRequestPanel
                key={selected.id}
                request={selected}
                embedded
                onChanged={refresh}
              />
            ) : (
              <p role="status">Loading approval review…</p>
            )}
          </RecordSheet>
        )}
      </SectionStack>
    </ScreenTemplate>
  );
}
