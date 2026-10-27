import { expect, test } from "@playwright/test";
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
    "Partial — known amount 999999999999999.9999",
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
});

test("404 never echoes revealing backend text; stale evidence offers review refresh", () => {
  const render = (status: number) =>
    renderToStaticMarkup(
      createElement(QuotationFailure, {
        error: Object.assign(new Error(), {
          status,
          body: { message: "Foreign company secret" },
        }),
        retry: () => {},
      }),
    );
  expect(render(404)).toContain("This quotation is not available.");
  expect(render(404)).not.toContain("Foreign company");
  expect(render(404)).not.toContain("Try again");
  expect(render(422)).toContain("Refresh review");
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

test("review refusal recovery is keyboard reachable", async ({ page }) => {
  const html = renderToStaticMarkup(
    createElement(QuotationFailure, {
      error: Object.assign(new Error(), {
        status: 422,
        body: {
          message: "The draft changed; review it again.",
          reason_code: "QUOTATION_DRAFT_CHANGED",
        },
      }),
      retry: () => {},
    }),
  );
  await page.setContent(`<main><h1>Quotation review</h1>${html}</main>`);
  await expect(page.getByRole("alert")).toContainText(
    "The draft changed; review it again.",
  );
  await page.keyboard.press("Tab");
  await expect(
    page.getByRole("button", { name: "Refresh review" }),
  ).toBeFocused();
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
      page.getByText("Partial — known amount 999999999999999.9999", {
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
