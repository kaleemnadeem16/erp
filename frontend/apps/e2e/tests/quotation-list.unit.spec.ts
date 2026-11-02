import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { buildSync } from "esbuild";
import { createRequire } from "node:module";
import { mkdirSync, unlinkSync } from "node:fs";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import {
  quotationMilestoneAccess,
  NO_MILESTONE_ACCESS,
  QUOTATION_ISSUE,
  DOCUMENT_VIEW,
  DOCUMENT_GENERATE,
  DOCUMENT_DOWNLOAD,
  DOCUMENT_REPAIR,
  DELIVERY_VIEW,
  DELIVERY_RECORD,
} from "../../../packages/core-ui/src/features/quotations/quotation-milestone-access";
import { allowsQuotationDevMode } from "../../../packages/core-ui/src/lib/quotation-dev-mode";
import {
  listQueryString,
  mockListGateway,
  mockSummary,
  readListPage,
} from "../../../packages/core-ui/src/features/quotations/quotation-list-contract";

const bundle = new URL(
  "../../../node_modules/.cache/quotation-list-ssr-" + process.pid + ".cjs",
  import.meta.url,
);
mkdirSync(new URL(".", bundle), { recursive: true });
buildSync({
  stdin: {
    contents: `export * from "./quotation-list-screen"; export { useAuthStore } from "../../stores/auth-store"; export { TooltipProvider } from "../../components/ui/tooltip"; export { MemoryRouter } from "react-router";`,
    resolveDir: new URL(
      "../../../packages/core-ui/src/features/quotations/",
      import.meta.url,
    ).pathname,
    loader: "tsx",
  },
  bundle: true,
  platform: "node",
  format: "cjs",
  jsx: "automatic",
  outfile: bundle.pathname,
  external: [
    "react",
    "react-dom",
    "react-router",
    "zod",
    "zustand",
    "lucide-react",
    "@radix-ui/*",
    "clsx",
    "tailwind-merge",
    "class-variance-authority",
  ],
});
const {
  QuotationListView,
  QuotationListScreen,
  useAuthStore,
  TooltipProvider,
  MemoryRouter,
} = createRequire(import.meta.url)(
  bundle.pathname,
) as typeof import("../../../packages/core-ui/src/features/quotations/quotation-list-screen") &
  typeof import("../../../packages/core-ui/src/stores/auth-store") &
  typeof import("../../../packages/core-ui/src/components/ui/tooltip") &
  typeof import("react-router");
test.afterAll(() => {
  unlinkSync(bundle);
});

type ViewProps = Parameters<typeof QuotationListView>[0];
function view(overrides: Partial<ViewProps> = {}) {
  const props: ViewProps = {
    rows: [
      mockSummary(1),
      mockSummary(2, { state: "ISSUED", revision_ordinal: 2 }),
      mockSummary(3, { state: "CANCELLED_DRAFT", recipient_party_id: null }),
    ],
    total: 3,
    pagination: { pageIndex: 0, pageSize: 25 },
    onPaginationChange: () => undefined,
    sorting: [],
    onSortingChange: () => undefined,
    state: undefined,
    onStateChange: () => undefined,
    mine: false,
    onMineChange: () => undefined,
    isLoading: false,
    error: null,
    onOpen: () => undefined,
    mayCreate: true,
    ...overrides,
  };
  return renderToStaticMarkup(
    createElement(
      MemoryRouter,
      null,
      createElement(
        TooltipProvider,
        null,
        createElement(QuotationListView, props),
      ),
    ),
  );
}

test("production build keeps every milestone screen denied, whatever is granted", () => {
  const everything = new Set([
    QUOTATION_VIEW,
    QUOTATION_PREPARE,
    QUOTATION_ISSUE,
    DOCUMENT_VIEW,
    DOCUMENT_GENERATE,
    DOCUMENT_DOWNLOAD,
    DOCUMENT_REPAIR,
    DELIVERY_VIEW,
    DELIVERY_RECORD,
  ]);
  expect(QUOTATION_UI_ENABLED).toBe(false);
  expect(quotationMilestoneAccess(everything)).toEqual(NO_MILESTONE_ACCESS);
  expect(
    Object.values(quotationMilestoneAccess(everything)).some(Boolean),
  ).toBe(false);
  // The development override can never turn on in a production build, even with the flag set.
  expect(
    allowsQuotationDevMode({
      development: false,
      flag: "1",
      hostname: "localhost",
    }),
  ).toBe(false);
});

