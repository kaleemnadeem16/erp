import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

const base = {
  id: 91001,
  subject_type: "PURCHASE_ORDER",
  subject_id: 91001,
  status: "PENDING",
  summary: {
    number: "Draft #91001",
    supplier: "Punjab Pigments",
    order_date: "2026-10-06",
  },
  policy: {
    id: 1,
    code: "review",
    version_no: 1,
    name: "Technical then commercial review",
  },
  submitted_by: { id: 12, name: "Demo Buyer" },
  submitted_at: "2026-10-06T09:00:00Z",
  closed_at: null,
  closing_reason: null,
  closing_reason_visible: true,
  amount_base: "95000.0000",
  evidence: {},
  evidence_visible: true,
  document_review_required: false,
  document_review_ready: true,
  can_decide: true,
  my_step_id: 1001,
  steps: [
    {
      id: 1001,
      stage: 1,
      name: "Technical review",
      status: "OPEN",
      required_approvals: 1,
      approved_count: 0,
      deadline_at: null,
      overdue: false,
      uses_authority_limit: false,
      decisions: [],
      review_profile: "OPERATIONAL",
    },
    {
      id: 1002,
      stage: 2,
      name: "Commercial approval",
      status: "WAITING",
      required_approvals: 1,
      approved_count: 0,
      deadline_at: null,
      overdue: false,
      uses_authority_limit: true,
      decisions: [],
      review_profile: "COMMERCIAL",
    },
  ],
};
const overdue = {
  ...base,
  id: 91002,
  subject_id: 91002,
  summary: {
    ...base.summary,
    number: "Draft #91002",
    supplier: "PakChem Industries",
  },
  steps: [
    { ...base.steps[0], overdue: true, deadline_at: "2026-10-05T09:00:00Z" },
  ],
};
const approved = {
  ...base,
  status: "APPROVED",
  can_decide: false,
  my_step_id: null,
  closed_at: "2026-10-07T09:00:00Z",
  steps: [
    {
      ...base.steps[0],
      status: "APPROVED",
      approved_count: 1,
      decisions: [
        {
          id: 30,
          decision: "APPROVED",
          decided_by: { id: 5, name: "Demo Approver" },
          acting_for: null,
          reason: "Checked specifications",
          reason_visible: true,
          decided_at: "2026-10-07T09:00:00Z",
        },
      ],
    },
  ],
};
async function fixtures(
  page: Page,
  layout: "table" | "cards" | null = "cards",
) {
  await page.addInitScript((layout) => {
    if (layout)
      localStorage.setItem("erp.list-view.approvals-workspace", layout);
  }, layout);
  await page.route("**/api/v1/approval-requests/inbox", (route) =>
    route.fulfill({ json: { data: [base, overdue] } }),
  );
  await page.route("**/api/v1/approval-requests/91001", (route) =>
    route.fulfill({
      json: { data: page.url().includes("view=history") ? approved : base },
    }),
  );
  await page.route(/\/api\/v1\/approval-requests\?/, (route) => {
    const pageNumber = Number(
      new URL(route.request().url()).searchParams.get("page") ?? 1,
    );
    return route.fulfill({
      json: {
        data: [approved],
        links: {},
        meta: {
          total: 26,
          current_page: pageNumber,
          last_page: 2,
          per_page: 25,
          from: 1,
          to: 25,
          links: [],
          path: null,
        },
      },
    });
  });
}
for (const mode of ["light", "dark"] as const) {
  test(`approval workspace ${mode}: cards, search, overdue and side-panel context`, async ({
    page,
  }) => {
    await page.addInitScript(
      (mode) =>
        localStorage.setItem(
          "erp.appearance",
          JSON.stringify({ mode, theme: "slate" }),
        ),
      mode,
    );
    await fixtures(page);
    await page.setViewportSize({ width: 1536, height: 1024 });
    await page.goto("/approvals");
    await expect(page.getByRole("article")).toHaveCount(2);
    await page.screenshot({
      path: `test-results/approvals-draft-${mode}.png`,
      fullPage: true,
      animations: "disabled",
    });
    await page.getByRole("button", { name: /^Overdue/ }).click();
    await expect(page.getByRole("article")).toHaveCount(1);
    await expect(page.getByRole("article")).toContainText("PakChem Industries");
    await page.getByRole("button", { name: /^Waiting for me/ }).click();
    await expect(
      page.getByRole("button", { name: /^Waiting for me/ }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(page.getByRole("article")).toHaveCount(2);
    await page
      .getByRole("textbox", { name: "Search approvals" })
      .fill("Punjab");
    await expect(page.getByRole("article")).toHaveCount(1);
    await page.getByRole("button", { name: "Review & decide" }).click();
    const sheet = page.getByRole("dialog");
    await expect(sheet).toBeVisible();
    await sheet.getByRole("button", { name: "Reject", exact: true }).click();
    await expect(sheet.getByRole("alert")).toHaveText(
      "Say why you are rejecting this.",
    );
    await page.screenshot({
      path: `test-results/approvals-review-${mode}.png`,
      fullPage: true,
      animations: "disabled",
    });
    const scan = await new AxeBuilder({ page }).analyze();
    expect(
      scan.violations.filter((issue) =>
        ["serious", "critical"].includes(issue.impact ?? ""),
      ),
      JSON.stringify(scan.violations),
    ).toEqual([]);
    await sheet.getByRole("button", { name: "Close", exact: true }).click();
    await expect(sheet).toHaveCount(0);
    await expect(page).toHaveURL(/q=Punjab/);
    await expect(
      page.getByRole("textbox", { name: "Search approvals" }),
    ).toHaveValue("Punjab");
    await page.reload();
    await expect(page.getByRole("article")).toHaveCount(1);
  });
}
test("history sends server outcome filters, paginates, and shows recorded decisions", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto("/approvals");
  await page.getByRole("button", { name: "All approvals & history" }).click();
  await page.locator('button[aria-controls="approval-filters"], button[aria-controls="approval-mobile-filters"]').click();
  await page.getByRole("combobox", { name: "Outcome" }).click();
  const response = page.waitForRequest(
    (request) =>
      request.url().includes("approval-requests?") &&
      new URL(request.url()).searchParams.get("filter[status]") === "APPROVED",
  );
  await page.getByRole("option", { name: "Approved", exact: true }).click();
  await response;
  await expect(
    page.getByRole("navigation", { name: "Approval history pages" }),
  ).toContainText("26 approval requests");
  const next = page.waitForRequest(
    (request) =>
      request.url().includes("approval-requests?") &&
      new URL(request.url()).searchParams.get("page") === "2",
  );
  await page.getByRole("button", { name: "Next page", exact: true }).click();
  await next;
  await expect(page).toHaveURL(/page=2/);
  await page.getByRole("button", { name: "View details", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText(
    "Approved by Demo Approver",
  );
  await expect(page.getByRole("dialog")).toContainText(
    "Checked specifications",
  );
});
test("non-auditor cannot open or fetch company history from a bookmarked view", async ({
  page,
}) => {
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.permissions = [];
    await route.fulfill({ response, json: body });
  });
  await fixtures(page);
  const historyReads: string[] = [];
  page.on("request", (request) => {
    if (/\/approval-requests\?/.test(request.url()))
      historyReads.push(request.url());
  });
  await page.goto("/approvals?view=history");
  await expect(page.getByRole("article")).toHaveCount(2);
  await expect(
    page.getByRole("button", { name: "All approvals & history" }),
  ).toHaveCount(0);
  expect(historyReads).toEqual([]);
});
for (const mode of ["light", "dark"] as const) {
test(`mobile ${mode} cards, filters and review stay within the viewport`, async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 812 });
  await page.addInitScript((mode) =>
    localStorage.setItem(
      "erp.appearance",
      JSON.stringify({ mode, theme: "slate" }),
    ),
    mode,
  );
  await fixtures(page);
  await page.goto("/approvals");
  await expect(page.getByRole("article")).toHaveCount(2);
  await expect(page.getByRole("textbox", { name: "Search approvals" })).toHaveCSS("height", "40px");
  await expect(page.locator('button[aria-controls="approval-mobile-filters"]')).toHaveCSS("height", "40px");
  await expect(page.locator(".ui-list-view-toggle button").first()).toHaveCSS("height", "36px");
  const tabs = page.locator(".approval-view-tabs");
  const bounds = await tabs.locator("button").evaluateAll((buttons) =>
    buttons.map((button) => ({ top: button.getBoundingClientRect().top, height: button.getBoundingClientRect().height }))
  );
  expect(new Set(bounds.map((rect) => rect.top)).size).toBe(1);
  expect(bounds.every((rect) => rect.height === 36)).toBe(true);
  await expect(page.locator(".approval-card-policy").first()).toHaveCSS("white-space", "normal");
  await expect(page.locator(".approval-review-button").first()).toHaveCSS("min-height", "44px");
  await page.screenshot({ path: `/tmp/erp-approvals-mobile-${mode}.png`, fullPage: true, animations: "disabled" });
  await page.locator('button[aria-controls="approval-filters"], button[aria-controls="approval-mobile-filters"]').click();
  await expect(
    page.getByRole("region", { name: "Approval filters" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth - innerWidth,
    ),
  ).toBeLessThanOrEqual(1);
  const filterSheet = page.getByRole("dialog", { name: "Approval filters" });
  await expect(filterSheet).toBeVisible();
  await expect.poll(() => filterSheet.evaluate(node => Math.abs(node.getBoundingClientRect().bottom - innerHeight))).toBeLessThanOrEqual(1);
  await page.getByLabel("Document ID", { exact: true }).fill("91001");
  await page.getByRole("button", { name: "Done", exact: true }).click();
  await expect(filterSheet).toBeHidden();
  await expect(page.getByRole("article")).toHaveCount(1);
  await page.locator('button[aria-controls="approval-mobile-filters"]').click();
  await expect(page.getByLabel("Document ID", { exact: true })).toHaveValue("91001");
  await filterSheet.getByRole("button", { name: "Clear filters", exact: true }).click();
  await filterSheet.getByRole("button", { name: "Done", exact: true }).click();
  const heading = page.locator(".approval-card-heading").first();
  const identity = await heading.locator(":scope > div").first().boundingBox();
  const status = await heading.locator(":scope > div").last().boundingBox();
  expect(status!.x).toBeGreaterThan(identity!.x);
  expect(Math.abs(status!.y - identity!.y)).toBeLessThanOrEqual(1);
  await page.getByRole("button", { name: "Review & decide" }).last().click();
  await expect(page.getByRole("dialog")).toBeVisible();
  expect(
    await page
      .getByRole("dialog")
      .evaluate((node) => node.scrollWidth - node.clientWidth),
  ).toBeLessThanOrEqual(1);
  await page.screenshot({
    path: `test-results/approvals-draft-mobile-${mode}.png`,
    fullPage: true,
    animations: "disabled",
  });
});

}

