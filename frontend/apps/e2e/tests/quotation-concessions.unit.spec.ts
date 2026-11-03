import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import {
  quotationConcessionAccess,
  QUOTATION_DISCOUNT,
  QUOTATION_PREPARE,
  QUOTATION_PRICE_OVERRIDE,
  QUOTATION_VIEW,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import {
  buildChanges,
  readEditable,
  valuesFromEditable,
} from "../../../packages/core-ui/src/features/quotations/quotation-draft-changes";
import { readPreview } from "../../../packages/core-ui/src/features/quotations/quotation-contract";
import { ssrBundle } from "./fixtures/ssr-bundle";

type Screens =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-display") &
    typeof import("../../../packages/core-ui/src/features/quotations/quotation-edit-screen");
const { loaded, cleanup } = ssrBundle<Screens>(
  "quotation-concessions-ssr",
  `export * from "./quotation-display"; export { ConcessionFields, DocumentDiscountSection } from "./quotation-edit-screen";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const { QuotationLines, QuotationTotals, ConcessionFields, DocumentDiscountSection } =
  loaded;
test.afterAll(cleanup);

const editable = {
  revision_id: 12,
  family_id: 8,
  state: "DRAFT",
  input_generation: 3,
  recipient_party_id: null,
  currency_id: 2,
  pricing_date: "2026-11-03",
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  document_discount: null,
  lines: [
    {
      id: 11,
      scope_ref: "scope-a",
      section_title: null,
      description: "Survey",
      membership: "BASE",
      choice_group_ref: null,
      is_baseline: false,
      source_kind: "MANUAL",
      item_id: null,
      manual_measure: "job",
      offered_quantity: "2.0000",
      offered_uom_id: null,
      unit_price: "100.0000",
      sort_order: 10,
      discount: null,
      net_override: null,
      approval_required: false,
      approval_reason: null,
    },
  ],
};

test("concession controls need the view, prepare and their own duty, and never the production gate", () => {
  const all = new Set([
    QUOTATION_VIEW,
    QUOTATION_PREPARE,
    QUOTATION_DISCOUNT,
    QUOTATION_PRICE_OVERRIDE,
  ]);
  expect(quotationConcessionAccess(all)).toEqual({
    discount: false,
    override: false,
  });
  expect(quotationConcessionAccess(all, true)).toEqual({
    discount: true,
    override: true,
  });
  expect(
    quotationConcessionAccess(new Set([QUOTATION_VIEW, QUOTATION_PREPARE]), true),
  ).toEqual({ discount: false, override: false });
  expect(
    quotationConcessionAccess(
      new Set([QUOTATION_PREPARE, QUOTATION_DISCOUNT, QUOTATION_PRICE_OVERRIDE]),
      true,
    ),
  ).toEqual({ discount: false, override: false });
  expect(
    quotationConcessionAccess(
      new Set([QUOTATION_VIEW, QUOTATION_PREPARE, QUOTATION_DISCOUNT]),
      true,
    ),
  ).toEqual({ discount: true, override: false });
});

test("discount, override and document discount changes are minimal, exact and removable", () => {
  const base = readEditable(editable);
  const values = valuesFromEditable(base);
  expect(buildChanges(base, values)).toEqual({ expected_generation: 3 });
  values.lines[0]!.discount = { kind: "PERCENT", value: "12.5" };
  values.document_discount = { kind: "AMOUNT", value: "0.10" };
  expect(buildChanges(base, values)).toEqual({
    expected_generation: 3,
    document_discount: { kind: "AMOUNT", value: "0.10" },
    update_lines: [{ id: 11, discount: { kind: "PERCENT", value: "12.5" } }],
  });
  const withOverride = readEditable({
    ...editable,
    document_discount: { kind: "PERCENT", value: "2.0000" },
    lines: [
      {
        ...editable.lines[0],
        net_override: { amount: "150.0000", reason: "Goodwill" },
      },
    ],
  });
  const cleared = valuesFromEditable(withOverride);
  cleared.lines[0]!.net_override = null;
  cleared.document_discount = null;
  expect(buildChanges(withOverride, cleared)).toEqual({
    expected_generation: 3,
    document_discount: null,
    update_lines: [{ id: 11, net_override: null }],
  });
  // An older server response without the concession fields still parses, as no concession.
  const legacy = { ...editable } as Record<string, unknown>;
  delete legacy.document_discount;
  const legacyLine = { ...editable.lines[0] } as Record<string, unknown>;
  for (const key of ["discount", "net_override", "approval_required", "approval_reason"])
    delete legacyLine[key];
  expect(
    readEditable({ ...legacy, lines: [legacyLine] } as never).lines[0]!.discount,
  ).toBeNull();
});

const amount = (value: string) => ({
  certainty: "COMPLETE",
  amount: value,
  known_amount: value,
  currency_id: 2,
  reasons: [],
});
const preview = {
  revision_id: 12,
  family_id: 8,
  input_generation: 5,
  authority: "PREVIEW_ONLY",
  currency_id: 2,
  pricing_date: "2026-11-03",
  rounding_policy: null,
  revalidation: null,
  document_discount: {
    kind: "PERCENT",
    value: "5.0000",
    amount: "9.0000",
    approval_required: true,
    approval_reason: "DISCOUNT_LIMIT_NOT_CONFIGURED",
    issues: [],
  },
  lines: [
    {
      id: 11,
      logical_scope_id: 1,
      sort_order: 10,
      section_title: null,
      description: "Survey",
      membership: "BASE",
      choice_group_id: null,
      is_baseline: false,
      source_kind: "MANUAL",
      item_id: null,
      manual_measure: "job",
      offered: { quantity: "2.0000", uom_id: null },
      priced: null,
      stock: null,
      unit_price: "100.0000",
      base_unit_price: null,
      counts_in_total: true,
      list_amount: "200.0000",
      adjustment: {
        discount: { kind: "PERCENT", value: "10.0000" },
        override: null,
        amount: "20.0000",
        document_share: "9.0000",
        pre_document_net: "180.0000",
        approval_required: true,
        approval_reason: "DISCOUNT_LIMIT_NOT_CONFIGURED",
      },
      net: amount("171.0000"),
      issues: [],
      fx: null,
    },
  ],
  totals: {
    net: amount("171.0000"),
    optional_net: null,
    list: "200.0000",
    reduction: "29.0000",
  },
  tax: {
    certainty: "COMPLETE",
    reasons: [],
    lines: [{ line_id: 11, disposition: "TAXABLE", tax: "17.1000", gross: "188.1000" }],
    total: "17.1000",
    total_including_tax: { certainty: "COMPLETE", amount: "188.1000" },
  },
  controls: { evaluated: 0, status: "NOT_CONFIGURED", blocks_issue: null },
  issue_ready: false,
  issue_blockers: ["ISSUE_NOT_AVAILABLE", "APPROVAL_REQUIRED"],
  issues: [],
};

test("the review shows discounts, the document discount, tax and totals, and no cost or limit", () => {
  const parsed = readPreview(preview as never);
  const html =
    renderToStaticMarkup(createElement(QuotationLines, { preview: parsed })) +
    renderToStaticMarkup(createElement(QuotationTotals, { preview: parsed }));
  for (const text of [
    "10.0000 percent",
    "20.0000 taken off",
    "Share of document discount",
    "171.0000",
    "17.1000",
    "188.1000",
    "29.0000 off",
    "No discount limit is configured",
  ])
    expect(html).toContain(text);
  expect(html.toLowerCase()).not.toMatch(/cost|margin|threshold/);
  // Unknown tax stays unknown and names why, never zero.
  const unknown = readPreview({
    ...preview,
    tax: {
      certainty: "UNKNOWN",
      reasons: ["TAX_JURISDICTION_UNKNOWN"],
      total_including_tax: { certainty: "UNKNOWN", amount: null },
    },
  } as never);
  const unknownHtml = renderToStaticMarkup(
    createElement(QuotationTotals, { preview: unknown }),
  );
  expect(unknownHtml).toContain("Unknown");
  expect(unknownHtml).toContain("No tax jurisdiction is established");
  // A preview from before concessions existed still parses.
  const old = JSON.parse(JSON.stringify(preview));
  delete old.document_discount;
  delete old.totals.list;
  delete old.lines[0].adjustment;
  delete old.lines[0].list_amount;
  expect(() => readPreview(old)).not.toThrow();
});

test("the editor offers each concession only to its duty holder, with 44px targets", () => {
  const line = readEditable(editable).lines[0]!;
  const render = (discount: boolean, override: boolean, l = line) =>
    renderToStaticMarkup(
      createElement(ConcessionFields, {
        id: "11",
        line: l,
        concessions: { discount, override },
        onChange: () => undefined,
      }),
    );
  expect(render(false, false)).toBe("");
  const discountOnly = render(true, false);
  expect(discountOnly).toContain("Discount type");
  expect(discountOnly).not.toContain("Override line net");
  expect(discountOnly).toContain("min-h-11");
  const both = render(true, true);
  expect(both).toContain("Override line net");
  const entered = {
    ...line,
    discount: { kind: "PERCENT" as const, value: "5.0000" },
    approval_reason: "DISCOUNT_LIMIT_NOT_CONFIGURED",
  };
  const readOnly = render(false, false, entered);
  expect(readOnly).toContain("Discount entered");
  expect(readOnly).toContain("no discount limit is configured");
  expect(readOnly).not.toContain("<select");
  const overridden = {
    ...line,
    net_override: { amount: "150.0000", reason: "Goodwill" },
  };
  expect(render(true, true, overridden)).toContain("Remove override");
  expect(render(true, false, overridden)).not.toContain("<select");
  expect(
    renderToStaticMarkup(
      createElement(DocumentDiscountSection, {
        value: null,
        canEdit: false,
        onChange: () => undefined,
      }),
    ),
  ).toBe("");
  expect(
    renderToStaticMarkup(
      createElement(DocumentDiscountSection, {
        value: null,
        canEdit: true,
        onChange: () => undefined,
      }),
    ),
  ).toContain("No document discount");
});
