import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { api, findByCode } from './package-stock-support';

/**
 * The Estimating masters' create/edit forms are sheets over their lists (decision D7), and the backend features that had no screen are reachable: delete a rate
 * dimension / value / application rate / assembly group / resource rate (with the server's refusal shown when something still uses it), rename a dimension value,
 * archive a formulation or an assembly, edit a draft assembly version, test which formulation resolves, edit and delete an overhead-policy draft.
 * Records these tests make carry a unique stamp and are removed in a `finally`; records that can only be archived are archived by the test itself.
 */
const stamp = Date.now().toString(36);

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

/** Chooses a record in a server-searched combobox by typing part of its name. */
async function pick(page: Page, label: string, term: string, within?: ReturnType<Page['getByRole']>) {
  await (within ?? page).getByRole('combobox', { name: label, exact: true }).first().click();
  await page.locator('[cmdk-input]').fill(term);
  await page.getByRole('option', { name: new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first().click();
}

async function actAs(page: Page, permissions: string[]) {
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions } }) });
  });
}

test.describe('Rate dimensions', () => {
  test('a dimension is created in a sheet, its values added, renamed and removed, and the dimension deleted', async ({ page }) => {
    const code = `e2e-dim-${stamp}`;
    try {
      await page.goto('/rate-dimensions?per_page=10');
      await page.getByRole('button', { name: 'New dimension' }).click();
      await expect(page).toHaveURL(/\/rate-dimensions\/new\?.*per_page=10/);
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: 'New rate dimension' })).toBeVisible();
      // The list stays mounted behind the sheet.
      await expect(page.locator('[data-screen-template="list"]')).toBeAttached();
      await sheet.locator('input[name="code"]').fill(code);
      await sheet.locator('input[name="name"]').fill(`E2E dimension ${stamp}`);
      await sheet.getByRole('button', { name: 'Create dimension' }).click();

      // Saving opens the new dimension on its own record, ready for values.
      await expect(sheet.getByRole('heading', { name: `Edit E2E dimension ${stamp}` })).toBeVisible();
      await expect(page).toHaveURL(/\/rate-dimensions\/\d+\/edit/);
      await sheet.locator('#new-value-code').fill('v1');
      await sheet.locator('#new-value-name').fill('First');
      await sheet.getByRole('button', { name: 'Add value' }).click();
      const row = sheet.getByTestId('dimension-value-v1');
      await expect(row).toContainText('First');

      // Rename.
      await row.getByRole('button', { name: 'Rename First' }).click();
      await row.getByLabel('Name').fill('First, renamed');
      await row.getByRole('button', { name: 'Save First' }).click();
      await expect(sheet.getByTestId('dimension-value-v1')).toContainText('First, renamed');

      // Remove the value with a confirmation.
      await sheet.getByRole('button', { name: 'Delete First, renamed' }).click();
      const valueDialog = page.getByTestId('delete-value-dialog');
      await expect(valueDialog).toContainText('cannot be deleted while any application rate is tagged');
      await valueDialog.getByRole('button', { name: 'Delete value' }).click();
      await expect(sheet.getByTestId('dimension-value-v1')).toHaveCount(0);

      // Delete the dimension.
      await sheet.getByRole('button', { name: 'Delete dimension' }).click();
      await page.getByTestId('delete-dimension-dialog').getByRole('button', { name: 'Delete dimension' }).click();
      await expect(page).toHaveURL(/\/rate-dimensions\?.*per_page=10/);
      await expect(page.getByRole('dialog')).toHaveCount(0);
      expect(await findByCode(page, '/rate-dimensions', code)).toBeUndefined();
    } finally {
      const left = await findByCode<{ id: number; code: string }>(page, '/rate-dimensions', code);
      if (left) {
        const values = (await api(page, 'GET', `/rate-dimensions/${left.id}/values?per_page=100`)).data as Array<{ id: number }>;
        for (const value of values) await api(page, 'DELETE', `/rate-dimension-values/${value.id}`);
        await api(page, 'DELETE', `/rate-dimensions/${left.id}`);
      }
    }
  });

  test('a dimension that still has values cannot be deleted: the reason is shown and nothing changes', async ({ page }) => {
    await page.goto('/rate-dimensions');
    const dimension = await findByCode<{ id: number; code: string }>(page, '/rate-dimensions', 'demo-surface-type');
    expect(dimension).toBeTruthy();
    await page.goto(`/rate-dimensions/${dimension!.id}/edit`);
    const sheet = page.getByRole('dialog');
    await sheet.getByRole('button', { name: 'Delete dimension' }).click();
    const dialog = page.getByTestId('delete-dimension-dialog');
    await expect(dialog).toContainText('only when it has no values left');
    await dialog.getByRole('button', { name: 'Delete dimension' }).click();
    await expect(dialog.getByRole('alert')).toContainText('still referenced');
    await dialog.getByRole('button', { name: 'Keep it' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(sheet.getByRole('heading', { name: /Edit / })).toBeVisible();
    expect(await findByCode(page, '/rate-dimensions', 'demo-surface-type')).toBeTruthy();
  });

  test('the edit sheet is dismissed by Escape back to the list and does not overflow on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/rate-dimensions');
    await page.getByText('demo-surface-type').first().click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: /Edit / })).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    expect((await sheet.getByRole('button', { name: 'Save dimension' }).boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/rate-dimensions(\?.*)?$/);
    await expect(sheet).toHaveCount(0);
  });
});

