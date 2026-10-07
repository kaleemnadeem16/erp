import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

for (const mode of ["light", "dark"] as const) {
  for (const theme of ["slate", "ocean", "amber"] as const) {
    test(`screen standard: ${mode} / ${theme}`, async ({ page }) => {
      await page.addInitScript(
        (appearance) =>
          localStorage.setItem("erp.appearance", JSON.stringify(appearance)),
        { mode, theme },
      );
      await page.goto("/purchase-orders");
      const screen = page.locator('[data-screen-template="list"]');
      await expect(screen).toBeVisible();
      const title = page.getByRole("heading", { name: "Purchase Orders" });
      await expect(title).toHaveCSS("font-size", "30px");
      await expect(title).toHaveCSS("font-weight", "700");
      await expect(screen).toHaveCSS(
        "background-color",
        mode === "dark" ? "rgb(11, 11, 11)" : "rgb(248, 250, 252)",
      );
      if (mode === "dark") {
        const ratios = await page.evaluate(() => {
          const sample = document.createElement("span");
          document.body.append(sample);
          const canvas = document.createElement("canvas");
          canvas.width = canvas.height = 1;
          const context = canvas.getContext("2d")!;
          const luminance = (token: string) => {
            sample.style.color = `var(${token})`;
            context.fillStyle = getComputedStyle(sample).color;
            context.fillRect(0, 0, 1, 1);
            const channels = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3)
              .map((value) => value / 255)
              .map((value) => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
            return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
          };
          const pairs: [string, string, number][] = [
            ["--foreground", "--card", 4.5],
            ["--erp-blue-color-text-secondary", "--erp-blue-color-bg-hover", 4.5],
            ["--erp-blue-color-field-placeholder", "--erp-blue-color-field-bg", 4.5],
            ["--primary-foreground", "--primary", 4.5],
            ["--erp-blue-color-text-link", "--erp-blue-color-table-row-selected-hover-bg", 4.5],
            ["--erp-blue-color-field-border", "--erp-blue-color-field-bg", 3],
            ["--erp-blue-color-field-border", "--card", 3],
            ["--ring", "--card", 3],
          ];
          for (const status of ["draft", "pending", "active", "progress", "success", "danger"])
            pairs.push([`--erp-blue-status-${status}-text`, `--erp-blue-status-${status}-bg`, 4.5]);
          const result = pairs.map(([foreground, background, minimum]) => {
            const a = luminance(foreground), b = luminance(background);
            return { foreground, background, minimum, ratio: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) };
          });
          sample.remove();
          return result;
        });
        for (const pair of ratios)
          expect(pair.ratio, JSON.stringify(pair)).toBeGreaterThanOrEqual(pair.minimum);
      }
      const input = page.getByPlaceholder("Search orders, suppliers, items…");
      await expect(input).toHaveCSS(
        "background-color",
        mode === "dark" ? "rgb(18, 18, 18)" : "rgb(255, 255, 255)",
      );
      await input.focus();
      const ring = await page.evaluate(() => {
        const sample = document.createElement("span");
        sample.style.color = "var(--ring)";
        document.body.append(sample);
        const color = getComputedStyle(sample).color;
        sample.remove();
        return color;
      });
      await expect(input).toHaveCSS("border-color", ring);
      expect(
        await input.evaluate((element) => getComputedStyle(element).boxShadow),
      ).toContain("3px");
      // The list's Radix menu is outside the page DOM; it still uses the same theme.
      await page.getByRole("combobox", { name: "Status", exact: true }).click();
      const menu = page
        .locator(".erp-portal")
        .filter({
          has: page.getByRole("option", { name: "ISSUED", exact: true }),
        });
      await expect(menu).toBeVisible();
      await expect(menu).toHaveCSS(
        "background-color",
        mode === "dark" ? "rgb(23, 23, 23)" : "rgb(255, 255, 255)",
      );
      await page.keyboard.press("Escape");
      await page
        .getByRole("checkbox", { name: "Select row", exact: true })
        .first()
        .check();
      await expect(
        page.getByRole("region", { name: "Bulk actions" }),
      ).toContainText("1 selected");
      const scan = await new AxeBuilder({ page }).analyze();
      expect(
        scan.violations.filter((issue) =>
          ["serious", "critical"].includes(issue.impact ?? ""),
        ),
        JSON.stringify(scan.violations, null, 2),
      ).toEqual([]);
      if (theme === "slate")
        await page.screenshot({
          path: `test-results/screen-standard-${mode}.png`,
          fullPage: true,
        });
    });
  }
}

