import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * The shared approval engine's screens (ADR 0070) against the demo data: approvals are switched on for purchase orders with two
 * amount-banded policies, and orders wait in each state. Everything here is read-only except authoring and discarding a draft
 * policy, so the specs stay repeatable. The demo login holds every capability and has no approval ceiling.
 */
async function noSeriousViolations(page: import('@playwright/test').Page) {
  const results = await new AxeBuilder({ page }).analyze();
  const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
}

test('the settings screen shows what needs approval, the banded policies with their steps, and stays accessible', async ({ page }) => {
  await page.goto('/settings/approvals');
  await expect(page.getByRole('heading', { name: 'Approvals', level: 1 })).toBeVisible();

  await expect(page.getByLabel('Approval for Purchase order')).toContainText('When a policy applies');

  const large = page.getByRole('listitem', { name: /Orders of 500,000 and over version 1/ });
  await expect(large.getByText('Active', { exact: true })).toBeVisible();
  await expect(large.getByText(/Applies when: Order total \(base currency\) is at least 500000/)).toBeVisible();
  await expect(large.getByText(/Stage 1: Purchasing manager — 1 approval, within each approver’s limit/)).toBeVisible();
  await expect(large.getByText(/Stage 2: Finance — 2 approvals, within each approver’s limit, 48 h time limit/)).toBeVisible();

  await expect(page.getByText(/Up to 300000/)).toBeVisible();
  await noSeriousViolations(page);
});

test('a policy draft can be authored and discarded, and an active version is only ever superseded by a new one', async ({ page }) => {
  await page.goto('/settings/approvals');
  const active = page.getByRole('listitem', { name: /Orders of 100,000 and over version 1/ });
  await expect(active.getByRole('button', { name: /Edit draft/ })).toHaveCount(0);

  await active.getByRole('button', { name: /Start a new version/ }).click();
  const form = page.getByRole('form', { name: 'New policy version' });
  await expect(form.locator('#policy-name')).toHaveValue('Orders of 100,000 and over');
  await form.getByLabel('Why is this being set?').fill('End-to-end check');
  await form.getByRole('button', { name: 'Save as draft' }).click();

  const draft = page.getByRole('listitem', { name: /Orders of 100,000 and over version \d+/ }).filter({ hasText: 'Draft' });
  await expect(draft).toBeVisible();
  await draft.getByRole('button', { name: /Discard draft/ }).click();
  await expect(draft).toHaveCount(0);
});

test('the inbox shows what waits for this person, with the amount, its steps and the decision controls', async ({ page }) => {
  await page.goto('/approvals');
  await expect(page.getByRole('heading', { name: 'Approvals', level: 1 })).toBeVisible();

  const waiting = page.getByRole('region', { name: /Approval of Draft #\d+/ }).filter({ hasText: '250000' });
  await expect(waiting.getByText('Waiting for approval')).toBeVisible();
  await expect(waiting.getByText('Purchasing manager')).toBeVisible();
  await expect(waiting.getByRole('button', { name: 'Approve' })).toBeVisible();
  // Rejecting needs a reason, checked before anything is sent.
  await waiting.getByRole('button', { name: 'Reject' }).click();
  await expect(waiting.getByText('Say why you are rejecting this.')).toBeVisible();

  await expect(page.getByRole('heading', { name: 'Cover while you are away' })).toBeVisible();
  await noSeriousViolations(page);
});

test('an order waiting for approval is frozen: it cannot be issued or edited, and says why', async ({ page }) => {
  // Find the order through the inbox the SPA itself loads, so the spec uses the same session and API base as the screens.
  const inbox = page.waitForResponse((response) => /approval-requests\/inbox/.test(response.url()));
  await page.goto('/approvals');
  const requests = ((await (await inbox).json()).data) as Array<{ subject_id: number; amount_base: string }>;
  const order = requests.find((request) => request.amount_base === '250000.0000');
  expect(order, 'the demo order worth 250,000 should be waiting').toBeTruthy();

  await page.goto(`/purchase-orders/${order!.subject_id}`);
  const approval = page.getByRole('region', { name: 'Approval' });
  await expect(approval.getByText('Waiting for approval').first()).toBeVisible();
  await expect(approval.getByText(/Purchasing manager/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Issue purchase order' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Edit draft' })).toBeDisabled();
});
