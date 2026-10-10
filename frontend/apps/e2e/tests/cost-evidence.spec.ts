import { expect, test } from '@playwright/test';
import { findByCode } from './package-stock-support';
import { pickCombobox } from './support';

test('the preview separates incomplete totals from supported zero at phone width', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/material-preview');
  for (const scenario of ['unknown', 'partial', 'zero']) {
    await pickCombobox(page, 'Assembly', new RegExp(`demo-asm-cost-${scenario}`), { searchText: `demo-asm-cost-${scenario}` });
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    const costs = page.getByRole('heading', { name: 'Internal cost for this quantity' }).locator('..');
    if (scenario === 'zero') {
      await expect(page.getByText('Complete cost evidence', { exact: false })).toBeVisible();
      await expect(costs.getByText('0.0000', { exact: true }).first()).toBeVisible();
    } else {
      await expect(page.getByText('Complete cost is unavailable', { exact: false })).toBeVisible();
      await expect(costs.getByText('Unavailable', { exact: true }).first()).toBeVisible();
      if (scenario === 'partial') {
        await expect(costs.getByText(/Incomplete · known contribution 25.0000/).first()).toBeVisible();
      }
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  }
});

for (const width of [390, 1280]) {
  test(`the Assembly cost panel shows incomplete and supported-zero costs honestly at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const open = async (code: string) => {
      const assembly = await findByCode(page, '/assemblies', code);
      expect(assembly, `${code} must be seeded by demo:seed`).toBeTruthy();
      await page.goto(`/assemblies/${assembly!.id}/edit`);
      await page.getByRole('button', { name: 'View cost breakdown' }).first().click();
    };

    await open('demo-asm-cost-partial');
    await expect(page.getByText('Incomplete · known contribution 25.0000').first()).toBeVisible();
    await expect(page.getByText('Unavailable until cost is complete')).toBeVisible();
    await expect(page.getByText('Complete cost is unavailable', { exact: false })).toBeVisible();

    await open('demo-asm-cost-unknown');
    await expect(page.getByText('Cost evidence missing').first()).toBeVisible();
    await expect(page.getByText('Unavailable until cost is complete')).toBeVisible();

    await open('demo-asm-cost-zero');
    await expect(page.getByText('Complete cost evidence', { exact: false })).toBeVisible();
    await expect(page.getByText('Unavailable until cost is complete')).toHaveCount(0);
    await expect(page.getByText('Sell / unit').locator('..').getByText('0.0000')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}

test('the formulation cost panel states its batch cost and readiness', async ({ page }) => {
  const formulation = await findByCode(page, '/formulations', 'demo-f-paint-white-alpha');
  expect(formulation, 'demo formulation must be seeded').toBeTruthy();
  await page.goto(`/formulations/${formulation!.id}/edit`);
  await page.getByRole('button', { name: 'View cost breakdown' }).first().click();
  await expect(page.getByText('Cost per output unit')).toBeVisible();
  await expect(page.getByText('Output batch cost')).toBeVisible();
  await expect(page.getByText(/Complete cost (evidence|is unavailable)/)).toBeVisible();
});