test("dark mobile record/form screens preserve layout and field hierarchy", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.addInitScript(() =>
    localStorage.setItem(
      "erp.appearance",
      JSON.stringify({ mode: "dark", theme: "slate" }),
    ),
  );
  await page.goto("/purchase-orders/173");
  const sheet = page.locator('.erp-record[role="dialog"]');
  await expect(sheet).toBeVisible();
  await expect(sheet.locator(".detail-section").first()).toHaveCSS(
    "background-color",
    "rgb(23, 23, 23)",
  );
  expect(
    await sheet.evaluate(
      (element) => element.scrollWidth - element.clientWidth,
    ),
  ).toBeLessThanOrEqual(1);
  await page.goto("/purchase-orders/new");
  await expect(page.locator('.erp-record[role="dialog"]')).toBeVisible();
  const form = page.locator(".erp-record form");
  await expect(form).toBeVisible();
  expect(
    await page
      .locator('.erp-record[role="dialog"]')
      .evaluate((element) => element.scrollWidth - element.clientWidth),
  ).toBeLessThanOrEqual(1);
  const segmentsFit = await form.evaluate((element) => {
    return [...element.querySelectorAll('[data-slot="date-field"]')].every((field) => {
      const bounds = field.getBoundingClientRect();
      return [...field.querySelectorAll('input')].every((segment) => {
        const rectangle = segment.getBoundingClientRect();
        return rectangle.top >= bounds.top && rectangle.bottom <= bounds.bottom;
      });
    });
  });
  expect(segmentsFit).toBe(true);
  const scan = await new AxeBuilder({ page }).analyze();
  expect(
    scan.violations.filter((issue) =>
      ["serious", "critical"].includes(issue.impact ?? ""),
    ),
    JSON.stringify(scan.violations, null, 2),
  ).toEqual([]);
  await page.screenshot({
    path: "test-results/screen-standard-mobile-dark-form.png",
    fullPage: true,
  });
});

test("system colour mode and saved theme selection use the default dark palette", async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: "dark" });
  await page.addInitScript(() => {
    if (!localStorage.getItem("erp.appearance"))
      localStorage.setItem(
        "erp.appearance",
        JSON.stringify({ mode: "system", theme: "slate" }),
      );
  });
  await page.goto("/settings/appearance");
  await expect(page.getByRole("radio", { name: /ERP Blue/ })).toBeChecked();
  await expect(page.locator("html")).toHaveClass(/dark/);
  await page.getByRole("radio", { name: /Amber/ }).focus();
  await page.keyboard.press("Space");
  await page.reload();
  await expect(page.getByRole("radio", { name: /Amber/ })).toBeChecked();
});

for (const mode of ["light", "dark"] as const) {
  test(`PO mobile controls match Approvals: ${mode}`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript((mode) => {
      localStorage.setItem("erp.appearance", JSON.stringify({ mode, theme: "slate" }));
      localStorage.setItem("erp.list-view.purchase-orders-list", "cards");
    }, mode);
    await page.goto("/purchase-orders");
    const search = page.getByPlaceholder("Search orders, suppliers, items…");
    const filters = page.getByRole("button", { name: /^Filters/ }).filter({ visible: true });
    await expect(search).toHaveCSS("height", "40px");
    await expect(filters).toHaveCSS("height", "40px");
    const toggle = page.locator(".ui-list-view-toggle");
    await expect(toggle).toBeVisible();
    for (const name of ["Table view", "Card view"]) {
      const button = toggle.getByRole("button", { name });
      await button.click();
      await expect(button).toHaveAttribute("aria-pressed", "true");
      await expect(button).toHaveCSS("height", "36px");
      await page.mouse.move(0, 0);
      await expect.poll(() => button.evaluate(element => {
        const probe = document.createElement("span");
        probe.style.backgroundColor = getComputedStyle(element).getPropertyValue("--ui-brand-primary");
        element.appendChild(probe);
        const expected = getComputedStyle(probe).backgroundColor;
        probe.remove();
        return getComputedStyle(element).backgroundColor === expected;
      })).toBe(true);
    }
    await page.screenshot({ path: `/tmp/erp-po-mobile-switcher-${mode}.png`, fullPage: true, animations: "disabled" });
    await filters.click();
    await expect(page.getByRole("dialog", { name: "Filters" })).toBeVisible();
  });
}

for (const mode of ["light", "dark"] as const) {
  test(`mobile pagination matches PO and Approvals: ${mode}`, async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.addInitScript((mode) => {
      localStorage.setItem("erp.appearance", JSON.stringify({ mode, theme: "slate" }));
      localStorage.setItem("erp.list-view.purchase-orders-list", "cards");
      localStorage.setItem("erp.list-view.approvals-workspace", "cards");
    }, mode);
    const sizes: unknown[] = [];
    for (const route of ["/purchase-orders", "/approvals", "/approvals?view=history"]) {
      await page.goto(route);
      const footer = page.locator(".ui-list-pagination");
      const size = footer.getByRole("combobox", { name: "Rows per page" });
      await size.scrollIntoViewIfNeeded();
      await expect(size).toBeVisible();
      sizes.push(await size.evaluate(element => {
        const style = getComputedStyle(element);
        return { height: style.height, width: style.width, fontSize: style.fontSize, borderRadius: style.borderRadius };
      }));
      await size.click();
      await page.getByRole("option", { name: "25", exact: true }).click();
      await expect(size).toHaveText("25");
      expect(await footer.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
      await page.screenshot({ path: `/tmp/erp-pagination-${route.includes("purchase") ? "po" : route.includes("history") ? "history" : "approvals"}-${mode}.png`, animations: "disabled" });
    }
    expect(sizes[1]).toEqual(sizes[0]);
    expect(sizes[2]).toEqual(sizes[0]);
  });
}