test.describe('Assembly groups', () => {
  test('a group is created in a sheet and deleted again', async ({ page }) => {
    const code = `e2e-grp-${stamp}`;
    try {
      await page.goto('/assembly-groups');
      await page.getByRole('button', { name: 'New group' }).click();
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: 'New assembly group' })).toBeVisible();
      await sheet.locator('input[name="code"]').fill(code);
      await sheet.locator('input[name="name"]').fill(`E2E group ${stamp}`);
      await sheet.getByRole('button', { name: 'Create group' }).click();
      await expect(sheet.getByRole('heading', { name: `Edit E2E group ${stamp}` })).toBeVisible();
      await expect(sheet.getByText('Overhead policy')).toBeVisible();

      await sheet.getByRole('button', { name: 'Delete group' }).click();
      await page.getByTestId('delete-group-dialog').getByRole('button', { name: 'Delete group' }).click();
      await expect(page).toHaveURL(/\/assembly-groups(\?.*)?$/);
      expect(await findByCode(page, '/assembly-groups', code)).toBeUndefined();
    } finally {
      const left = await findByCode<{ id: number; code: string }>(page, '/assembly-groups', code);
      if (left) await api(page, 'DELETE', `/assembly-groups/${left.id}`);
    }
  });

  test('a group an assembly still belongs to cannot be deleted: the reason is shown and nothing changes', async ({ page }) => {
    await page.goto('/assembly-groups');
    const group = await findByCode<{ id: number; code: string }>(page, '/assembly-groups', 'demo-labour');
    expect(group).toBeTruthy();
    await page.goto(`/assembly-groups/${group!.id}/edit`);
    await page.getByRole('dialog').getByRole('button', { name: 'Delete group' }).click();
    const dialog = page.getByTestId('delete-group-dialog');
    await dialog.getByRole('button', { name: 'Delete group' }).click();
    await expect(dialog.getByRole('alert')).toContainText('still referenced');
    await dialog.getByRole('button', { name: 'Keep it' }).click();
    expect(await findByCode(page, '/assembly-groups', 'demo-labour')).toBeTruthy();
  });
});