test("recording a decision refreshes both the review and the waiting list", async ({
  page,
}) => {
  await fixtures(page);
  let decided = false;
  await page.route("**/api/v1/approval-requests/inbox", (route) =>
    route.fulfill({ json: { data: decided ? [overdue] : [base, overdue] } }),
  );
  await page.route("**/api/v1/approval-requests/91001", (route) =>
    route.fulfill({ json: { data: decided ? approved : base } }),
  );
  await page.route("**/api/v1/approval-requests/91001/decide", (route) => {
    expect(route.request().postDataJSON()).toMatchObject({
      decision: "APPROVED",
      step_id: 1001,
      reason: "Reviewed evidence",
    });
    decided = true;
    return route.fulfill({ json: { data: approved } });
  });
  await page.goto("/approvals");
  await page
    .getByRole("article", { name: "Approval of Draft #91001" })
    .getByRole("button", { name: "Review & decide" })
    .click();
  const sheet = page.getByRole("dialog");
  await sheet
    .getByLabel("Reason (required to reject)")
    .fill("Reviewed evidence");
  await sheet.getByRole("button", { name: "Approve", exact: true }).click();
  await expect(sheet).toContainText("Approved by Demo Approver");
  await expect(
    sheet.getByRole("button", { name: "Approve", exact: true }),
  ).toHaveCount(0);
  await sheet.getByRole("button", { name: "Close", exact: true }).click();
  await expect(sheet).toHaveCount(0);
  await expect(page.getByRole("article")).toHaveCount(1);
  await expect(page.getByRole("article")).toContainText("PakChem Industries");
});

