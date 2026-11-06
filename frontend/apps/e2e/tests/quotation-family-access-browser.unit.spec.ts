import { test, expect, type Page, type Request } from "@playwright/test";
import { buildSync } from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
import AxeBuilder from "@axe-core/playwright";

test.use({ locale: "en-GB", timezoneId: "UTC" });
const origin = "http://quotation-access.test";
const bundle = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-family-access.tsx", import.meta.url).pathname,
  ],
  bundle: true,
  write: false,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  define: { "process.env.NODE_ENV": '"production"' },
}).outputFiles[0]!.text;
const assets = new URL("../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");
const secret = "927361480.4183";
function snapshot() {
  return {
    family_id: 11,
    record_scope: "ALL",
    created_by: 12,
    created_by_name: "Original maker",
    responsible_owner: { id: 13, name: "Current owner", status: "SUSPENDED" },
    access_unit: { id: 14, name: "Main unit", status: "ACTIVE" },
    baseline_state: "NATIVE",
    ownership_generation: 3,
    access_generation: 7,
    allowed_actions: {
      prepare: true,
      assign: true,
      delegate_view: true,
      delegate_prepare: true,
      revoke: true,
    },
    ownership_history: [
      {
        id: 15,
        generation: 3,
        kind: "REASSIGNED",
        old_owner_id: 12,
        old_owner_name: "Previous owner",
        new_owner_id: 13,
        new_owner_name: "Current owner",
        old_unit_id: null,
        old_unit_name: null,
        new_unit_id: 14,
        new_unit_name: "Main unit",
        actor_id: 16,
        actor_name: "Earlier governor",
        recorded_at: "2026-11-05T00:00:00.000000Z",
        reason: secret,
      },
    ],
    ownership_history_has_more: false,
    ownership_history_next_generation: null,
    shares: [
      {
        id: 17,
        granted_generation: 7,
        recipient_id: 18,
        recipient_name: "Existing collaborator",
        issuer_id: 16,
        issuer_name: "Earlier governor",
        level: "VIEW",
        starts_at: "2026-11-05T00:00:00.000000Z",
        ends_at: null,
        recorded_at: "2026-11-05T00:00:00.000000Z",
        revoked_by: null,
        revoked_by_name: null,
        revoked_at: null,
        status: "ACTIVE",
        reason: secret,
        issuer_authorization_generation: 301,
      },
    ],
    shares_has_more: false,
    shares_next_generation: null,
  };
}
type Reply = { status: number; body: unknown } | { abort: true };
type Seen = {
  path: string;
  method: string;
  key: string | undefined;
  body: Record<string, unknown> | null;
  url: URL;
};
async function mount(
  page: Page,
  reply: (request: Seen) => Reply | Promise<Reply>,
  search = "?state=DRAFT&page=3",
) {
  const seen: Seen[] = [];
  await page.route("**/*", async (route) => {
    const request: Request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    if (url.pathname.startsWith("/api/")) {
      const item: Seen = {
        path: url.pathname,
        url,
        method: request.method(),
        key: request.headers()["idempotency-key"],
        body: request.postData()
          ? (request.postDataJSON() as Record<string, unknown>)
          : null,
      };
      seen.push(item);
      const response = await reply(item);
      if ("abort" in response) return route.abort("failed");
      return route.fulfill({
        status: response.status,
        contentType: "application/json",
        body: JSON.stringify(response.body),
      });
    }
    return route.fulfill({
      contentType: "text/html",
      body: '<!doctype html><html lang="en"><head><title>Quotation access</title></head><body><div id="root"></div></body></html>',
    });
  });
  await page.goto(origin + "/quotations/12" + search);
  await page.addStyleTag({ content: css });
  await page.addScriptTag({ content: bundle });
  return seen;
}
function candidates(
  rows = [{ id: 53, name: "New owner" }],
  next: string | null = null,
) {
  return {
    status: 200,
    body: { data: rows, meta: { next_cursor: next, prev_cursor: null } },
  };
}
const outcome = (access = 8, replayed = false) => ({
  status: 200,
  body: {
    data: {
      family_id: 11,
      ownership_generation: 3,
      access_generation: access,
      changed: true,
      replayed,
    },
  },
});
async function choose(page: Page, label: string, name = "New owner") {
  await page
    .getByRole("dialog")
    .getByRole("combobox", { name: label, exact: true })
    .click();
  await page.getByRole("option", { name: new RegExp(name) }).click();
}