test.describe('Application rates', () => {
  test('a rate is created in a sheet, its item and work unit are fixed afterwards, and it is deleted again', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/');
    const list = async (path: string) => (await api(page, 'GET', `${path}?per_page=100`)).data as any[];
    const litre = (await list('/uoms')).find((uom) => uom.code === 'l');
    const base = await findByCode<any>(page, '/items', 'demo-pack-thinner');
    expect(litre && base).toBeTruthy();
    const code = `e2e-ar-${stamp}`;
    const item = (
      await api(page, 'POST', '/items', {
        code, name: `E2E rate item ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
        inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
      })
    ).data;
    try {
      await page.goto('/application-rates');
      await page.getByRole('button', { name: 'New rate' }).click();
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: 'New application rate' })).toBeVisible();
      await pick(page, 'Item', code, sheet);
      await pick(page, 'Work unit', 'Square metre', sheet);
      await sheet.getByLabel('Quantity consumed per work unit').fill('0.25');
      await pick(page, 'Consumption unit', 'Litre', sheet);
      await sheet.getByRole('button', { name: 'Create rate' }).click();

      await expect(sheet.getByRole('heading', { name: 'Edit application rate' })).toBeVisible();
      await expect(sheet.getByRole('combobox', { name: 'Item', exact: true })).toBeDisabled();
      await expect(sheet.getByRole('combobox', { name: 'Work unit', exact: true })).toBeDisabled();

      await sheet.getByRole('button', { name: 'Delete rate' }).click();
      const dialog = page.getByTestId('delete-application-rate-dialog');
      await expect(dialog).toContainText('cannot be deleted');
      await dialog.getByRole('button', { name: 'Delete rate' }).click();
      await expect(page).toHaveURL(/\/application-rates(\?.*)?$/);
      const rates = (await api(page, 'GET', `/application-rates?filter[item_id]=${item.id}`)).data as unknown[];
      expect(rates).toHaveLength(0);
    } finally {
      const rates = (await api(page, 'GET', `/application-rates?filter[item_id]=${item.id}`)).data as Array<{ id: number }>;
      for (const rate of rates) await api(page, 'DELETE', `/application-rates/${rate.id}`);
    }
  });

  test('a rate an assembly component points to cannot be deleted: the reason is shown and the rate stays', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/');
    const list = async (path: string) => (await api(page, 'GET', `${path}?per_page=100`)).data as any[];
    const litre = (await list('/uoms')).find((uom) => uom.code === 'l');
    const sqm = (await api(page, 'GET', '/uoms?filter[search]=Square metre&per_page=10')).data[0];
    const base = await findByCode<any>(page, '/items', 'demo-pack-thinner');
    const item = (
      await api(page, 'POST', '/items', {
        code: `e2e-ar2-${stamp}`, name: `E2E used rate item ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
        inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
      })
    ).data;
    const rate = (await api(page, 'POST', '/application-rates', { item_id: item.id, work_uom_id: sqm.id, qty_per_work_unit: '0.5', uom_id: litre.id, is_default: true })).data;
    const assembly = (await api(page, 'POST', '/assemblies', { code: `e2e-asm2-${stamp}`, name: `E2E rate user ${stamp}`, work_uom_id: sqm.id, margin_type: 'MARGIN', margin_pct: '10' })).data;
    await api(page, 'POST', `/assemblies/${assembly.id}/versions`, {
      components: [{ kind: 'MATERIAL', item_id: item.id, application_rate_id: rate.id, qty_per_work_unit: '1', uom_id: litre.id, sequence: 0 }],
    });
    try {
      await page.goto(`/application-rates/${rate.id}/edit`);
      await page.getByRole('dialog').getByRole('button', { name: 'Delete rate' }).click();
      const dialog = page.getByTestId('delete-application-rate-dialog');
      await dialog.getByRole('button', { name: 'Delete rate' }).click();
      await expect(dialog.getByRole('alert')).toContainText('still referenced');
      await dialog.getByRole('button', { name: 'Keep it' }).click();
      expect(((await api(page, 'GET', `/application-rates/${rate.id}`)).data as { id: number }).id).toBe(rate.id);
    } finally {
      await api(page, 'DELETE', `/assemblies/${assembly.id}`);
    }
  });

  test('searching by item name reaches the API and shows a removable chip', async ({ page }) => {
    await page.goto('/application-rates');
    const request = page.waitForRequest((r) => r.url().includes('/api/v1/application-rates') && r.url().includes('filter%5Bsearch%5D=paint'));
    await page.getByRole('searchbox', { name: 'Search application rates' }).fill('paint');
    await request;
    const chip = page.locator('.ui-chip-active').filter({ hasText: 'Search: "paint"' });
    await expect(chip).toBeVisible();
    await expect(page.getByRole('row', { name: /paint/i }).first()).toBeVisible();
    await chip.getByRole('button', { name: /Remove filter/ }).click();
    await expect(chip).toHaveCount(0);
  });
});

