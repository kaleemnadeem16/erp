import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import {
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import {
  QUOTATION_ISSUE,
  DOCUMENT_VIEW,
} from "../../../packages/core-ui/src/features/quotations/quotation-milestone-access";
import type { Outcome, WritesFixture } from "./fixtures/quotation-writes";

// The issue screens at 390px: readiness with every kind of blocker, the confirm dialog, and the issued record in each availability.
const fixtureScript = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-writes.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;

// The compiled application CSS (apps/main/dist, built before the unit project runs) so class-based layout and touch-target rules are real.
const assets = new URL("../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");

async function mount(
  page: Page,
  script: Record<string, Outcome[]>,
  permissions: string[],
) {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en"><head><title>Issue phone fixture</title><style>${css}</style></head><body><div id="root"></div></body></html>`,
  );
  const fixture: WritesFixture = {
    screen: "issue",
    dev: true,
    permissions,
    script,
  };
  await page.evaluate((f) => {
    window.__fixture = f;
  }, fixture);
  await page.addScriptTag({ content: fixtureScript });
}

async function expectPhoneSafe(page: Page) {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - window.innerWidth,
  );
  expect(overflow).toBeLessThanOrEqual(0);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("button, a[href]")]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .filter((el) => el.getBoundingClientRect().height < 44)
      .map((el) => (el.textContent ?? "").trim()),
  );
  expect(small).toEqual([]);
}

const ALL = [QUOTATION_VIEW, QUOTATION_PREPARE, QUOTATION_ISSUE, DOCUMENT_VIEW];
const blockers = [
  ["RECIPIENT_REQUIRED", "HEADER"],
  ["VALID_THROUGH_PAST", "HEADER"],
  ["TAX_CLASSIFICATION_REQUIRED", "TAX"],
  ["TAX_CLASSIFICATION_REQUIRED", "TAX"],
  ["CONTROLS_NOT_CONFIGURED", "CONTROLS"],
  ["CONTROL_FAILED", "CONTROLS"],
  ["SOMETHING_UNKNOWN", "LINE"],
].map(([code, scope], i) => ({
  code,
  scope,
  line_id: scope === "HEADER" ? null : i + 1,
}));

test("readiness with many blockers and the confirm dialog fit a 390px screen", async ({
  page,
}) => {
  await mount(
    page,
    {
      readiness: [
        {
          ok: {
            revision_id: 12,
            input_generation: 4,
            issue_ready: false,
            blockers,
          },
        },
      ],
    },
    ALL,
  );
  await expect(page.getByText("Not ready")).toBeVisible();
  await expectPhoneSafe(page);
  await expect(
    page.getByRole("button", { name: "Issue quotation" }),
  ).toBeDisabled();

  await mount(
    page,
    {
      readiness: [
        {
          ok: {
            revision_id: 12,
            input_generation: 4,
            issue_ready: true,
            blockers: [],
          },
        },
      ],
    },
    ALL,
  );
  await page.getByRole("button", { name: "Issue quotation" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.waitForTimeout(600); // let the open animation (scale) settle before measuring
  await expectPhoneSafe(page);
});

for (const availability of ["AVAILABLE", "EXPIRED", "WITHDRAWN", "REPLACED"]) {
  test(`the issued record (${availability}) fits a 390px screen and links to the PDF`, async ({
    page,
  }) => {
    const record = {
      revision_id: 12,
      family_id: 8,
      official_number: "QUO-2026-0042-WITH-A-LONG-SUFFIX-FOR-OVERFLOW",
      issued_at: "2026-10-30T10:15:00Z",
      prepared_by: 5,
      issued_by: 9,
      currency_id: 3,
      pricing_date: "2026-10-27",
      valid_through_local_date: "2026-11-30",
      expires_at: "2026-12-01T00:00:00Z",
      availability,
      totals: {
        net_total: "1000.00",
        optional_net_total: null,
        tax_total: "150.00",
        gross_total: "1150.00",
      },
    };
    await mount(
      page,
      {
        readiness: [{ status: 422, reason_code: "QUOTATION_NOT_EDITABLE" }],
        issued: [{ ok: record }],
      },
      ALL,
    );
    await expect(page.getByText("QUO-2026-0042")).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Official PDF and delivery" }),
    ).toBeVisible();
    await expectPhoneSafe(page);
  });
}

test("the PDF link is hidden from someone without document access", async ({
  page,
}) => {
  const record = {
    revision_id: 12,
    family_id: 8,
    official_number: "QUO-2026-0042",
    issued_at: "2026-10-30T10:15:00Z",
    prepared_by: 5,
    issued_by: 9,
    currency_id: 3,
    pricing_date: "2026-10-27",
    valid_through_local_date: "2026-11-30",
    expires_at: "2026-12-01T00:00:00Z",
    availability: "EXPIRED",
    totals: null,
  };
  await mount(
    page,
    {
      readiness: [{ status: 422, reason_code: "QUOTATION_NOT_EDITABLE" }],
      issued: [{ ok: record }],
    },
    [QUOTATION_VIEW, QUOTATION_PREPARE],
  );
  await expect(page.getByText("Expired", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: /Official PDF/ })).toHaveCount(0);
});

test("the issue screen has a heading and a way back, and the confirm dialog gives focus back to the Issue button", async ({
  page,
}) => {
  await mount(
    page,
    {
      readiness: [
        {
          ok: {
            revision_id: 12,
            input_generation: 4,
            issue_ready: true,
            blockers: [],
          },
        },
      ],
    },
    ALL,
  );
  await expect(
    page.getByRole("heading", { level: 1, name: "Issue quotation" }),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "Back" })).toBeVisible();
  const issue = page.getByRole("button", { name: "Issue quotation" });
  await issue.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(issue).toBeFocused();
});

test("someone who may issue but not prepare is told why issuing is not offered, and no readiness request is made", async ({
  page,
}) => {
  await mount(page, { readiness: [] }, [QUOTATION_VIEW, QUOTATION_ISSUE]);
  await expect(
    page.getByText("Issuing is not available to you here"),
  ).toBeVisible();
  await expect(page.getByText(/duty to prepare quotations/)).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Issue quotation" }),
  ).toHaveCount(0);
  await expectPhoneSafe(page);
});
