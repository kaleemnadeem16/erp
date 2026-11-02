import { expect, test, type Page } from "@playwright/test";

// Operator acknowledgement is not proof of the database behind port 8091.
// The coordinator must establish and confirm that mapping before this runs.
const database = process.env.ERP_PRIVATE_QUOTATION_DB;
const email = process.env.E2E_USER_EMAIL;
const password = process.env.E2E_USER_PASSWORD;
test.skip(
  database !== "erp_testing_agent_d_quotes_ui_m1" || !email || !password,
  "Requires coordinator-confirmed private backend on :8091 and demo credentials.",
);

async function signInToPrivateBackend(page: Page) {
  const forbidden: string[] = [];
  await page.route("**/*", async (route) => {
    const url = new URL(route.request().url());
    const frontendProxy =
      url.port === "5178" &&
      (url.pathname.startsWith("/api/") ||
        url.pathname.startsWith("/sanctum/"));
    if (
      url.hostname !== "localhost" ||
      !["5178", "8091"].includes(url.port) ||
      frontendProxy
    ) {
      forbidden.push(`${url.protocol}//${url.host}${url.pathname}`);
      await route.abort();
      return;
    }
    await route.continue();
  });
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill(email!);
  await page.getByLabel("Password", { exact: true }).fill(password!);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).not.toHaveURL(/\/login/);
  return forbidden;
}

test("create and edit a draft through the private backend", async ({
  page,
}) => {
  const forbidden = await signInToPrivateBackend(page);
  await page.goto("/quotations/new");
  await expect(
    page.getByRole("heading", { name: "Create quotation draft" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Review draft" }).click();
  const createResponse = page.waitForResponse(
    (response) =>
      response.url() === "http://localhost:8091/api/v1/quotations" &&
      response.request().method() === "POST",
  );
  const editableResponse = page.waitForResponse(
    (response) =>
      /\/api\/v1\/quotations\/[1-9]\d*\/editable$/.test(response.url()) &&
      response.request().method() === "GET",
  );
  await page.getByRole("button", { name: "Create draft", exact: true }).click();
  const created = await createResponse;
  expect([200, 201]).toContain(created.status());
  expect(created.request().headers()["idempotency-key"]).toBeTruthy();
  const receipt = (await created.json()).data as { revision_id: number };
  expect(Number.isSafeInteger(receipt.revision_id)).toBe(true);
  expect(receipt.revision_id).toBeGreaterThan(0);
  const editable = await editableResponse;
  expect(editable.status()).toBe(200);
  const baseline = (await editable.json()).data as {
    revision_id: number;
    input_generation: number;
  };
  expect(baseline.revision_id).toBe(receipt.revision_id);
  await expect(
    page.getByRole("heading", {
      name: `Edit quotation #${receipt.revision_id}`,
    }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Add manual line" }).click();
  const description = `Private test line ${Date.now()}`;
  await page.getByLabel("Description", { exact: true }).fill(description);
  await page.getByLabel("Measure", { exact: true }).fill("job");
  await page.getByLabel("Quantity", { exact: true }).fill("2.0000");
  await page.getByRole("button", { name: "Review changes" }).click();
  const updateResponse = page.waitForResponse(
    (response) =>
      response.url() ===
        `http://localhost:8091/api/v1/quotations/${receipt.revision_id}` &&
      response.request().method() === "PATCH",
  );
  await page.getByRole("button", { name: "Save changes" }).click();
  const updated = await updateResponse;
  expect(updated.status()).toBe(200);
  expect(updated.request().headers()["idempotency-key"]).toBeTruthy();
  expect(updated.request().postDataJSON().expected_generation).toBe(
    baseline.input_generation,
  );
  await expect(page).toHaveURL(/\/quotations$/);
  await page.goto(`/quotations/${receipt.revision_id}/edit`);
  await expect(page.getByLabel("Description", { exact: true })).toHaveValue(
    description,
  );
  expect(forbidden).toEqual([]);
});

test("retry an uncertain create with the same key and payload", async ({
  page,
}) => {
  const forbidden = await signInToPrivateBackend(page);
  const requests: { key: string | undefined; body: string | null }[] = [];
  await page.route("http://localhost:8091/api/v1/quotations", async (route) => {
    if (route.request().method() !== "POST") return route.fallback();
    requests.push({
      key: route.request().headers()["idempotency-key"],
      body: route.request().postData(),
    });
    if (requests.length === 1) {
      const completed = await route.fetch({ maxRedirects: 0 });
      expect([200, 201]).toContain(completed.status());
      await route.abort("failed");
      return;
    }
    await route.fallback();
  });
  await page.goto("/quotations/new");
  await page.getByRole("button", { name: "Review draft" }).click();
  await page.getByRole("button", { name: "Create draft", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Retry create" }),
  ).toBeVisible();
  const replayResponse = page.waitForResponse(
    (response) =>
      response.url() === "http://localhost:8091/api/v1/quotations" &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Retry create" }).click();
  const replay = await replayResponse;
  expect(replay.status()).toBe(200);
  expect((await replay.json()).data.replayed).toBe(true);
  expect(requests).toHaveLength(2);
  expect(requests[0]?.key).toBeTruthy();
  expect(requests[1]).toEqual(requests[0]);
  expect(forbidden).toEqual([]);
});