test("a failed inbox reports an error rather than an empty approval list", async ({
  page,
}) => {
  await page.route("**/api/v1/approval-requests/inbox", (route) =>
    route.fulfill({ status: 403, json: { message: "Access refused" } }),
  );
  await page.goto("/approvals");
  await expect(page.getByRole("alert")).toContainText(
    "Couldn't load approvals.",
  );
  await expect(page.getByText("You’re all caught up")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});

for (const mode of ["light", "dark"] as const) {
  test(`approval layouts ${mode}: pagination and saved table/card choice`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1536, height: 1024 });
    await page.addInitScript(
      (mode) =>
        localStorage.setItem(
          "erp.appearance",
          JSON.stringify({ mode, theme: "slate" }),
        ),
      mode,
    );
    await fixtures(page, null);
    const many = Array.from({ length: 23 }, (_, index) => ({
      ...base,
      id: 92000 + index,
      subject_id: 92000 + index,
      summary: { ...base.summary, number: `Draft #${92000 + index}` },
    }));
    await page.route("**/api/v1/approval-requests/inbox", (route) =>
      route.fulfill({ json: { data: many } }),
    );
    await page.goto("/approvals");
    await expect(
      page.getByRole("button", { name: "Table view", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(page.locator("tbody tr")).toHaveCount(10);
    // Selected views stay filled; repeated review actions use text links.
    const review = page.locator(".approval-review-button").first();
    const selectedView = page.getByRole("button", { name: "Table view", exact: true });
    await expect(review).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await review.hover();
    await expect(review).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await page.mouse.move(0, 0);
    for (const button of [selectedView]) {
      await expect(button).toHaveCSS("opacity", "1");
      expect(await button.evaluate((element) => {
        const style = getComputedStyle(element);
        const probe = document.createElement("span");
        probe.style.backgroundColor = style.getPropertyValue("--ui-brand-primary");
        element.appendChild(probe);
        const expected = getComputedStyle(probe).backgroundColor;
        probe.remove();
        return style.backgroundColor === expected;
      })).toBe(true);
      await button.hover();
      await expect(button).toHaveCSS("opacity", "1");
      expect(await button.evaluate((element) =>
        getComputedStyle(element).backgroundColor.includes("rgba")
      )).toBe(false);
      await page.mouse.move(0, 0);
    }
    await expect(page.getByRole("button", { name: "Previous page", exact: true })).toHaveCSS("opacity", "1");

    await expect(
      page.getByRole("navigation", { name: "Approval pages", exact: true }),
    ).toContainText("1–10 of 23 approval requests");
    await page.screenshot({
      path: `test-results/approvals-table-${mode}.png`,
      fullPage: true,
      animations: "disabled",
    });
    await page.getByRole("button", { name: "Next page" }).click();
    await expect(page.locator("tbody tr")).toHaveCount(10);
    await expect(page).toHaveURL(/page=2/);
    await page.getByRole("button", { name: "Card view", exact: true }).click();
    await expect(page.getByRole("article")).toHaveCount(10);
    await expect(
      page.getByRole("navigation", { name: "Approval pages", exact: true }),
    ).toContainText("11–20 of 23 approval requests");
    await page.screenshot({
      path: `test-results/approvals-compact-cards-${mode}.png`,
      fullPage: true,
      animations: "disabled",
    });
    await page.reload();
    await expect(
      page.getByRole("button", { name: "Card view", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(page.getByRole("article")).toHaveCount(10);
    await page.getByRole("button", { name: "Last page" }).click();
    await expect(page.getByRole("article")).toHaveCount(3);
    await page.getByRole("combobox", { name: "Rows per page" }).click();
    await page.getByRole("option", { name: "25", exact: true }).click();
    await expect(page.getByRole("article")).toHaveCount(23);
    await expect(
      page.getByRole("button", { name: "Next page" }),
    ).toBeDisabled();
    await page
      .getByRole("textbox", { name: "Search approvals" })
      .fill("Draft #92000");
    await expect(page.getByRole("article")).toHaveCount(1);
    await expect(
      page.getByRole("navigation", { name: "Approval pages" }),
    ).toContainText("1–1 of 1 approval requests");
    const scan = await new AxeBuilder({ page }).analyze();
    expect(
      scan.violations.filter((issue) =>
        ["serious", "critical"].includes(issue.impact ?? ""),
      ),
      JSON.stringify(scan.violations),
    ).toEqual([]);
  });
}

test("view buttons focus individually and filters animate in both directions", async ({
  page,
}) => {
  await fixtures(page, null);
  await page.goto("/approvals");
  const group = page.getByRole("group", { name: "Which approvals to show" });
  await group.getByRole("button", { name: /^Overdue/ }).click();
  expect(
    await group.evaluate((element) => getComputedStyle(element).boxShadow),
  ).toBe("none");
  await page.keyboard.press("Tab");
  await group.getByRole("button", { name: /^Waiting for me/ }).focus();
  expect(
    await group
      .getByRole("button", { name: /^Waiting for me/ })
      .evaluate((element) => getComputedStyle(element).boxShadow),
  ).not.toBe("none");
  const panel = page.locator("#approval-filters");
  // Register before interaction so a busy renderer cannot make the assertion miss a short transition.
  await panel.evaluate((element) => {
    element.setAttribute("data-test-motion-count", "0");
    element.addEventListener("transitionrun", (event) => {
      if (event.target !== element) return;
      element.setAttribute(
        "data-test-motion-count",
        String(Number(element.getAttribute("data-test-motion-count")) + 1),
      );
    });
  });
  await page.locator('button[aria-controls="approval-filters"], button[aria-controls="approval-mobile-filters"]').click();
  await expect
    .poll(() => panel.getAttribute("data-test-motion-count").then(Number))
    .toBeGreaterThan(0);
  await expect(panel).toHaveCSS("opacity", "1");
  await expect
    .poll(() => panel.evaluate((element) => element.getAnimations().length))
    .toBe(0);
  await page.getByLabel("Document ID", { exact: true }).fill("42");
  await panel.evaluate((element) =>
    element.setAttribute("data-test-motion-count", "0"),
  );
  await page.locator('button[aria-controls="approval-filters"], button[aria-controls="approval-mobile-filters"]').click();
  await expect
    .poll(() => panel.getAttribute("data-test-motion-count").then(Number))
    .toBeGreaterThan(0);
  await expect(panel).toHaveAttribute("inert", "");
  await expect(panel).toHaveCSS("opacity", "0");
  await expect(page.locator("#approval-document-id")).toHaveValue("42");
  await page.emulateMedia({ reducedMotion: "reduce" });
  await expect(panel).toHaveCSS("transition-property", "none");
});

test("approval coverage opens an animated management section and a separate form dialog", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/v1/approval-delegations", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 902,
            delegator_id: 12,
            delegate_id: 13,
            delegator_name: "Buyer",
            delegate_name: "Colleague",
            valid_from: "2026-10-01",
            valid_to: "2026-11-01",
            subject_type: "PURCHASE_ORDER",
            reason: "Annual leave",
            in_force: true,
            revoked_at: null,
          },
        ],
      },
    }),
  );
  await page.goto("/approvals");
  await expect(
    page.getByText("Annual leave", { exact: true }),
  ).not.toBeVisible();
  await page.getByRole("button", { name: "Manage coverage" }).click();
  await expect(page.getByText("Annual leave", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Revoke delegation to Colleague" })
    .click();
  await expect(
    page.getByRole("dialog", { name: "End approval coverage?" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Keep coverage" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page
    .getByRole("button", { name: "Delegate my approvals", exact: true })
    .click();
  const form = page.getByRole("dialog", { name: "Delegate my approvals" });
  await expect(form).toBeVisible();
  await form.getByRole("button", { name: "Add delegation" }).click();
  await expect(form.getByText("Choose a start date.")).toBeVisible();
  await expect(form.getByText("Enter a reason.")).toBeVisible();
  const scan = await new AxeBuilder({ page }).analyze();
  expect(
    scan.violations.filter((issue) =>
      ["serious", "critical"].includes(issue.impact ?? ""),
    ),
    JSON.stringify(scan.violations),
  ).toEqual([]);
  await page.screenshot({
    path: "test-results/approval-coverage-dialog.png",
    fullPage: true,
    animations: "disabled",
  });
});

test("incoming coverage does not offer an unauthorised revoke action", async ({
  page,
}) => {
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.permissions = [];
    body.data.id = 13;
    await route.fulfill({ response, json: body });
  });
  await fixtures(page);
  await page.route("**/api/v1/approval-delegations", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 902,
            delegator_id: 12,
            delegate_id: 13,
            delegator_name: "Buyer",
            delegate_name: "Colleague",
            valid_from: "2026-10-01",
            valid_to: "2026-11-01",
            subject_type: "PURCHASE_ORDER",
            reason: "Annual leave",
            in_force: true,
            revoked_at: null,
          },
        ],
      },
    }),
  );
  await page.goto("/approvals");
  await page.getByRole("button", { name: "Manage coverage" }).click();
  await expect(page.getByText("Annual leave", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("button", { name: /Revoke delegation/ }),
  ).toHaveCount(0);
});