test("production entry denies before queries even with synthetic supplied grants", async ({
  page,
}) => {
  const seen = await mount(
    page,
    () => ({ status: 500, body: {} }),
    "?entry=public",
  );
  await expect(
    page.getByRole("heading", { name: "Quotation access fixture" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Access and responsibility" }),
  ).toHaveCount(0);
  expect(seen).toHaveLength(0);
});

test("named view has no management controls or private history and does not render unknown secrets", async ({
  page,
}) => {
  const data = {
    ...snapshot(),
    record_scope: "DELEGATED",
    allowed_actions: {
      prepare: false,
      assign: false,
      delegate_view: false,
      delegate_prepare: false,
      revoke: false,
    },
    ownership_history: undefined,
    shares: undefined,
    reason: secret,
  };
  const seen = await mount(page, () => ({ status: 200, body: { data } }));
  await expect(page.getByText("Named access shared with you")).toBeVisible();
  await expect(page.getByText("View only", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: /Change responsible|Share quotation|Revoke share/,
    }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Responsibility history" }),
  ).toHaveCount(0);
  await expect(page.locator("body")).not.toContainText(secret);
  expect(
    seen.every(
      (item) => item.method === "GET" && item.path.endsWith("/access"),
    ),
  ).toBe(true);
});

test("owner change confirms once and refreshes responsibility without changing creator", async ({
  page,
}) => {
  let data = snapshot();
  let release: () => void = () => {};
  const hold = new Promise<void>((resolve) => {
    release = resolve;
  });
  const seen = await mount(page, async (item) => {
    if (item.path.endsWith("/access-candidates")) return candidates();
    if (item.method === "POST") {
      await hold;
      data = {
        ...data,
        responsible_owner: { id: 53, name: "New owner", status: "ACTIVE" },
        ownership_generation: 4,
      };
      return {
        status: 200,
        body: {
          data: {
            family_id: 11,
            owner_id: 53,
            access_unit_id: 14,
            ownership_generation: 4,
            access_generation: 7,
            changed: true,
            replayed: false,
          },
        },
      };
    }
    return { status: 200, body: { data } };
  });
  await page.getByRole("button", { name: "Change responsible owner…" }).click();
  await choose(page, "Responsible owner");
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Cover the colleague's work");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await expect(page.getByLabel("Review access change")).toContainText(
    "New owner",
  );
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(page.getByRole("button", { name: "Applying…" })).toBeDisabled();
  expect(seen.filter((item) => item.method === "POST")).toHaveLength(1);
  release();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Quotation access and responsibility updated."),
  ).toBeVisible();
  await expect(page.getByText("New owner", { exact: true })).toBeVisible();
  await expect(page.getByText("Original maker", { exact: true })).toBeVisible();
  const post = seen.find((item) => item.method === "POST")!;
  expect(post.path).toBe("/api/v1/quotation-families/11/reassign-owner");
  expect(post.body).toEqual({
    owner_id: 53,
    reason: "Cover the colleague's work",
    expected_ownership_generation: 3,
    expected_access_generation: 7,
  });
  expect(post.key).toBeTruthy();
});

