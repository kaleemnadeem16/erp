import { expect, test } from '@playwright/test';

const operational = [
  {
    title: 'Purchase order',
    fields: [
      { label: 'Supplier', value: 'Review supplier' },
      { label: 'Ordered quantity', value: '7.0000' },
      { label: 'Description / specification', value: 'Exterior coating specification' },
    ],
  },
];
const commercial = [
  {
    title: 'Line 1 — pricing',
    fields: [
      { label: 'Unit price', value: '123.4500' },
      { label: 'Tax', value: '0.0000' },
    ],
  },
];

async function mockReview(page: import('@playwright/test').Page, profile: 'COMMERCIAL' | 'OPERATIONAL' | 'MISSING') {
  const request = {
    id: 880085,
    subject_type: 'PURCHASE_ORDER',
    subject_id: 880085,
    status: 'PENDING',
    summary: { number: 'PO-REVIEW-85', supplier: 'Review supplier' },
    policy: { id: 85, code: 'review', version_no: 1, name: 'Document review' },
    submitted_by: { id: 86, name: 'Buyer' },
    submitted_at: '2026-10-06T09:00:00Z',
    closed_at: null,
    closing_reason: null,
    amount_base: profile === 'COMMERCIAL' ? '864.1500' : null,
    evidence: [],
    evidence_visible: profile === 'COMMERCIAL',
    document_review_required: true,
    document_review_ready: profile !== 'MISSING',
    document_review: {
      available: profile !== 'MISSING',
      profile: profile === 'MISSING' ? null : profile,
      captured_at: '2026-10-06T09:00:00Z',
      sections: profile === 'MISSING' ? [] : profile === 'COMMERCIAL' ? [...operational, ...commercial] : operational,
    },
    // Intentionally inconsistent for MISSING: the browser must still refuse a blind decision.
    can_decide: true,
    my_step_id: 85,
    steps: [
      {
        id: 85,
        stage: 1,
        name: 'Review step',
        status: 'OPEN',
        required_approvals: 1,
        approved_count: 0,
        deadline_at: null,
        overdue: false,
        uses_authority_limit: false,
        decisions: [],
        review_profile: profile === 'OPERATIONAL' ? profile : 'COMMERCIAL',
      },
    ],
  };
  await page.route('**/api/v1/approval-requests/inbox', (route) => route.fulfill({ json: { data: [request] } }));
  await page.route('**/api/v1/approval-requests/880085/decide', (route) =>
    route.fulfill({ json: { data: { ...request, status: 'APPROVED', can_decide: false } } }),
  );
  // No live PO fetch is part of review: detect accidental broad document access.
  const poReads: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/api/v1/purchase-orders')) poReads.push(request.url());
  });
  await page.goto('/approvals');
  return poReads;
}

test('commercial review shows submitted lines and pricing in the screen and print tree before a mocked decision', async ({
  page,
}) => {
  const poReads = await mockReview(page, 'COMMERCIAL');
  const review = page.getByRole('region', { name: 'Submitted document review' });
  await expect(review.getByText('Exterior coating specification').first()).toBeVisible();
  await expect(review.getByText('123.4500').first()).toBeVisible();
  await expect(review.locator('div.hidden')).toContainText('123.4500');
  await expect(review.getByRole('button', { name: /print/i })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
  const decision = page.waitForRequest('**/api/v1/approval-requests/880085/decide');
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  expect((await decision).postDataJSON()).toMatchObject({ decision: 'APPROVED', step_id: 85 });
  expect(poReads).toEqual([]);
});

test('technical review has operational evidence but no prices in either the screen or print tree', async ({ page }) => {
  await mockReview(page, 'OPERATIONAL');
  const review = page.getByRole('region', { name: 'Submitted document review' });
  await expect(review.getByText('Exterior coating specification').first()).toBeVisible();
  await expect(review.getByText('Unit price', { exact: true })).toHaveCount(0);
  await expect(review).not.toContainText('123.4500');
  await expect(review.locator('div.hidden')).toContainText('Exterior coating specification');
});

test('missing document evidence disables decisions and explains how to obtain a fresh review', async ({ page }) => {
  await mockReview(page, 'MISSING');
  await expect(page.getByText(/Ask the submitter to withdraw and submit/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Reject', exact: true })).toBeDisabled();
});
