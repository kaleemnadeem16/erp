import { z } from "zod";

/**
 * CONTRACT ADAPTER for GET /api/v1/quotations (agent-c/quotation-backend, cdbfac47; a fix is
 * pending on agent-b/quotation-access-fix). These types mirror that branch's generated client
 * by hand because the client is not on develop yet. SWAP: when it lands, `fetchQuotationList`
 * becomes a call to the generated `quotationsIndex` / `useQuotationsIndex`; the parsing and
 * everything above it stays.
 *
 * The contract carries a safe summary: identities, state, valid-through and the responsible
 * owner's id, plus two values the server decides PER ROW: `recipient_name` (Party read) and
 * `net_amount` (the independent commercial view; the frozen net of an issued quotation only).
 * Either is null when it is hidden or absent, and the list never says which.
 */
export const LIST_STATES = ["DRAFT", "CANCELLED_DRAFT", "ISSUED"] as const;
export type QuotationListState = (typeof LIST_STATES)[number];
export const LIST_SORTS = [
  "id",
  "created_at",
  "state",
  "-id",
  "-created_at",
  "-state",
] as const;
export type QuotationListSort = (typeof LIST_SORTS)[number];

const id = z.number().int().positive();
// Strict projection: unknown extras (cost, margin, ...) are dropped by zod's default strip.
export const summarySchema = z.object({
  revision_id: id,
  family_id: id,
  revision_ordinal: id,
  state: z.string(),
  recipient_party_id: id.nullable(),
  currency_id: id,
  created_at: z.string().nullable(),
  // Added on the integration baseline: safe header facts the detail contract already shows the same view holder.
  valid_through_local_date: z.string().nullable(),
  /** Present once issued. */
  official_number: z.string().nullable(),
  /** Derived by the server: DRAFT, CANCELLED_DRAFT, AVAILABLE, EXPIRED, WITHDRAWN, REPLACED. */
  availability: z.string(),
  responsible_owner_id: id.nullable(),
  recipient_name: z.string().nullable().optional(),
  net_amount: z
    .string()
    .regex(/^\d+(?:\.\d+)?$/)
    .nullable()
    .optional(),
});
const pageLink = z.string().nullable();
export const listPageSchema = z.object({
  data: z.array(summarySchema),
  links: z.object({
    first: pageLink,
    last: pageLink,
    prev: pageLink,
    next: pageLink,
  }),
  meta: z.object({
    current_page: z.number().int().min(1),
    last_page: z.number().int().min(1),
    per_page: z.number().int().min(0),
    total: z.number().int().min(0),
    from: z.number().int().nullable(),
    to: z.number().int().nullable(),
  }),
});
export type QuotationSummary = z.infer<typeof summarySchema>;
export type QuotationListPage = z.infer<typeof listPageSchema>;

export interface QuotationListParams {
  state?: QuotationListState;
  mine?: boolean;
  sort?: QuotationListSort;
  page: number;
  perPage: number;
}

export function readListPage(value: unknown): QuotationListPage {
  return listPageSchema.parse(value);
}

export function isListState(
  value: string | undefined,
): value is QuotationListState {
  return LIST_STATES.includes(value as QuotationListState);
}
export function isListSort(
  value: string | undefined,
): value is QuotationListSort {
  return LIST_SORTS.includes(value as QuotationListSort);
}

/** The query string the server documents: filter[state], filter[mine], sort, per_page, page. */
export function listQueryString(params: QuotationListParams): string {
  const query = new URLSearchParams();
  if (params.state) query.set("filter[state]", params.state);
  if (params.mine) query.set("filter[mine]", "1");
  if (params.sort) query.set("sort", params.sort);
  query.set("per_page", String(Math.min(100, Math.max(1, params.perPage))));
  query.set("page", String(Math.max(1, params.page)));
  return query.toString();
}

/** What the screen depends on. Real implementation is swapped in; tests and previews inject the mock. */
export type QuotationListGateway = (
  params: QuotationListParams,
) => Promise<QuotationListPage>;

/**
 * Default gateway while the contract is pending: the generated client is not on develop, and
 * core-api does not export its fetch client, so nothing can call the server yet. It fails
 * closed (a visible error state) rather than returning invented rows.
 * SWAP: replace with `quotationsIndex(...)` from @erp/core-api, then `readListPage(response.data)`.
 */
export const fetchQuotationList: QuotationListGateway = async () => {
  throw new Error("The quotation list contract is not available yet.");
};

/** MOCK DATA: used only by unit tests and the unregistered preview, never by a registered route. */
export function mockSummary(
  revisionId: number,
  overrides: Partial<QuotationSummary> = {},
): QuotationSummary {
  return {
    revision_id: revisionId,
    family_id: 100 + revisionId,
    revision_ordinal: 1,
    state: "DRAFT",
    recipient_party_id: 20 + revisionId,
    currency_id: 3,
    created_at: "2026-10-27T09:30:00Z",
    valid_through_local_date: "2026-11-30",
    official_number: null,
    availability: "DRAFT",
    responsible_owner_id: 7,
    recipient_name: null,
    net_amount: null,
    ...overrides,
  };
}

export function mockListGateway(
  rows: QuotationSummary[] = [
    mockSummary(1),
    mockSummary(2, {
      state: "ISSUED",
      revision_ordinal: 2,
      official_number: "QUO-2026-0007",
      availability: "AVAILABLE",
      recipient_name: "Alpha Decorators",
      net_amount: "860000.0000",
    }),
    mockSummary(3, {
      state: "CANCELLED_DRAFT",
      availability: "CANCELLED_DRAFT",
      recipient_party_id: null,
    }),
  ],
): QuotationListGateway {
  return async (params) => {
    let items = rows.filter(
      (row) => !params.state || row.state === params.state,
    );
    const key = (params.sort ?? "-id").replace(
      "-",
      "",
    ) as keyof QuotationSummary;
    const sign = (params.sort ?? "-id").startsWith("-") ? -1 : 1;
    items = [...items].sort(
      (a, b) =>
        sign *
        String(a[key]).localeCompare(String(b[key]), "en", { numeric: true }),
    );
    const start = (params.page - 1) * params.perPage;
    const data = items.slice(start, start + params.perPage);
    const last = Math.max(1, Math.ceil(items.length / params.perPage));
    return readListPage({
      data,
      links: {
        first: null,
        last: null,
        prev: params.page > 1 ? "prev" : null,
        next: params.page < last ? "next" : null,
      },
      meta: {
        current_page: params.page,
        last_page: last,
        per_page: params.perPage,
        total: items.length,
        from: data.length ? start + 1 : null,
        to: data.length ? start + data.length : null,
      },
    });
  };
}
