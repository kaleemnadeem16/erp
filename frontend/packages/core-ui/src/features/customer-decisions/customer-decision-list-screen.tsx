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
import { Combobox, type ComboboxOption } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
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
  /** Opened on or after / before this company-calendar day (`yyyy-mm-dd`), as the URL carries them; empty for no bound. */
  recordedFrom: string;
  recordedTo: string;
  onRecordedChange: (next: { from?: string; to?: string }) => void;
  /** The quotation family filtered to; empty for any. */
  quotationFamilyId: string;
  /** The issued quotations a person may pick; null hides the control (the person may not view quotations). */
  quotationOptions: ComboboxOption[] | null;
  quotationsLoading?: boolean;
  quotationsError?: unknown;
  onQuotationsRetry?: () => void;
  onQuotationChange: (familyId: string | undefined) => void;
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
  const rangeBackwards =
    props.recordedFrom !== "" &&
    props.recordedTo !== "" &&
    props.recordedTo < props.recordedFrom;
  const quotationFilter = (id: string, className: string) =>
    props.quotationOptions === null ? null : (
      <Combobox
        id={id}
        aria-label="Linked quotation"
        className={className}
        value={props.quotationFamilyId || "all"}
        onValueChange={(value) =>
          props.onQuotationChange(value === "all" ? undefined : value)
        }
        options={[
          { value: "all", label: "Any quotation" },
          ...props.quotationOptions,
        ]}
        placeholder="Any quotation"
        searchPlaceholder="Search quotations…"
        emptyText="No issued quotations."
        loading={props.quotationsLoading}
        loadError={
          props.quotationsError
            ? {
                error: props.quotationsError,
                onRetry: () => props.onQuotationsRetry?.(),
                noun: "quotations",
              }
            : undefined
        }
        selectedLabel={
          props.quotationFamilyId &&
          !props.quotationOptions.some(
            (o) => o.value === props.quotationFamilyId,
          )
            ? `Quotation #${props.quotationFamilyId}`
            : undefined
        }
      />
    );
  const dateRange = (id: string, fieldClass: string, full: boolean) => (
    <div className="space-y-1">
      <div className="flex items-center gap-1.5">
        <DateField
          id={`${id}-from`}
          aria-label="Opened from"
          value={props.recordedFrom}
          onChange={(value) =>
            props.onRecordedChange({ from: value || undefined })
          }
          className={fieldClass}
          pickerPosition={full ? "end" : "start"}
          aria-invalid={rangeBackwards}
          aria-describedby={rangeBackwards ? `${id}-range-note` : undefined}
        />
        <span className="text-xs text-muted-foreground" aria-hidden="true">
          –
        </span>
        <DateField
          id={`${id}-to`}
          aria-label="Opened to"
          value={props.recordedTo}
          onChange={(value) =>
            props.onRecordedChange({ to: value || undefined })
          }
          className={fieldClass}
          pickerPosition={full ? "end" : "start"}
          aria-invalid={rangeBackwards}
          aria-describedby={rangeBackwards ? `${id}-range-note` : undefined}
        />
      </div>
      {rangeBackwards && (
        <p
          id={`${id}-range-note`}
          role="alert"
          className="text-xs text-destructive"
        >
          The end day is before the start day, so the dates are not applied.
        </p>
      )}
    </div>
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
  if (props.recordedFrom || props.recordedTo)
    chips.push({
      id: "opened",
      label: `Opened: ${props.recordedFrom || "…"} – ${props.recordedTo || "…"}`,
      onRemove: () =>
        props.onRecordedChange({ from: undefined, to: undefined }),
    });
  if (props.quotationFamilyId)
    chips.push({
      id: "quotation",
      label: `Quotation: ${
        props.quotationOptions?.find((o) => o.value === props.quotationFamilyId)
          ?.label ?? `#${props.quotationFamilyId}`
      }`,
      onRemove: () => props.onQuotationChange(undefined),
    });
  const activeCount =
    (props.partyId ? 1 : 0) +
    (props.recordedFrom || props.recordedTo ? 1 : 0) +
    (props.quotationFamilyId ? 1 : 0);
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
          {quotationFilter("decision-quotation-filter", "h-10 w-56")}
          {dateRange("decision-opened", "h-10 w-40", true)}
        </div>
        <ListFiltersSheet activeCount={activeCount} onClear={props.onClearAll}>
          <FilterField label="Customer" htmlFor="decision-party-filter-sheet">
            {partyFilter("decision-party-filter-sheet", "h-11 w-full")}
          </FilterField>
          {props.quotationOptions !== null && (
            <FilterField
              label="Linked quotation"
              htmlFor="decision-quotation-filter-sheet"
            >
              {quotationFilter(
                "decision-quotation-filter-sheet",
                "h-11 w-full",
              )}
            </FilterField>
          )}
          <FilterField label="Opened between">
            {dateRange("decision-opened-sheet", "h-11 w-full", false)}
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
  const query = useListQueryState<{
    search: string;
    party_id: string;
    recorded_from: string;
    recorded_to: string;
    quotation_family_id: string;
  }>({
    defaultSort: "-id",
    defaultPerPage: 25,
  });
  const sort = query.sort === "id" ? "id" : "-id";
  const search = query.filters.search ?? "";
  const partyId = /^[1-9]\d*$/.test(query.filters.party_id ?? "")
    ? (query.filters.party_id as string)
    : "";
  const isDay = (value: string | undefined) =>
    /^\d{4}-\d{2}-\d{2}$/.test(value ?? "") ? (value as string) : "";
  const recordedFrom = isDay(query.filters.recorded_from);
  const recordedTo = isDay(query.filters.recorded_to);
  const quotationFamilyId = /^[1-9]\d*$/.test(
    query.filters.quotation_family_id ?? "",
  )
    ? (query.filters.quotation_family_id as string)
    : "";
  // A backwards range is not sent (the server would refuse it); the filter bar says so.
  const rangeBackwards =
    recordedFrom !== "" && recordedTo !== "" && recordedTo < recordedFrom;
  // The quotation picker lists the issued quotations (of the chosen customer, if any) and needs the quotation view duty.
  const quotations = useQuery({
    queryKey: [
      "customer-decision-filter-quotations",
      partyId ? Number(partyId) : null,
    ],
    queryFn: () => gateway.quotationsFor(partyId ? Number(partyId) : undefined),
    enabled: mayLinkQuotations,
    retry: shouldRetryQuery,
  });
  const quotationOptions: ComboboxOption[] | null = mayLinkQuotations
    ? [
        ...new Map(
          (quotations.data ?? []).map((q) => [
            String(q.family_id),
            {
              value: String(q.family_id),
              label: q.official_number ?? `Quotation #${q.family_id}`,
            },
          ]),
        ).values(),
      ]
    : null;
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
    recordedFrom: rangeBackwards ? undefined : recordedFrom || undefined,
    recordedTo: rangeBackwards ? undefined : recordedTo || undefined,
    quotationFamilyId: quotationFamilyId
      ? Number(quotationFamilyId)
      : undefined,
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
        recordedFrom={recordedFrom}
        recordedTo={recordedTo}
        onRecordedChange={(next) =>
          query.setFilters({
            ...("from" in next ? { recorded_from: next.from } : {}),
            ...("to" in next ? { recorded_to: next.to } : {}),
          })
        }
        quotationFamilyId={quotationFamilyId}
        quotationOptions={quotationOptions}
        quotationsLoading={quotations.isPending && mayLinkQuotations}
        quotationsError={quotations.isError ? quotations.error : undefined}
        onQuotationsRetry={() => void quotations.refetch()}
        onQuotationChange={(value) =>
          query.setFilter("quotation_family_id", value)
        }
        onClearAll={() =>
          query.setFilters({
            search: undefined,
            party_id: undefined,
            recorded_from: undefined,
            recorded_to: undefined,
            quotation_family_id: undefined,
          })
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
