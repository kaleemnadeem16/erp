import { expect, test } from "@playwright/test";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  mockSummary,
  readListPage,
} from "../../../packages/core-ui/src/features/quotations/quotation-list-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-list-screen");
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-list-values-ssr",
  `export { quotationListColumns } from "./quotation-list-screen";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
test.afterAll(cleanup);

const column = (key: string) =>
  loaded.quotationListColumns.find((c) => c.key === key)!;

test("the recipient name and the net amount show when the server sent them and a neutral text when it did not", () => {
  const shown = mockSummary(2, {
    recipient_name: "Alpha Decorators",
    net_amount: "860000.0000",
  });
  expect(column("recipient").value(shown)).toBe("Alpha Decorators");
  expect(column("net_amount").value(shown)).toContain("860000.0000");
  const hidden = mockSummary(3, { recipient_name: null, net_amount: null });
  expect(column("recipient").value(hidden)).toBe(
    `Party reference ${hidden.recipient_party_id}`,
  );
  expect(column("net_amount").value(hidden)).toBe("Not shown");
  expect(
    column("recipient").value(mockSummary(4, { recipient_party_id: null })),
  ).toBe("Not specified");
  // Neither value is sortable: a hidden value must not be probeable through the order.
  expect(column("net_amount").sortable).toBeFalsy();
  expect(column("recipient").sortable).toBeFalsy();
});

test("the list contract keeps the new values exact, rejects a malformed amount and still reads an older page", () => {
  const page = (row: Record<string, unknown>) => ({
    data: [row],
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
  });
  const row = { ...mockSummary(2) } as Record<string, unknown>;
  expect(
    readListPage(page({ ...row, recipient_name: "A", net_amount: "1.5000" }))
      .data[0]!.net_amount,
  ).toBe("1.5000");
  expect(() => readListPage(page({ ...row, net_amount: "1e3" }))).toThrow();
  expect(() => readListPage(page({ ...row, net_amount: 12.5 }))).toThrow();
  delete row.recipient_name;
  delete row.net_amount;
  expect(readListPage(page(row)).data[0]!.net_amount).toBeUndefined();
});
