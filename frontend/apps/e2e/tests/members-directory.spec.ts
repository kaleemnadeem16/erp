import { expect, test } from "@playwright/test";

test("member directory defaults to mobile cards and role-first creation", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/settings/members");

  await expect(page.getByRole("heading", { name: "Members" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Card view" })).toHaveAttribute(
    "aria-pressed",
    "true",
  );
  await expect(page.getByRole("table")).toHaveCount(0);
  await expect(page.getByText("Access changed").first()).toBeVisible();

  await page.getByRole("button", { name: "Create user" }).click();
  const custom = page.getByRole("checkbox", {
    name: "Give custom permissions",
  });
  await expect(custom).not.toBeChecked();
  await expect(
    page.getByText("Reason for initial access (optional)"),
  ).toHaveCount(0);
  await custom.click();
  const administration = page
    .locator("details")
    .filter({ has: page.locator("summary", { hasText: "Administration" }) });
  await expect(administration.locator("summary")).toContainText("of");
  await expect(administration).not.toHaveAttribute("open", "");
  await administration.locator("summary").click();
  await expect(administration).toHaveAttribute("open", "");
});