test("uncertain grant retry retains its exact key and intent after closing the form", async ({
  page,
}) => {
  let posts = 0;
  const seen = await mount(page, (item) => {
    if (item.path.endsWith("/access-candidates"))
      return candidates([{ id: 63, name: "New collaborator" }]);
    if (item.method === "POST") {
      posts++;
      return posts === 1 ? { abort: true } : outcome(8, true);
    }
    return { status: 200, body: { data: snapshot() } };
  });
  await page.getByRole("button", { name: "Share quotation…" }).click();
  await choose(page, "Recipient", "New collaborator");
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Help with the quotation");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(
    page.getByText(/The server may have applied this change/),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close for now" }).click();
  await page.getByRole("button", { name: "Share quotation…" }).click();
  await expect(
    page.getByText("Restored an unfinished change from this tab."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Retry same change" }).click();
  await expect(
    page.getByText("The earlier change was confirmed."),
  ).toBeVisible();
  const attempts = seen.filter((item) => item.method === "POST");
  expect(attempts).toHaveLength(2);
  expect(attempts[0]!.key).toBe(attempts[1]!.key);
  expect(attempts[0]!.body).toEqual(attempts[1]!.body);
});

for (const mode of ["light", "dark"] as const)
  test(`access metadata and confirmation stay usable on a phone in ${mode}`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mount(page, (item) =>
      item.path.endsWith("/access-candidates")
        ? candidates()
        : { status: 200, body: { data: snapshot() } },
    );
    await page.evaluate(
      (dark) => document.documentElement.classList.toggle("dark", dark),
      mode === "dark",
    );
    await expect(
      page.getByText("Inactive owner", { exact: true }),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(secret);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
    await page
      .getByRole("button", { name: "Change responsible owner…" })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page
      .getByRole("textbox", { name: "Reason", exact: true })
      .fill("A reviewed transfer");
    await page
      .getByRole("button", { name: "Review change", exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: "Confirm change" }),
    ).toBeInViewport();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
    const axe = await new AxeBuilder({ page }).analyze();
    expect(axe.violations).toEqual([]);
  });

test("stale refusal preserves inputs and requires explicit refresh before a new reviewed attempt", async ({
  page,
}) => {
  let data = snapshot();
  let posts = 0;
  const seen = await mount(page, (item) => {
    if (item.path.endsWith("/access-candidates"))
      return candidates([{ id: 63, name: "New collaborator" }]);
    if (item.method === "POST") {
      posts++;
      if (posts === 1) {
        data = { ...data, access_generation: 8 };
        return {
          status: 422,
          body: {
            message: "Stale",
            reason_code: "QUOTATION_OWNERSHIP_STALE",
            errors: { expected_access_generation: ["Stale"] },
          },
        };
      }
      return outcome(9);
    }
    return { status: 200, body: { data } };
  });
  await page.getByRole("button", { name: "Share quotation…" }).click();
  await choose(page, "Recipient", "New collaborator");
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Retain this explanation");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(
    page.getByText(/Responsibility or access has changed/),
  ).toBeVisible();
  await expect(
    page.getByRole("textbox", { name: "Reason", exact: true }),
  ).toHaveValue("Retain this explanation");
  await expect(
    page.getByRole("button", { name: "Review change", exact: true }),
  ).toBeDisabled();
  await page.getByRole("button", { name: "Refresh current details" }).click();
  await expect(
    page.getByRole("button", { name: "Review change", exact: true }),
  ).toBeEnabled();
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const attempts = seen.filter((item) => item.method === "POST");
  expect(attempts).toHaveLength(2);
  expect(attempts[0]!.key).not.toBe(attempts[1]!.key);
  expect(attempts[0]!.body?.expected_access_generation).toBe(7);
  expect(attempts[1]!.body?.expected_access_generation).toBe(8);
  expect(attempts[1]!.body?.reason).toBe("Retain this explanation");
});

test("lookup loads later cursor pages and retains the selected label across review", async ({
  page,
}) => {
  const seen = await mount(page, (item) => {
    if (item.path.endsWith("/access-candidates"))
      return item.url.searchParams.get("cursor")
        ? candidates([{ id: 53, name: "Later page owner" }])
        : candidates(
            [{ id: 52, name: "Earlier page owner" }],
            "next-page-token",
          );
    return { status: 200, body: { data: snapshot() } };
  });
  await page.getByRole("button", { name: "Change responsible owner…" }).click();
  await page
    .getByRole("dialog")
    .getByRole("combobox", { name: "Responsible owner", exact: true })
    .click();
  await expect(
    page.getByRole("option", { name: /Earlier page owner/ }),
  ).toBeVisible();
  const more = page.getByRole("button", { name: "Load more matches" });
  await more.focus();
  await page.keyboard.press("Enter");
  await page.getByRole("option", { name: /Later page owner/ }).click();
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Use a person beyond the first page");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await expect(page.getByLabel("Review access change")).toContainText(
    "Later page owner",
  );
  await page.getByRole("button", { name: "Back to change" }).click();
  await expect(
    page
      .getByRole("dialog")
      .getByRole("combobox", { name: "Responsible owner", exact: true }),
  ).toContainText("Later page owner");
  expect(
    seen.some(
      (item) => item.url.searchParams.get("cursor") === "next-page-token",
    ),
  ).toBe(true);
  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations).toEqual([]);
});

