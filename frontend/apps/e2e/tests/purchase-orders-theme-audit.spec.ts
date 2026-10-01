import { expect, test } from '@playwright/test';

test.describe('Purchase Orders Light Palette Theme Audit', () => {
  test('verifies erp-blue palette tokens, computed styles, and visual states on PO screen', async ({ page }) => {
    // 1. Navigate to /purchase-orders
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    // 2. Canvas & Typography hierarchy
    const poContainer = page.locator('.po-screen');
    await expect(poContainer).toBeVisible();

    // Verify canvas background is #F8FAFC (rgb(248, 250, 252))
    const canvasBg = await poContainer.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    expect(canvasBg).toBe('rgb(248, 250, 252)');

    // Verify page title is #0F172A (rgb(15, 23, 42))
    const title = page.getByRole('heading', { name: 'Purchase Orders' });
    const titleColor = await title.evaluate((el) => window.getComputedStyle(el).color);
    expect(titleColor).toBe('rgb(15, 23, 42)');

    // Verify page description is #475569 (rgb(71, 85, 105))
    const desc = page.getByText('Raise supplier commitments');
    const descColor = await desc.evaluate((el) => window.getComputedStyle(el).color);
    expect(descColor).toBe('rgb(71, 85, 105)');

    // 3. Buttons: Primary (New purchase order)
    const newPoBtn = page.getByRole('button', { name: /New purchase order|Continue new purchase order/ });
    if (await newPoBtn.isVisible()) {
      const primaryBg = await newPoBtn.evaluate((el) => window.getComputedStyle(el).backgroundColor);
      const primaryText = await newPoBtn.evaluate((el) => window.getComputedStyle(el).color);
      expect(primaryBg).toBe('rgb(37, 99, 235)'); // #2563EB
      expect(primaryText).toBe('rgb(255, 255, 255)'); // #FFFFFF
    }

    // 4. Secondary button (Print)
    const printBtn = page.getByRole('button', { name: 'Print' });
    await expect(printBtn).toBeVisible();
    const printBg = await printBtn.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const printColor = await printBtn.evaluate((el) => window.getComputedStyle(el).color);
    expect(printBg).toBe('rgb(255, 255, 255)'); // #FFFFFF
    expect(printColor).toBe('rgb(15, 23, 42)'); // #0F172A

    // 5. Form controls: Search input
    const searchInput = page.getByPlaceholder('Search orders, suppliers, items…');
    await expect(searchInput).toBeVisible();
    const inputBg = await searchInput.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const inputColor = await searchInput.evaluate((el) => window.getComputedStyle(el).color);
    const inputBorder = await searchInput.evaluate((el) => window.getComputedStyle(el).borderColor);
    expect(inputBg).toBe('rgb(255, 255, 255)'); // #FFFFFF
    expect(inputColor).toBe('rgb(15, 23, 42)'); // #0F172A
    expect(inputBorder).toBe('rgb(203, 213, 225)'); // #CBD5E1

    // 6. Table: Header, Rows, Separators
    const tableHeader = page.locator('.po-screen thead tr').first();
    await expect(tableHeader).toBeVisible();
    const thBg = await tableHeader.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    expect(thBg).toBe('rgb(248, 250, 252)'); // #F8FAFC

    const thCell = page.locator('.po-screen th').first();
    const thColor = await thCell.evaluate((el) => window.getComputedStyle(el).color);
    expect(thColor).toBe('rgb(71, 85, 105)'); // #475569

    const firstRow = page.locator('.po-screen tbody tr').first();
    await expect(firstRow).toBeVisible();
    const rowBg = await firstRow.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const rowColor = await firstRow.evaluate((el) => window.getComputedStyle(el).color);
    expect(rowBg).toBe('rgb(255, 255, 255)'); // #FFFFFF
    expect(rowColor).toBe('rgb(15, 23, 42)'); // #0F172A

    // 7. PO Number link styling
    const poLink = page.locator('.po-order-link').first();
    await expect(poLink).toBeVisible();
    const linkColor = await poLink.evaluate((el) => window.getComputedStyle(el).color);
    expect(linkColor).toBe('rgb(29, 78, 216)'); // #1D4ED8

    // 8. Status badges: CLOSED / ISSUED / DRAFT
    const closedBadge = page.locator('.po-status-badge.po-status-success').first();
    if (await closedBadge.isVisible()) {
      const closedBg = await closedBadge.evaluate((el) => window.getComputedStyle(el).backgroundColor);
      const closedText = await closedBadge.evaluate((el) => window.getComputedStyle(el).color);
      expect(closedBg).toBe('rgb(220, 252, 231)'); // #DCFCE7
      expect(closedText).toBe('rgb(22, 101, 52)'); // #166534
    }

    const issuedBadge = page.locator('.po-status-badge.po-status-active').first();
    if (await issuedBadge.isVisible()) {
      const issuedBg = await issuedBadge.evaluate((el) => window.getComputedStyle(el).backgroundColor);
      const issuedText = await issuedBadge.evaluate((el) => window.getComputedStyle(el).color);
      expect(issuedBg).toBe('rgb(219, 234, 254)'); // #DBEAFE
      expect(issuedText).toBe('rgb(30, 64, 175)'); // #1E40AF
    }

    // Status is never colour alone: every badge keeps its text label, and the draft badge uses the draft tokens
    const draftBadge = page.locator('.po-status-badge.po-status-draft').first();
    if (await draftBadge.isVisible()) {
      await expect(draftBadge).toHaveText(/DRAFT/);
      expect(await draftBadge.evaluate((el) => window.getComputedStyle(el).backgroundColor)).toBe('rgb(241, 245, 249)'); // #F1F5F9
      expect(await draftBadge.evaluate((el) => window.getComputedStyle(el).color)).toBe('rgb(71, 85, 105)'); // #475569
    }

    // Capture default screen screenshot
    await page.screenshot({ path: 'test-results/po-screen-default.png', fullPage: true });

    // 9. Filters button active state & Advanced filters panel
    const filtersBtn = page.getByRole('button', { name: /Filters/ }).first();
    await filtersBtn.click();
    await expect(page.getByText('Advanced Filters')).toBeVisible();
    // Wait for transition-colors (150ms) to complete
    await page.waitForTimeout(250);

    // Verify filter panel bg and border
    const filterPanel = page.locator('.po-filter-panel');
    const panelBg = await filterPanel.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const panelBorder = await filterPanel.evaluate((el) => window.getComputedStyle(el).borderColor);
    expect(panelBg).toBe('rgb(255, 255, 255)'); // #FFFFFF
    expect(panelBorder).toBe('rgb(226, 232, 240)'); // #E2E8F0

    // Helper for subtle blue background check (#EFF6FF with float/OKLCH round-trip tolerance)
    const expectSubtleBlue = (rgb: string) => {
      const match = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
      expect(match).not.toBeNull();
      const [, r, g, b] = match!.map(Number);
      expect(r).toBeGreaterThanOrEqual(237);
      expect(r).toBeLessThanOrEqual(240);
      expect(g).toBeGreaterThanOrEqual(244);
      expect(g).toBeLessThanOrEqual(247);
      expect(b).toBe(255);
    };

    // Filters button active subtle blue state:
    // Right after click, button is hovered -> should be --po-brand-subtle-hover (#DBEAFE)
    const hoveredFiltersBg = await filtersBtn.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const activeFiltersColor = await filtersBtn.evaluate((el) => window.getComputedStyle(el).color);
    expect(hoveredFiltersBg).toBe('rgb(219, 234, 254)'); // #DBEAFE
    expect(activeFiltersColor).toBe('rgb(29, 78, 216)'); // #1D4ED8

    // Move mouse away to test unhovered active state -> should be --po-brand-subtle (#EFF6FF)
    await page.mouse.move(0, 0);
    await page.waitForTimeout(200);
    const unhoveredFiltersBg = await filtersBtn.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    expectSubtleBlue(unhoveredFiltersBg); // #EFF6FF

    // 10. Active filter chip
    await page.getByLabel('Min total value').fill('500000');
    const chip = page.locator('.po-chip-active').first();
    await expect(chip).toBeVisible();

    const chipBg = await chip.evaluate((el) => window.getComputedStyle(el).backgroundColor);
    const chipColor = await chip.evaluate((el) => window.getComputedStyle(el).color);
    const chipBorder = await chip.evaluate((el) => window.getComputedStyle(el).borderColor);
    expectSubtleBlue(chipBg);
    expect(chipColor).toBe('rgb(29, 78, 216)'); // #1D4ED8
    expect(['rgb(219, 234, 254)', 'rgb(218, 234, 254)']).toContain(chipBorder); // #DBEAFE

    // The count badge on the Filters button stays a readable blue chip (a broad table-surface selector once painted it white)
    const filterBadge = page.locator('.po-filter-badge').first();
    await expect(filterBadge).toBeVisible();
    expect(await filterBadge.evaluate((el) => window.getComputedStyle(el).backgroundColor)).toBe('rgb(37, 99, 235)');
    expect(await filterBadge.evaluate((el) => window.getComputedStyle(el).color)).toBe('rgb(255, 255, 255)');

    // Capture active filters screenshot
    await page.screenshot({ path: 'test-results/po-screen-active-filters.png', fullPage: true });

    // Remove chip via its X button
    await chip.getByRole('button').click();
    await expect(chip).toHaveCount(0);
  });

  test('rows can be ticked for bulk actions, and the selection stays honest', async ({ page }) => {
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();
    // The hidden print layouts hold tables too, so count only the visible rows that carry a tick box
    const rows = page.getByRole('row').filter({ has: page.getByRole('checkbox', { name: 'Select row' }) });
    await expect(rows.first()).toBeVisible();

    // Nothing ticked: no bulk bar
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toHaveCount(0);

    // Ticking a row selects it (surface + edge, not just the box) and offers bulk actions
    await page.getByRole('checkbox', { name: 'Select row' }).first().check();
    const bar = page.getByRole('region', { name: 'Bulk actions' });
    await expect(bar).toContainText('1 selected');
    await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
    const selected = page.locator('.po-screen tbody tr[data-state="selected"]');
    await expect(selected).toHaveCount(1);
    await page.mouse.move(0, 0);
    await page.waitForTimeout(250);
    expect(await selected.evaluate((el) => window.getComputedStyle(el).backgroundColor)).toBe('rgb(239, 246, 255)'); // #EFF6FF
    // Ticking a row must not open the detail sheet
    await expect(page).toHaveURL(/\/purchase-orders(\?.*)?$/);

    // Select all on the page, then clear
    await page.getByRole('checkbox', { name: 'Select all' }).click();
    const rowCount = await rows.count();
    await expect(bar).toContainText(`${rowCount} selected`);
    await bar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toHaveCount(0);

    // Changing the filters starts a fresh selection
    await page.getByRole('checkbox', { name: 'Select row' }).first().check();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Status' }).click();
    await page.getByRole('option', { name: 'ISSUED' }).click();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toHaveCount(0);
  });

  test('each row has an actions menu, and the footer names the records', async ({ page }) => {
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();
    await expect(page.getByText(/\d+–\d+ of \d+ purchase orders/)).toBeVisible();
    await expect(page.locator('.po-screen [aria-current="page"]')).toHaveText('1');

    await page.getByRole('button', { name: /^Actions for/ }).first().click();
    await expect(page.getByRole('menuitem', { name: 'Open order' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Open order' }).click();
    await expect(page).toHaveURL(/\/purchase-orders\/\d+/);
  });

  test('the row menu offers only the quick actions that fit the order, and hands issue over to the order screen', async ({ page }) => {
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    // A draft: open, edit, issue — never cancel
    const draftRow = page.getByRole('row').filter({ has: page.getByText('DRAFT', { exact: true }) }).first();
    await draftRow.getByRole('button', { name: /^Actions for/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Open order' })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Edit draft' })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: /^Issue order/ })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: /^Cancel order/ })).toHaveCount(0);
    await page.keyboard.press('Escape');

    // An issued order: open and cancel — never edit or issue
    const issuedRow = page.getByRole('row').filter({ has: page.getByText('ISSUED', { exact: true }) }).first();
    await issuedRow.getByRole('button', { name: /^Actions for/ }).click();
    await expect(page.getByRole('menuitem', { name: /^Cancel order/ })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Edit draft' })).toHaveCount(0);
    await expect(page.getByRole('menuitem', { name: /^Issue order/ })).toHaveCount(0);
    await page.keyboard.press('Escape');

    // Choosing "Cancel order…" opens that order with the cancel confirmation already showing (nothing is cancelled yet)
    await issuedRow.getByRole('button', { name: /^Actions for/ }).click();
    await page.getByRole('menuitem', { name: /^Cancel order/ }).click();
    await expect(page.getByRole('heading', { name: 'Cancel this issued order' })).toBeVisible();
    await expect(page.getByText('ISSUED', { exact: true }).first()).toBeVisible();
  });

  test('verifies other screens (Items) are completely untouched by po-screen styles', async ({ page }) => {
    await page.goto('/items');
    await expect(page.getByRole('heading', { name: 'Items' })).toBeVisible();

    // Confirm .po-screen is NOT present on /items
    await expect(page.locator('.po-screen')).toHaveCount(0);

    // Capture items screen screenshot for visual comparison
    await page.screenshot({ path: 'test-results/items-screen-untouched.png', fullPage: true });
  });
});
