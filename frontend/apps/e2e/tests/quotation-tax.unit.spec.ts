import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  buildChanges,
  readEditable,
  valuesFromEditable,
} from "../../../packages/core-ui/src/features/quotations/quotation-draft-changes";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-edit-screen");
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-tax-ssr",
  `export { TaxDeclarationFields } from "./quotation-edit-screen";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const { TaxDeclarationFields } = loaded;
test.afterAll(cleanup);

const base = {
  revision_id: 12,
  family_id: 8,
  state: "DRAFT",
  input_generation: 3,
  recipient_party_id: null,
  currency_id: 2,
  pricing_date: null,
  tax_estimate_date: "2026-10-27",
  valid_through_local_date: null,
  current_opportunity_id: null,
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
      offered_quantity: "1.0000",
      offered_uom_id: null,
      unit_price: "10.0000",
      sort_order: 10,
      tax_declaration: {
        version_no: 1,
        nature: "SERVICES",
        classification: "services",
        reason: "Labour",
      },
    },
  ],
};

test("a tax declaration is sent only when it states something new, as the next version with its reason", () => {
  const editable = readEditable(base);
  const values = valuesFromEditable(editable);
  expect(buildChanges(editable, values)).toEqual({ expected_generation: 3 });
  values.lines[0]!.tax_declaration_change = {
    nature: "SERVICES",
    classification: " services ",
    reason: "Restated",
  };
  expect(buildChanges(editable, values)).toEqual({ expected_generation: 3 });
  values.lines[0]!.tax_declaration_change = {
    nature: "GOODS",
    classification: "goods",
    reason: " It is paint ",
  };
  expect(buildChanges(editable, values)).toEqual({
    expected_generation: 3,
    update_lines: [
      {
        id: 11,
        tax_declaration: {
          nature: "GOODS",
          classification: "goods",
          reason: "It is paint",
        },
      },
    ],
  });
});

test("the declaration control names what is in force and offers declare or correct", () => {
  const render = (current: unknown, value: unknown) =>
    renderToStaticMarkup(
      createElement(TaxDeclarationFields, {
        id: "11",
        current: current as never,
        value: value as never,
        onChange: () => undefined,
      }),
    );
  const none = render(null, null);
  expect(none).toContain("not declared, so tax cannot be determined");
  expect(none).toContain("Declare classification");
  const declared = render(base.lines[0]!.tax_declaration, null);
  expect(declared).toContain("version 1");
  expect(declared).toContain("Correct classification");
  expect(declared).toContain("min-h-11");
  const editing = render(base.lines[0]!.tax_declaration, {
    nature: "GOODS",
    classification: "goods",
    reason: "",
  });
  expect(editing).toContain("Reason for stating it");
  expect(editing).toContain("Cancel");
});
