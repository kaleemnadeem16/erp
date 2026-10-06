import { expect, test, type Page } from "@playwright/test";

/**
 * ADR 0088 (Q36): the account screen lists the devices signed in with a device token and lets the person sign one out. The server
 * returns only the caller's own devices and expires every token (its tests read the responses); this checks the screen shows what it
 * is given, says plainly when there are none, and asks the server to end exactly the device chosen. The API is mocked.
 */
const phone = {
  id: 9001,
  name: "Pixel 8",
  created_at: "2026-10-01T09:00:00Z",
  last_used_at: "2026-10-06T08:30:00Z",
  expires_at: "2026-10-31T09:00:00Z",
  is_current: true,
  auth_methods: ["password"],
  user_agent: "ErpMobile/1.0 (Android 15)",
};
const tablet = {
  ...phone,
  id: 9002,
  name: "Warehouse tablet",
  is_current: false,
  last_used_at: null,
  user_agent: null,
};

async function openAccount(page: Page, devices: unknown[]) {
  await page.route("**/api/v1/account/devices", (route) =>
    route.fulfill({ json: { data: devices } }),
  );
  await page.goto("/settings/account");
  await expect(page.getByRole("heading", { name: "Account" })).toBeVisible();
}

test("lists each device with when it signed in, when it was last used and when it signs out", async ({
  page,
}) => {
  await openAccount(page, [phone, tablet]);
  const list = page.getByRole("list", { name: "Signed-in devices" });
  await expect(list.getByText("Pixel 8")).toBeVisible();
  await expect(list.getByText("This device")).toBeVisible();
  await expect(list.getByText("ErpMobile/1.0 (Android 15)")).toBeVisible();
  await expect(list.getByText("Warehouse tablet")).toBeVisible();
  await expect(list.getByText(/last used never/)).toBeVisible();
  await expect(list.getByText(/signs out on/).first()).toBeVisible();
});

test("says plainly when no device is signed in", async ({ page }) => {
  await openAccount(page, []);
  await expect(
    page.getByText(/No devices are signed in with a device token/),
  ).toBeVisible();
  await expect(
    page.getByRole("list", { name: "Signed-in devices" }),
  ).toHaveCount(0);
});

test("signing a device out asks the server to end exactly that device", async ({
  page,
}) => {
  await openAccount(page, [phone, tablet]);
  await page.route("**/api/v1/account/devices/9002", (route) =>
    route.fulfill({ status: 204 }),
  );
  const request = page.waitForRequest(
    (r) =>
      r.method() === "DELETE" &&
      r.url().endsWith("/api/v1/account/devices/9002"),
  );
  await page.getByRole("button", { name: "Sign out Warehouse tablet" }).click();
  expect((await request).url()).toContain("/account/devices/9002");
});