test("milestone duties follow the server pairing of duty plus independent view", () => {
  const on = (...p: string[]) => quotationMilestoneAccess(new Set(p), true);
  expect(on()).toEqual(NO_MILESTONE_ACCESS);
  expect(on(QUOTATION_ISSUE)).toEqual(NO_MILESTONE_ACCESS);
  expect(on(DOCUMENT_VIEW)).toEqual(NO_MILESTONE_ACCESS);
  expect(on(QUOTATION_VIEW)).toMatchObject({
    list: true,
    create: false,
    issue: false,
    documents: false,
  });
  expect(on(QUOTATION_VIEW, QUOTATION_PREPARE)).toMatchObject({
    create: true,
    readiness: true,
    issue: false,
  });
  expect(on(QUOTATION_VIEW, QUOTATION_ISSUE)).toMatchObject({
    issue: true,
    readiness: false,
  });
  expect(on(QUOTATION_VIEW, DOCUMENT_VIEW)).toMatchObject({
    documents: true,
    generate: false,
    download: false,
    check: false,
  });
  expect(on(QUOTATION_VIEW, DOCUMENT_VIEW, DOCUMENT_DOWNLOAD)).toMatchObject({
    download: true,
    generate: false,
  });
  expect(on(QUOTATION_VIEW, DELIVERY_RECORD)).toMatchObject({
    recordDelivery: false,
  });
  expect(on(QUOTATION_VIEW, DELIVERY_VIEW, DELIVERY_RECORD)).toMatchObject({
    deliveries: true,
    recordDelivery: true,
  });
});

test("the list screen denies before any query or router mounts when the gate is off", () => {
  useAuthStore.setState({
    user: {
      id: 1,
      name: "Test",
      email: "test@example.test",
      must_change_password: false,
      permissions: [QUOTATION_VIEW, QUOTATION_PREPARE],
    },
  });
  try {
    // No Router or QueryClient provided: only the denial path can render.
    expect(renderToStaticMarkup(createElement(QuotationListScreen))).toContain(
      "You don’t have access",
    );
  } finally {
    useAuthStore.setState({ user: null });
  }
});

test("list contract keeps only the safe summary and rejects malformed pages", () => {
  const page = readListPage({
    data: [
      {
        ...mockSummary(1),
        cost: "SECRET_COST",
        margin: "SECRET_MARGIN",
        owner_email: "x@y.z",
      },
    ],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
      from: 1,
      to: 1,
    },
  });
  expect(JSON.stringify(page)).not.toMatch(/SECRET|owner_email/);
  expect(() => readListPage({ data: [{ revision_id: "x" }] })).toThrow();
  expect(
    listQueryString({
      state: "ISSUED",
      mine: true,
      sort: "-created_at",
      page: 2,
      perPage: 500,
    }),
  ).toBe(
    "filter%5Bstate%5D=ISSUED&filter%5Bmine%5D=1&sort=-created_at&per_page=100&page=2",
  );
});

test("mock gateway filters, sorts and pages server-style", async () => {
  const gateway = mockListGateway();
  const issued = await gateway({ state: "ISSUED", page: 1, perPage: 10 });
  expect(issued.data.map((r) => r.revision_id)).toEqual([2]);
  const sorted = await gateway({ sort: "id", page: 1, perPage: 2 });
  expect(sorted.data.map((r) => r.revision_id)).toEqual([1, 2]);
  expect(sorted.links.next).not.toBeNull();
  expect(sorted.meta.total).toBe(3);
});

test("list renders rows with labels, status text and Create draft only for preparers", () => {
  const html = view();
  expect(html).toContain("Quotation 1 · Revision 1");
  expect(html).toContain("Issued");
  expect(html).toContain("Cancelled draft");
  expect(html).toContain("Not specified");
  expect(html).toContain("Create draft");
  expect(html).toContain('for="quotation-filter-state"');
  expect(view({ mayCreate: false })).not.toContain("Create draft");
});

test("list shows loading, empty, filtered-empty and error states", () => {
  expect(view({ rows: [], total: 0 })).toContain("No quotations yet.");
  expect(view({ rows: [], total: 0, mine: true })).toContain(
    "No quotations match these filters.",
  );
  expect(view({ rows: [], total: 0, isLoading: true })).toContain(
    "animate-pulse",
  );
  const forbidden = view({
    rows: [],
    total: 0,
    error: Object.assign(new Error("x"), { status: 403 }),
  });
  expect(forbidden).toContain("You don&#x27;t have permission to view this.");
  expect(forbidden).toContain("Couldn’t load quotations.");
  const offline = view({
    rows: [],
    total: 0,
    error: new TypeError("net"),
    onRetry: () => undefined,
  });
  expect(offline).toContain("Can&#x27;t reach the server");
  expect(offline).toContain("Try again");
});
