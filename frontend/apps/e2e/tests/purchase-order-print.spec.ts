import { expect, test } from '@playwright/test';

test('PO print uses real company data, amount columns and one authoritative total', async ({ page }) => {
  await page.setViewportSize({ width: 794, height: 1123 });
  await page.route('**/api/v1/company', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.values, {
      name: 'Coating Works',
      legal_name: 'Coating Works (Pvt) Ltd',
      address_line1: '42 Works Road',
      city: 'Lahore',
      tax_number: 'TEST-TAX-42',
    });
    await route.fulfill({ response, json: body });
  });
  await page.route('**/api/v1/purchase-orders/173?*', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.supplier_site = {
      id: 88001,
      supplier_account_id: body.data.supplier_account_id,
      role: 'ORDERING',
      is_default: true,
      party_site: {
        id: 88002,
        party_id: 88003,
        code: 'main',
        name: 'Main office',
        address_line1: '18 Industrial Road',
        address_line2: null,
        city: 'Lahore',
        state: 'Punjab',
        postal_code: '54000',
        country_code: 'PK',
        status: 'ACTIVE',
      },
    };
    await route.fulfill({ response, json: body });
  });
  await page.route('**/api/v1/supplier-accounts/*?*', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.party, {
      tax_id: 'SUPPLIER-TAX-18',
      registration_no: 'SUPPLIER-REG-18',
      website: 'https://supplier.example',
    });
    await route.fulfill({ response, json: body });
  });
  await page.goto('/purchase-orders/173');
  const print = page.getByTestId('purchase-order-print');
  await expect(print).toContainText('Coating Works');
  await expect(print).toContainText('Punjab Pigments');
  await expect(print).toContainText('18 Industrial Road');
  await expect(print).toContainText('SUPPLIER-TAX-18');
  await expect(print.locator('table')).toHaveCount(1);
  await expect(print.locator('.po-print-order-details')).toContainText('PO details');

  await expect(print).toContainText('95,000.0000');
  await expect(print.getByText('42 Works Road', { exact: false })).toHaveCount(1);
  await expect(print.locator('.po-print-total')).toHaveCount(1);
  await expect(print).not.toContainText('Discount (%)');
  await expect(print).not.toContainText('Demo User');
  await expect(print).not.toContainText('Deliver to');
  await expect(print).not.toContainText('Terms and conditions');
  await expect(print).toContainText('Draft — not issued');
  // Render the actual hidden print tree as paper for visual inspection and PDF output.
  await page.evaluate(() => {
    const paper = document.querySelector('[data-testid="purchase-order-print"]')!.cloneNode(true);
    document.body.replaceChildren(paper);
    document.body.style.cssText = 'background:white;margin:0;padding:32px;width:794px';
  });
  await page.emulateMedia({ media: 'print' });
  await page.screenshot({ path: 'test-results/po-print-preview.png', fullPage: true });
  await page.evaluate(() => {
    document.body.style.cssText = 'background:white;margin:0;padding:0;width:718px';
  });
  await page.pdf({
    path: 'test-results/po-print-preview.pdf',
    format: 'A4',
    printBackground: true,
    margin: { top: '10mm', bottom: '10mm', left: '10mm', right: '10mm' },
  });
  const width = await print.evaluate((element) => ({ scroll: element.scrollWidth, client: element.clientWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.client);
});

test('PO print omits commercial fields for a quantity-only reader', async ({ page }) => {
  await page.route('**/api/v1/user', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.permissions = ['purchasing.purchase-order.view'];
    await route.fulfill({ response, json: body });
  });
  await page.goto('/purchase-orders/173');
  const print = page.getByTestId('purchase-order-print');
  await expect(print).toContainText('Punjab Pigments');
  await expect(print.getByRole('columnheader', { name: 'Unit price', includeHidden: true })).toHaveCount(0);
  await expect(print.locator('.po-print-total')).toHaveCount(0);
  await expect(print).not.toContainText('95,000.0000');
});

test('long PO print repeats table headings and preserves exact decimal totals', async ({ page }) => {
  await page.route('**/api/v1/purchase-orders/173?*', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const line = body.data.lines[0];
    body.data.lines = Array.from({ length: 55 }, (_, i) => ({
      ...line,
      id: 80000 + i,
      line_number: i + 1,
      description:
        `Line ${i + 1}: coating material with a detailed supplier specification and handling instructions. `.repeat(3),
    }));
    body.data.total_amount = '999999999999999.0001';
    await route.fulfill({ response, json: body });
  });
  await page.goto('/purchase-orders/173');
  const print = page.getByTestId('purchase-order-print');
  await expect(print.locator('tbody tr')).toHaveCount(55);
  await expect(print.locator('.po-print-total')).toContainText('999,999,999,999,999.0001');
  await page.evaluate(() => {
    const paper = document.querySelector('[data-testid="purchase-order-print"]')!.cloneNode(true);
    document.body.replaceChildren(paper);
    document.body.style.cssText = 'background:white;margin:0;padding:0;width:718px';
  });
  await page.emulateMedia({ media: 'print' });
  await expect(print.locator('thead')).toHaveCSS('display', 'table-header-group');
  const width = await print.evaluate((element) => ({ scroll: element.scrollWidth, client: element.clientWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.client);
  await page.pdf({
    path: 'test-results/po-print-long.pdf',
    format: 'A4',
    printBackground: true,
    margin: { top: '10mm', bottom: '10mm', left: '10mm', right: '10mm' },
  });
});
