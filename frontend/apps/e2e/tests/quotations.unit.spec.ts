import { expect, test, type Page } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { readFileSync, readdirSync } from "node:fs";
import AxeBuilder from "@axe-core/playwright";
import {
  quotationAccess,
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";
import {
  readDraft,
  readPreview,
  amountSchema,
  revisionId,
} from "../../../packages/core-ui/src/features/quotations/quotation-contract";
// Playwright rewrites JSX for component descriptors; compile this isolated SSR
// fixture with the production React JSX runtime before rendering actual components.
import { buildSync } from "esbuild";
import { createRequire } from "node:module";
import { mkdirSync, unlinkSync } from "node:fs";
const bundle = new URL(
  "../../../node_modules/.cache/quotation-ssr-" + process.pid + ".cjs",
  import.meta.url,
);
mkdirSync(new URL(".", bundle), { recursive: true });
buildSync({
  stdin: {
    contents: `export * from "./quotation-display"; export * from "./quotation-detail-screen"; export { useAuthStore } from "../../stores/auth-store";`,
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
    "@tanstack/*",
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
  QuotationFailure,
  QuotationMetadata,
  QuotationMoney,
  QuotationReview,
  QuotationDetailScreen,
  useAuthStore,
} = createRequire(import.meta.url)(
  bundle.pathname,
) as typeof import("../../../packages/core-ui/src/features/quotations/quotation-display") &
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-detail-screen") &
  typeof import("../../../packages/core-ui/src/stores/auth-store");
test.afterAll(() => {
  unlinkSync(bundle);
});
const browserFixture = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-record.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;

async function mountRecord(
  page: Page,
  path: string,
  reply: (url: URL, attempt: number) => { status: number; body: unknown },
) {
  const requests: string[] = [];
  await page.route("http://quotation.test/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith("/api/")) {
      requests.push(url.pathname);
      const response = reply(url, requests.length);
      await route.fulfill({
        status: response.status,
        contentType: "application/json",
        body: JSON.stringify(response.body),
      });
    } else {
      await route.fulfill({
        contentType: "text/html",
        body: '<!doctype html><html lang="en"><head><title>Quotation fixture</title></head><body><div id="root"></div></body></html>',
      });
    }
  });
  await page.goto(`http://quotation.test${path}`);
  await page.addScriptTag({ content: browserFixture });
  return requests;
}

import {
  configureCoreApi,
  setAuthToken,
  quotationsShow,
  quotationsPreview,
} from "../../../packages/core-api/src/index";

const money = (
  certainty = "COMPLETE",
  amount: string | null = "0.0000",
  known: string | null = "0.0000",
) => ({
  certainty,
  amount,
  known_amount: known,
  currency_id: 3,
  reasons: certainty === "COMPLETE" ? [] : ["PRICE_NOT_ENTERED"],
});
const draft = {
  revision_id: 12,
  family_id: 8,
  revision_ordinal: 1,
  state: "DRAFT",
  input_generation: 2,
  recipient_party_id: 23,
  currency_id: 3,
  pricing_date: "2026-10-27",
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  line_count: 1,
  created_by: 5,
};
const preview = {
  revision_id: 12,
  family_id: 8,
  input_generation: 2,
  authority: "PREVIEW_ONLY",
  currency_id: 3,
  pricing_date: "2026-10-27",
  lines: [
    {
      id: 14,
      logical_scope_id: 9,
      sort_order: 1,
      section_title: "Wall coating",
      description: "A long quotation line ".repeat(15),
      membership: "CHOOSE_ONE",
      choice_group_id: 7,
      is_baseline: true,
      source_kind: "DIRECT_ITEM",
      item_id: 31,
      manual_measure: null,
      offered: { quantity: "2.0000", uom_id: 4 },
      priced: { quantity: "2.0000", uom_id: 4 },
      stock: { quantity: "2.0000", uom_id: 4, conversion_version: 1 },
      unit_price: "0.0000",
      base_unit_price: "0.0000",
      net: money(),
      issues: [],
      fx: {
        fingerprint: "opaque",
        route: [],
        ratio: [],
        current: "CHANGED",
        currency_id: 3,
        base_currency_id: 1,
      },
      settlement: {
        computed: null,
        computed_fraction: { numerator: "1", denominator: "3" },
        residual: "-0.00333333",
      },
    },
  ],
  totals: {
    net: money("PARTIAL", null, "999999999999999.9999"),
    optional_net: money("UNKNOWN", null, null),
  },
  tax: {
    certainty: "UNKNOWN",
    reasons: ["TAX_NOT_DETERMINED"],
    total_including_tax: { certainty: "UNKNOWN", amount: null },
  },
  controls: { evaluated: 0, status: "NOT_CONFIGURED", blocks_issue: null },
  issue_ready: false,
  issue_blockers: [
    "TAX_NOT_DETERMINED",
    "ISSUE_NOT_AVAILABLE",
    "CONTROLS_NOT_CONFIGURED",
  ],
  issues: [],
  rounding_policy: {
    source: "BUILT_IN_DEFAULT",
    version_no: null,
    unit_price_scale: 4,
    rounding_mode: "HALF_UP",
    settlement_basis: "LINE_NET_TO_CURRENCY_QUANTUM",
    document_net: "SUM_OF_SETTLED_LINES",
    calculation_version: "FR4.1",
  },
  revalidation: {
    requires_review: true,
    reasons: ["ROUNDING_POLICY_CHANGED"],
    non_conforming_lines: [14],
  },
};

test("staged screens deny even supplied quotation grants; capability policies stay independent", () => {
  const grants = new Set([QUOTATION_VIEW, QUOTATION_PREPARE]);
  expect(QUOTATION_UI_ENABLED).toBe(false);
  expect(quotationAccess(grants)).toEqual({ detail: false, review: false });
  for (const url of [
    "/quotations",
    "/quotations/12",
    "/quotations/12/review",
    "/quotations/new",
    "/quotations/12/edit",
  ])
    expect(canAccessScreen(url, grants)).toBe(false);
  expect(quotationAccess(new Set([QUOTATION_VIEW]), true)).toEqual({
    detail: true,
    review: false,
  });
  expect(quotationAccess(new Set([QUOTATION_PREPARE]), true)).toEqual({
    detail: false,
    review: true,
  });
  expect(quotationAccess(new Set(), true)).toEqual({
    detail: false,
    review: false,
  });
  useAuthStore.setState({
    user: {
      id: 1,
      name: "Test",
      email: "test@example.test",
      must_change_password: false,
      permissions: [...grants],
    },
  });
  try {
    // No Router or QueryClient: denial must happen before either child/query mounts.
    expect(
      renderToStaticMarkup(createElement(QuotationDetailScreen)),
    ).toContain("You don’t have access");
    expect(
      renderToStaticMarkup(
        createElement(QuotationDetailScreen, { review: true }),
      ),
    ).toContain("You don’t have access");
  } finally {
    useAuthStore.setState({ user: null });
  }
});

test("revision IDs are positive safe integers; malformed IDs cannot enable a query", () => {
  for (const value of [
    undefined,
    "",
    "0",
    "-1",
    "1.5",
    "12x",
    "01",
    "9007199254740992",
  ])
    expect(revisionId(value)).toBeNull();
  expect(revisionId("12")).toBe(12);
});

test("generated clients use the documented GET endpoints and parse their envelopes", async () => {
  const original = globalThis.fetch;
  setAuthToken("fixture-only");
  const requests: Array<{ url: string; method?: string }> = [];
  configureCoreApi({ baseUrl: "https://erp.example.test/api" });
  globalThis.fetch = async (input, init) => {
    requests.push({ url: String(input), method: init?.method });
    return new Response(
      JSON.stringify({
        data: String(input).endsWith("/preview") ? preview : draft,
      }),
      { status: 200 },
    );
  };
  try {
    const shown = await quotationsShow(12);
    const reviewed = await quotationsPreview(12);
    expect(shown.status === 200 && readDraft(shown.data.data).line_count).toBe(
      1,
    );
    expect(
      reviewed.status === 200 &&
        readPreview(reviewed.data.data).totals.net.certainty,
    ).toBe("PARTIAL");
    expect(requests).toEqual([
      { url: "https://erp.example.test/api/v1/quotations/12", method: "GET" },
      {
        url: "https://erp.example.test/api/v1/quotations/12/preview",
        method: "GET",
      },
    ]);
  } finally {
    globalThis.fetch = original;
    setAuthToken(null);
    configureCoreApi({ baseUrl: "" });
  }
});

test("financial display keeps complete zero, partial known amounts and unknown separate", () => {
  const render = (value: unknown) =>
    renderToStaticMarkup(
      createElement(QuotationMoney, {
        value: amountSchema.parse(value),
        currency: 3,
      }),
    );
  expect(render(money())).toContain("0.0000");
  expect(render(money("PARTIAL", null, "999999999999999.9999"))).toContain(
    "Partial — at least 999999999999999.9999",
  );
  expect(render(money("UNKNOWN", null, null))).toContain("Unknown");
  expect(render(money("UNKNOWN", null, null))).not.toContain("0.0000");
  expect(() =>
    readPreview({
      ...preview,
      totals: { net: money("COMPLETE", null), optional_net: null },
    }),
  ).toThrow();
  expect(() =>
    readPreview({
      ...preview,
      lines: [{ ...preview.lines[0], unit_price: 1.25 }],
    }),
  ).toThrow();
  expect(() => readPreview({ ...preview, authority: "ISSUED" })).toThrow();
  const metadata = renderToStaticMarkup(
    createElement(QuotationMetadata, {
      draft: readDraft({ ...draft, cost: "secret", unit_price: "secret" }),
    }),
  );
  expect(metadata).not.toContain("secret");
  expect(() =>
    readPreview({
      ...preview,
      lines: [{ ...preview.lines[0], unit_price: "-1.0000" }],
    }),
  ).toThrow();
});

test("404 never echoes revealing backend text; stale evidence offers review refresh", () => {
  const render = (status: number, code?: string) =>
    renderToStaticMarkup(
      createElement(QuotationFailure, {
        error: Object.assign(new Error(), {
          status,
          body: { message: "Foreign company secret", reason_code: code },
        }),
        retry: () => {},
      }),
    );
  expect(render(404)).toContain("This quotation is not available.");
  expect(render(404)).not.toContain("Foreign company");
  expect(render(404)).not.toContain("Try again");
  expect(render(422, "QUOTATION_DRAFT_CHANGED")).toContain("Refresh review");
  for (const code of [
    undefined,
    "RECIPIENT_REQUIRED",
    "QUOTATION_NOT_EDITABLE",
    "PRICING_DATE_REQUIRED",
  ]) {
    expect(render(422, code)).not.toContain("<button");
  }
  expect(render(403)).not.toContain("Try again");
  expect(render(503)).toContain("Try again");
});

test("empty and manual scope remain explicit without invented financial facts", () => {
  const empty = readPreview({
    ...preview,
    lines: [],
    rounding_policy: null,
    revalidation: null,
    totals: { net: money("UNKNOWN", null, null), optional_net: null },
  });
  const html = renderToStaticMarkup(
    createElement(QuotationReview, { preview: empty }),
  );
  expect(html).toContain("No quotation lines.");
  expect(html).not.toContain("Optional net");
  const manual = readPreview({
    ...preview,
    lines: [
      {
        ...preview.lines[0],
        source_kind: "MANUAL",
        item_id: null,
        manual_measure: "visit",
        offered: { quantity: "3.0000", uom_id: null },
        priced: null,
        stock: null,
        unit_price: null,
        base_unit_price: null,
        fx: null,
        settlement: undefined,
        net: money("UNKNOWN", null, null),
      },
    ],
  });
  const manualHtml = renderToStaticMarkup(
    createElement(QuotationReview, { preview: manual }),
  );
  expect(manualHtml).toContain("visit");
  expect(manualHtml).toContain("Not entered");
  expect(manualHtml).not.toContain("#null");
});

test("keyboard Refresh review refetches through the mounted generated hook", async ({
  page,
}) => {
  const requests = await mountRecord(
    page,
    "/quotations/12/review?fixtureDuty=prepare&context=retained",
    (_url, attempt) =>
      attempt === 1
        ? {
            status: 422,
            body: {
              message: "The draft changed; review it again.",
              reason_code: "QUOTATION_DRAFT_CHANGED",
            },
          }
        : { status: 200, body: { data: preview } },
  );
  await expect(page.getByRole("alert")).toContainText(
    "The draft changed; review it again.",
  );
  expect(requests).toEqual(["/api/v1/quotations/12/preview"]);
  // Follow actual keyboard order: work-area link, then the retry button.
  await page.keyboard.press("Tab");
  await expect(
    page.getByRole("link", { name: "Back to work area" }),
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(
    page.getByRole("button", { name: "Refresh review" }),
  ).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("heading", { name: "Draft review", exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("alert")).toHaveCount(0);
  expect(requests).toEqual([
    "/api/v1/quotations/12/preview",
    "/api/v1/quotations/12/preview",
  ]);
  await expect(
    page.getByRole("link", { name: "Quotation details" }),
  ).toHaveCount(0);
});

test("mounted invalid /quotations/abc does not issue a request", async ({
  page,
}) => {
  const requests = await mountRecord(page, "/quotations/abc", () => ({
    status: 500,
    body: {},
  }));
  await expect(
    page.getByRole("heading", { name: "Quotation unavailable" }),
  ).toBeVisible();
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  );
  expect(requests).toEqual([]);
});

test("mounted metadata loads no financial preview and preserves link context", async ({
  page,
}) => {
  const requests = await mountRecord(
    page,
    "/quotations/12?context=retained",
    () => ({ status: 200, body: { data: draft } }),
  );
  await expect(
    page.getByRole("heading", { name: "Quotation", exact: true }),
  ).toBeVisible();
  expect(requests).toEqual(["/api/v1/quotations/12"]);
  await expect(
    page.getByRole("link", { name: "Review quotation" }),
  ).toHaveAttribute("href", "/quotations/12/review?context=retained");
});

for (const [reason, message] of [
  ["RECIPIENT_REQUIRED", "A preview needs the recipient Party."],
  ["QUOTATION_NOT_EDITABLE", "Only a draft quotation can be previewed."],
])
  test(`mounted ${reason} refuses without a futile refresh`, async ({
    page,
  }) => {
    const requests = await mountRecord(page, "/quotations/12/review", () => ({
      status: 422,
      body: { message, reason_code: reason },
    }));
    await expect(page.getByRole("alert")).toContainText(message!);
    await expect(
      page.getByRole("button", { name: "Refresh review" }),
    ).toHaveCount(0);
    expect(requests).toEqual(["/api/v1/quotations/12/preview"]);
  });

test("malformed preview is rejected by the mounted hook without rendering extras", async ({
  page,
}) => {
  const requests = await mountRecord(page, "/quotations/12/review", () => ({
    status: 200,
    body: {
      data: { ...preview, authority: "UNSUPPORTED", cost: "PRIVATE_EVIDENCE" },
    },
  }));
  await expect(page.getByRole("alert")).toContainText(
    "unsupported quotation response",
  );
  await expect(page.getByText("PRIVATE_EVIDENCE")).toHaveCount(0);
  expect(requests).toEqual(["/api/v1/quotations/12/preview"]);
});

// Static fixtures isolate the display boundary; sensitive extras are stripped by zod
// before any component sees them, including extras nested on preview lines/totals.
test("preview lines and totals omit protected extras and label missing evidence", () => {
  const parsed = readPreview({
    ...preview,
    issues: ["CHOICE_BASELINE_MISSING:7", "FUTURE_INTERNAL_CHECK:opaque"],
    lines: [
      {
        ...preview.lines[0],
        cost: "SECRET_COST",
        margin: "SECRET_MARGIN",
        base_unit_price: null,
        stock: { ...preview.lines[0]!.stock, conversion_version: null },
        issues: ["SOURCE_NOT_FOUND"],
        net: { ...money(), margin: "SECRET_NET_MARGIN" },
      },
    ],
    totals: {
      cost: "SECRET_TOTAL_COST",
      net: {
        ...money("PARTIAL", null, "5.0000"),
        margin: "SECRET_TOTAL_MARGIN",
      },
      optional_net: null,
    },
  });
  const html = renderToStaticMarkup(
    createElement(QuotationReview, { preview: parsed }),
  );
  for (const secret of [
    "SECRET_COST",
    "SECRET_MARGIN",
    "SECRET_NET_MARGIN",
    "SECRET_TOTAL_COST",
    "SECRET_TOTAL_MARGIN",
    "FUTURE_INTERNAL_CHECK",
    "opaque",
  ])
    expect(html).not.toContain(secret);
  expect(html).not.toContain("Conversion ");
  expect(html).not.toContain("null in base currency");
  expect(html).toContain("Base unit price not available");
  expect(html).toContain("Choose a baseline for choice group #7.");
  expect(html).toContain("The source Item is unavailable or archived.");
  expect(html).toContain("Partial — at least 5.0000");
});

// Standalone browser fixtures: only compiled CSS and server-rendered components;
// no running app, authentication, shared stack, API or database is accessed.
for (const theme of ["light", "dark"])
  test(`review is readable at phone width in ${theme}`, async ({ page }) => {
    const assets = new URL("../../main/dist/assets/", import.meta.url);
    const css = readdirSync(assets)
      .filter((name) => name.endsWith(".css"))
      .map((name) => readFileSync(new URL(name, assets), "utf8"))
      .join("\n");
    await page.setViewportSize({ width: 390, height: 844 });
    const content = renderToStaticMarkup(
      createElement(QuotationReview, { preview: readPreview(preview) }),
    );
    await page.setContent(
      `<!doctype html><html lang="en" class="${theme}"><head><title>Quotation fixture</title><style>${css}</style></head><body><main class="erp-screen erp-record p-4"><h1>Quotation review</h1>${content}</main></body></html>`,
    );
    await expect(
      page.getByRole("heading", { name: "Quotation lines" }),
    ).toBeVisible();
    await expect(
      page.getByText("Partial — at least 999999999999999.9999", {
        exact: false,
      }),
    ).toBeVisible();
    await expect(
      page.getByText("Computed: 1/3", { exact: false }),
    ).toBeVisible();
    await expect(page.getByText("Pricing evidence needs review")).toBeVisible();
    await expect(
      page.getByText("Not available", { exact: true }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
    const violations = (
      await new AxeBuilder({ page }).analyze()
    ).violations.filter(
      (v) => v.impact === "critical" || v.impact === "serious",
    );
    expect(violations).toEqual([]);
    await page.screenshot({
      path: test.info().outputPath("review.png"),
      fullPage: true,
    });
  });