test.describe('Formulations', () => {
  test('the resolution tester names the recipe that applies for a customer, and says so when none does', async ({ page }) => {
    await page.goto('/');
    const litre = ((await api(page, 'GET', '/uoms?per_page=100')).data as any[]).find((uom) => uom.code === 'l');
    const base = await findByCode<any>(page, '/items', 'demo-pack-thinner');
    const code = `e2e-fr-${stamp}`;
    await api(page, 'POST', '/items', {
      code, name: `E2E unresolved item ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
      inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
    });

    await page.goto('/formulations');
    await page.getByRole('button', { name: 'Test resolution' }).click();
    await expect(page.getByText('Preview which formulation would be used')).toBeVisible();

    await pick(page, 'Output item (resolution test)', 'demo-paint-white');
    await pick(page, 'Customer (resolution test)', 'cus-alpha');
    await page.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(page.getByTestId('formulation-resolution')).toContainText('demo-f-paint-white-alpha');
    await expect(page.getByTestId('formulation-resolution')).toContainText('has a recipe of its own');

    await pick(page, 'Output item (resolution test)', code);
    await page.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'No formulation resolves' })).toBeVisible();
  });

  test('a formulation is opened as a sheet, confirms before archiving, and archived recipes stop resolving', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/');
    const list = async (path: string) => (await api(page, 'GET', `${path}?per_page=100`)).data as any[];
    const litre = (await list('/uoms')).find((uom) => uom.code === 'l');
    const base = await findByCode<any>(page, '/items', 'demo-pack-thinner');
    const input = await findByCode<any>(page, '/items', 'demo-pack-thinner');
    const code = `e2e-fa-${stamp}`;
    const output = (
      await api(page, 'POST', '/items', {
        code, name: `E2E recipe output ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
        inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
      })
    ).data;
    const formulation = (
      await api(page, 'POST', '/formulations', {
        output_item_id: output.id, code: `e2e-f-${stamp}`, name: `E2E formulation ${stamp}`, output_qty: '1', output_uom_id: litre.id,
        lines: [{ input_item_id: input.id, qty: '1', uom_id: litre.id, wastage_pct: '0', sequence: 0 }],
      })
    ).data;

    await page.goto(`/formulations?filter.search=e2e-f-${stamp}`);
    await page.getByRole('row', { name: new RegExp(`e2e-f-${stamp}`) }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: `E2E formulation ${stamp}` })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/formulations/${formulation.id}/edit\\?.*filter.search=e2e-f-${stamp}`));
    // Output item and customer are shown, not editable.
    await expect(sheet.getByText(`${code} — E2E recipe output ${stamp}`).first()).toBeVisible();

    await sheet.getByRole('button', { name: 'Archive', exact: true }).click();
    const dialog = page.getByTestId('archive-formulation-dialog');
    await expect(dialog).toContainText('There is no way to un-archive it');
    await dialog.getByRole('button', { name: 'Keep it active' }).click();
    expect(((await api(page, 'GET', `/formulations/${formulation.id}`)).data as { status: string }).status).not.toBe('ARCHIVED');

    await sheet.getByRole('button', { name: 'Archive', exact: true }).click();
    await page.getByTestId('archive-formulation-dialog').getByRole('button', { name: 'Archive formulation' }).click();
    await expect(page).toHaveURL(/\/formulations(\?.*)?$/);
    await expect(sheet).toHaveCount(0);
    expect(((await api(page, 'GET', `/formulations/${formulation.id}`)).data as { status: string }).status).toBe('ARCHIVED');
    await expect(page.getByRole('row', { name: new RegExp(`e2e-f-${stamp}`) })).toContainText('Archived');
  });
});

test.describe('Assemblies', () => {
  test('a draft version is edited in place, the assembly is archived after a confirmation', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/');
    const list = async (path: string) => (await api(page, 'GET', `${path}?per_page=100`)).data as any[];
    const litre = (await list('/uoms')).find((uom) => uom.code === 'l');
    const sqm = (await api(page, 'GET', '/uoms?filter[search]=Square metre&per_page=10')).data[0];
    const material = await findByCode<any>(page, '/items', 'demo-paint-white');
    expect(litre && sqm && material).toBeTruthy();
    const assembly = (
      await api(page, 'POST', '/assemblies', { code: `e2e-asm-${stamp}`, name: `E2E assembly ${stamp}`, work_uom_id: sqm.id, margin_type: 'MARGIN', margin_pct: '10' })
    ).data;
    const version = (
      await api(page, 'POST', `/assemblies/${assembly.id}/versions`, {
        notes: 'first cut',
        components: [{ kind: 'MATERIAL', item_id: material.id, qty_per_work_unit: '2', uom_id: litre.id, sequence: 0 }],
      })
    ).data;

    await page.goto(`/assemblies?filter.search=e2e-asm-${stamp}`);
    await page.getByRole('row', { name: new RegExp(`e2e-asm-${stamp}`) }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: `E2E assembly ${stamp}` })).toBeVisible();
    const card = sheet.getByTestId('assembly-version-1');
    await expect(card).toContainText('first cut');
    await expect(card).toContainText('MATERIAL');

    await card.getByRole('button', { name: 'Edit draft' }).click();
    const form = sheet.getByRole('form', { name: 'Edit draft version 1' });
    // The editor opens on what is stored, not on blanks.
    await expect(form.getByLabel('Version notes')).toHaveValue('first cut');
    await expect(form.getByLabel('Quantity per work unit')).toHaveValue(/^2(\.0+)?$/);
    await form.getByLabel('Version notes').fill('second cut');
    await form.getByLabel('Quantity per work unit').fill('3');
    await form.getByRole('button', { name: 'Save draft' }).click();
    await expect(form).toHaveCount(0);

    const stored = (await api(page, 'GET', `/assemblies/${assembly.id}?include=versions.components`)).data.versions.find((v: { id: number }) => v.id === version.id);
    expect(stored.notes).toBe('second cut');
    expect(Number(stored.components[0].qty_per_work_unit)).toBe(3);
    expect(stored.components[0].item_id).toBe(material.id);

    // Archive: confirmed, with the consequence stated.
    await sheet.getByRole('button', { name: 'Archive', exact: true }).click();
    const dialog = page.getByTestId('archive-assembly-dialog');
    await expect(dialog).toContainText('There is no way to un-archive it');
    await dialog.getByRole('button', { name: 'Archive assembly' }).click();
    await expect(page).toHaveURL(/\/assemblies(\?.*)?$/);
    expect(((await api(page, 'GET', `/assemblies/${assembly.id}`)).data as { status: string }).status).toBe('ARCHIVED');
  });
});

test.describe('Resource rates', () => {
  test('a rate is added in a sheet, read as a record, and deleted after a confirmation', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/');
    const painter = await findByCode<any>(page, '/items', 'demo-painter-hour');
    expect(painter).toBeTruthy();
    const mine = async () =>
      ((await api(page, 'GET', `/resource-rates?filter[item_id]=${painter.id}&sort=-created_at&per_page=20`)).data as Array<{ id: number; rate?: string }>).filter(
        (candidate) => Number(candidate.rate) === 21.5,
      );
    try {
      await page.goto('/resource-rates');
      await page.getByRole('button', { name: 'New rate' }).click();
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: 'New resource rate' })).toBeVisible();
      await pick(page, 'Item', 'demo-painter-hour', sheet);
      await sheet.getByRole('textbox', { name: 'Rate', exact: true }).fill('21.5');
      await pick(page, 'Per unit', 'hr', sheet);
      await sheet.getByRole('button', { name: 'Add rate' }).click();
      await expect(page).toHaveURL(/\/resource-rates(\?.*)?$/);
      await expect(sheet).toHaveCount(0);

      const [created] = await mine();
      expect(created).toBeTruthy();
      await page.goto(`/resource-rates/${created!.id}`);
      const record = page.getByRole('dialog');
      await expect(record.getByRole('heading', { name: /demo-painter-hour/ })).toBeVisible();
      await expect(record).toContainText('21.5');
      await record.getByRole('button', { name: 'Delete' }).click();
      const dialog = page.getByTestId('delete-resource-rate-dialog');
      await expect(dialog).toContainText('cannot be brought back');
      await dialog.getByRole('button', { name: 'Delete rate' }).click();
      await expect(page).toHaveURL(/\/resource-rates(\?.*)?$/);
      expect(await mine()).toHaveLength(0);
    } finally {
      for (const rate of await mine()) await api(page, 'DELETE', `/resource-rates/${rate.id}`);
    }
  });

  test('a rate record is read-only with Delete as its only change; a reader without the duty is offered neither Add nor Delete', async ({ page }) => {
    await page.goto('/resource-rates');
    const first = page.getByRole('table').locator('tbody tr').first();
    // A real row, not the loading skeleton that stands in for it.
    await expect(first).toHaveAttribute('tabindex', '0');
    await first.click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByText('A rate cannot be edited')).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Delete' })).toBeVisible();
    await sheet.getByRole('button', { name: 'Close' }).click();

    await actAs(page, ['estimating.internal_cost.view']);
    await page.goto('/resource-rates');
    await expect(page.getByRole('heading', { name: 'Resource rates', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New rate' })).toHaveCount(0);
    const reader = page.getByRole('table').locator('tbody tr').first();
    await expect(reader).toHaveAttribute('tabindex', '0');
    await reader.click();
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Delete' })).toHaveCount(0);
  });
});

test.describe('Overhead policy drafts', () => {
  test('a draft policy can be edited and deleted from an assembly group; an unactivated draft never changes the active policy', async ({ page }) => {
    test.setTimeout(120_000);
    const code = `e2e-op-${stamp}`;
    await page.goto('/assembly-groups');
    const group = (await api(page, 'POST', '/assembly-groups', { code, name: `E2E overhead group ${stamp}` })).data as { id: number };
    try {
      await page.goto(`/assembly-groups/${group.id}/edit`);
      const sheet = page.getByRole('dialog');
      await sheet.getByRole('button', { name: 'Set a new policy' }).click();
      const form = sheet.getByRole('form', { name: 'New overhead policy' });
      await form.getByLabel('Rate').fill('7');
      await form.getByLabel('Reason').fill('First estimate');
      await form.getByRole('button', { name: 'Save as draft' }).click();
      await expect(sheet.getByText('"First estimate"')).toBeVisible();

      // Edit the draft: it opens on its stored values.
      await sheet.getByRole('button', { name: /^Edit draft/ }).click();
      const edit = sheet.getByRole('form', { name: 'Edit draft overhead policy' });
      await expect(edit.getByLabel('Rate')).toHaveValue(/^7(\.0+)?$/);
      await edit.getByLabel('Rate').fill('9');
      await edit.getByLabel('Reason').fill('Revised estimate');
      await edit.getByRole('button', { name: 'Save draft' }).click();
      await expect(sheet.getByText('"Revised estimate"')).toBeVisible();
      await expect(sheet.getByText('"First estimate"')).toHaveCount(0);

      // Delete the draft, after a confirmation.
      await sheet.getByRole('button', { name: /^Delete draft/ }).click();
      const dialog = page.getByTestId('delete-overhead-policy-dialog');
      await expect(dialog).toContainText('Only a draft can be deleted');
      await dialog.getByRole('button', { name: 'Delete draft' }).click();
      await expect(sheet.getByText('"Revised estimate"')).toHaveCount(0);
      const policies = (await api(page, 'GET', `/overhead-policies?filter[scope_type]=ASSEMBLY_GROUP&filter[scope_id]=${group.id}`)).data as unknown[];
      expect(policies).toHaveLength(0);
    } finally {
      const policies = (await api(page, 'GET', `/overhead-policies?filter[scope_type]=ASSEMBLY_GROUP&filter[scope_id]=${group.id}`)).data as Array<{ id: number; status: string }>;
      for (const policy of policies.filter((candidate) => candidate.status === 'DRAFT')) await api(page, 'DELETE', `/overhead-policies/${policy.id}`);
      if (policies.every((candidate) => candidate.status === 'DRAFT')) await api(page, 'DELETE', `/assembly-groups/${group.id}`);
    }
  });
});

test.describe('Material Preview', () => {
  test('is on the screen template and has no serious accessibility violations', async ({ page }) => {
    await page.goto('/material-preview');
    await expect(page.locator('[data-screen-template="form"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Material Preview' }).first()).toBeVisible();
    await page.waitForLoadState('networkidle');
    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});

test.describe('phone width (390 px)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  for (const { name, path, create, heading } of [
    { name: 'Formulations', path: '/formulations', create: 'New formulation', heading: /New formulation/ },
    { name: 'Assemblies', path: '/assemblies', create: 'New assembly', heading: /New assembly/ },
    { name: 'Application rates', path: '/application-rates', create: 'New rate', heading: /New application rate/ },
    { name: 'Resource rates', path: '/resource-rates', create: 'New rate', heading: /New resource rate/ },
  ]) {
    test(`${name}: the create sheet fits the screen with 44 px actions`, async ({ page }) => {
      await page.goto(path);
      const button = page.getByRole('button', { name: create });
      await expect(button).toBeVisible();
      expect((await button.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await button.click();
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: heading }).first()).toBeVisible();
      expect((await sheet.boundingBox())!.width).toBeLessThanOrEqual(390.5);
      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
      const submit = sheet.getByRole('button', { name: /^(Create|Add)/ }).last();
      expect((await submit.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    });
  }
});