test("period disclosure retains values and prepare sharing sends explicit UTC instants", async ({
  page,
}) => {
  const seen = await mount(page, (item) =>
    item.path.endsWith("/access-candidates")
      ? candidates([{ id: 63, name: "New collaborator" }])
      : item.method === "POST"
        ? outcome()
        : { status: 200, body: { data: snapshot() } },
  );
  await page.getByRole("button", { name: "Share quotation…" }).click();
  await choose(page, "Recipient", "New collaborator");
  await page
    .getByRole("dialog")
    .getByRole("combobox", { name: "Access level" })
    .click();
  await page
    .getByRole("option", { name: "View and prepare", exact: true })
    .click();
  await page.getByRole("button", { name: "Access period" }).click();
  await page
    .getByLabel("Access starts", { exact: true })
    .fill("2030-01-02T09:00");
  await page
    .getByLabel("Access ends", { exact: true })
    .fill("2030-01-03T10:00");
  await page.getByRole("button", { name: "Hide access period" }).click();
  await expect(page.getByText("Start or end date set")).toBeVisible();
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Prepare the renewal");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await expect(page.getByLabel("Review access change")).toContainText(
    "View and prepare",
  );
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const post = seen.find((item) => item.method === "POST")!;
  expect(post.body?.level).toBe("PREPARE");
  expect(post.body?.starts_at).toBe("2030-01-02T09:00:00.000Z");
  expect(post.body?.ends_at).toBe("2030-01-03T10:00:00.000Z");
});

test("unit clear is explicit and dirty dismissal keeps or deliberately discards input", async ({
  page,
}) => {
  const seen = await mount(page, (item) =>
    item.path.endsWith("/access-candidates")
      ? candidates([{ id: 14, name: "Main unit" }])
      : item.method === "POST"
        ? outcome()
        : { status: 200, body: { data: snapshot() } },
  );
  await page.getByRole("button", { name: "Change access unit…" }).click();
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Clear the unit explicitly");
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page.getByText("Discard these unsaved changes?")).toBeVisible();
  await page.getByRole("button", { name: "Keep editing" }).click();
  await expect(
    page.getByRole("textbox", { name: "Reason", exact: true }),
  ).toHaveValue("Clear the unit explicitly");
  await page.getByRole("checkbox", { name: "No access unit" }).check();
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await expect(page.getByLabel("Review access change")).toContainText(
    "No access unit",
  );
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(
    seen.find((item) => item.method === "POST")!.body?.access_unit_id,
  ).toBeNull();
});