test("coverage searches colleagues remotely, saves validated dates and confirms revocation", async ({
  page,
}) => {
  await fixtures(page);
  let created = false,
    revoked = false;
  const cover = {
    id: 903,
    delegator_id: 12,
    delegate_id: 8001,
    delegator_name: "Buyer",
    delegate_name: "Remote Colleague",
    valid_from: "2026-10-08",
    valid_to: "2026-10-09",
    subject_type: null,
    reason: "Coverage test",
    in_force: true,
    revoked_at: null,
  };
  await page.route("**/api/v1/approval-delegations", (route) => {
    if (route.request().method() === "POST") {
      expect(route.request().postDataJSON()).toMatchObject({
        delegate_id: 8001,
        valid_from: "2026-10-08",
        valid_to: "2026-10-09",
        reason: "Coverage test",
        subject_type: null,
      });
      created = true;
      return route.fulfill({ status: 201, json: { data: cover } });
    }
    return route.fulfill({
      json: {
        data: created
          ? [
              {
                ...cover,
                in_force: !revoked,
                revoked_at: revoked ? "2026-10-07T10:00:00Z" : null,
              },
            ]
          : [],
      },
    });
  });
  await page.route("**/api/v1/approval-delegations/903", (route) => {
    expect(route.request().method()).toBe("DELETE");
    revoked = true;
    return route.fulfill({
      json: {
        data: { ...cover, in_force: false, revoked_at: "2026-10-07T10:00:00Z" },
      },
    });
  });
  await page.route(/\/api\/v1\/members\?/, (route) => {
    const search = new URL(route.request().url()).searchParams.get("search");
    return route.fulfill({
      json: {
        data: search
          ? [
              {
                id: 8001,
                name: "Remote Colleague",
                email: "colleague@example.test",
              },
            ]
          : [],
        meta: { total: search ? 1 : 0 },
      },
    });
  });
  await page.goto("/approvals");
  await page
    .getByRole("button", { name: "Delegate my approvals", exact: true })
    .click();
  const dialog = page.getByRole("dialog", { name: "Delegate my approvals" });
  await dialog.getByRole("combobox", { name: "Covered by" }).click();
  const remote = page.waitForRequest(
    (request) =>
      request.url().includes("/members?") &&
      new URL(request.url()).searchParams.get("search") === "Remote Colleague",
  );
  await page.getByPlaceholder("Search colleagues…").fill("Remote Colleague");
  await remote;
  await page.getByRole("option", { name: /Remote Colleague/ }).click();
  await expect(
    dialog.getByRole("combobox", { name: "Covered by" }),
  ).toContainText("Remote Colleague");
  for (const [name, day] of [
    ["From", "08"],
    ["To", "09"],
  ]) {
    const field = dialog.getByRole("group", { name, exact: true });
    await field
      .getByRole("spinbutton", { name: "Month", exact: true })
      .fill("10");
    await field
      .getByRole("spinbutton", { name: "Day", exact: true })
      .fill(day!);
    await field
      .getByRole("spinbutton", { name: "Year", exact: true })
      .fill("2026");
  }
  await dialog.getByLabel("Reason", { exact: true }).fill("Coverage test");
  await dialog.getByRole("button", { name: "Add delegation" }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByText("Coverage test", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Revoke delegation to Remote Colleague" })
    .click();
  await page.getByRole("button", { name: "End coverage", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByText("Revoked", { exact: true })).toBeVisible();
});