test("expired share revocation is reviewed and history cursors keep list context", async ({
  page,
}) => {
  const base = snapshot();
  const initial = {
    ...base,
    shares: [{ ...base.shares[0]!, status: "EXPIRED" }],
    shares_has_more: true,
    shares_next_generation: 7,
  };
  const seen = await mount(page, (item) => {
    if (item.method === "POST") return outcome();
    if (item.url.searchParams.has("shares_before"))
      return {
        status: 200,
        body: {
          data: {
            ...initial,
            shares: [
              {
                ...initial.shares[0],
                id: 9,
                granted_generation: 6,
                recipient_name: "Earlier collaborator",
              },
            ],
            shares_has_more: false,
            shares_next_generation: null,
          },
        },
      };
    return { status: 200, body: { data: initial } };
  });
  await page
    .getByRole("button", {
      name: "Revoke view share for Existing collaborator",
    })
    .click();
  await page
    .getByRole("textbox", { name: "Reason", exact: true })
    .fill("Close the expired share");
  await page
    .getByRole("button", { name: "Review change", exact: true })
    .click();
  await expect(page.getByLabel("Review access change")).toContainText(
    "Existing collaborator",
  );
  await page
    .getByRole("button", { name: "Confirm change", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(seen.find((item) => item.method === "POST")!.path).toBe(
    "/api/v1/quotation-families/11/revoke-access",
  );
  await page.getByRole("button", { name: "Older shares", exact: true }).click();
  await expect(
    page.getByText("Earlier collaborator", { exact: true }),
  ).toBeVisible();
  await expect(page.getByTestId("location")).toContainText("state=DRAFT");
  await expect(page.getByTestId("location")).toContainText("page=3");
  await expect(page.getByTestId("location")).toContainText(
    "access_shares_before=7",
  );
  expect(
    seen.some((item) => item.url.searchParams.get("shares_before") === "7"),
  ).toBe(true);
});

test("named preparation remains ordinary quotation work with no onward management", async ({
  page,
}) => {
  const data = {
    ...snapshot(),
    record_scope: "DELEGATED",
    allowed_actions: {
      prepare: true,
      assign: false,
      delegate_view: false,
      delegate_prepare: false,
      revoke: false,
    },
    ownership_history: undefined,
    shares: undefined,
  };
  await mount(page, () => ({ status: 200, body: { data } }));
  await expect(page.getByText("Named access shared with you")).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: /Change responsible|Change access unit|Share quotation|Revoke share/,
    }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Share history" }),
  ).toHaveCount(0);
  await expect(page.locator("body")).not.toContainText(secret);
});

test("invalid history cursor refuses before any family or directory query", async ({
  page,
}) => {
  const seen = await mount(
    page,
    () => ({ status: 500, body: {} }),
    "?page=3&access_shares_before=-1",
  );
  await expect(page.getByRole("alert")).toHaveText(
    "This history link is not valid.",
  );
  expect(seen).toHaveLength(0);
  await expect(
    page.getByRole("link", { name: "Back to quotations" }),
  ).toHaveAttribute("href", "/quotations?page=3&access_shares_before=-1");
});

test("foreign unknown and hidden family responses have the same non-disclosing display", async ({
  page,
}) => {
  for (const label of ["foreign", "unknown", "hidden"]) {
    const seen = await mount(page, () => ({
      status: 404,
      body: { message: "Not found." },
    }));
    await expect(page.getByRole("alert")).toBeVisible();
    await expect(page.getByText("Current owner", { exact: true })).toHaveCount(
      0,
    );
    await expect(
      page.getByRole("button", { name: "Share quotation", exact: true }),
    ).toHaveCount(0);
    expect(
      seen.every(
        (item) => item.method === "GET" && item.path.endsWith("/access"),
      ),
      label,
    ).toBe(true);
    await expect(page.locator("body")).not.toContainText(secret);
  }
});

for (const entry of ["record", "sheet"])
  test(`${entry} hides cached metadata after a fresh family denial`, async ({
    page,
  }) => {
    let allowed = true;
    await mount(
      page,
      (request) =>
        request.path.endsWith("/access")
          ? allowed
            ? { status: 200, body: { data: snapshot() } }
            : { status: 404, body: { message: "Not found." } }
          : {
              status: 200,
              body: {
                data: {
                  revision_id: 12,
                  family_id: 11,
                  revision_ordinal: 1,
                  state: "DRAFT",
                  input_generation: 2,
                  recipient_party_id: 23999,
                  currency_id: 3,
                  pricing_date: "2026-10-27",
                  tax_estimate_date: null,
                  valid_through_local_date: null,
                  current_opportunity_id: null,
                  line_count: 1,
                  created_by: 5,
                },
              },
            },
      `?entry=${entry}`,
    );
    await expect(page.getByText("Party #23999", { exact: true })).toBeVisible();
    allowed = false;
    await page
      .getByText("Refresh fixture authority", { exact: true })
      .click({ force: entry === "sheet" });
    await expect(page.getByText("Party #23999", { exact: true })).toHaveCount(
      0,
    );
    await expect(
      page.getByRole("button", { name: "Share quotation", exact: true }),
    ).toHaveCount(0);
  });
