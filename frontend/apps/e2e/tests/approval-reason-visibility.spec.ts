import { expect, test, type Page } from "@playwright/test";

/**
 * ADR 0087 (Q35): a reason written on an approval is shown only to people entitled to the request's evidence, to an auditor an
 * administrator names, and to its author. The server decides (its tests read the response bodies); this checks the screen says so
 * plainly instead of looking as if nothing was written. The API is mocked, as in the document-review spec.
 */
const REASON = "Unit price is far too high";

async function mockClosedRequest(page: Page, visible: boolean) {
  const request = {
    id: 880087,
    subject_type: "PURCHASE_ORDER",
    subject_id: 880087,
    status: "REJECTED",
    summary: { number: "PO-REASON-87", supplier: "Reason supplier" },
    policy: { id: 87, code: "reason", version_no: 1, name: "Reason policy" },
    submitted_by: { id: 86, name: "Buyer" },
    submitted_at: "2026-10-06T09:00:00Z",
    closed_at: "2026-10-06T10:00:00Z",
    closing_reason: visible ? REASON : null,
    closing_reason_visible: visible,
    amount_base: null,
    evidence: [],
    evidence_visible: false,
    document_review_required: false,
    document_review_ready: true,
    can_decide: false,
    my_step_id: null,
    steps: [
      {
        id: 87,
        stage: 1,
        name: "Manager",
        status: "REJECTED",
        required_approvals: 1,
        approved_count: 0,
        deadline_at: null,
        overdue: false,
        uses_authority_limit: false,
        review_profile: "COMMERCIAL",
        decisions: [
          {
            id: 870,
            decision: "REJECTED",
            reason: visible ? REASON : null,
            reason_visible: visible,
            decided_by: { id: 5, name: "Approver Ahmed" },
            acting_for: null,
            decided_at: "2026-10-06T10:00:00Z",
          },
        ],
      },
    ],
  };
  await page.route("**/api/v1/approval-requests/inbox", (route) =>
    route.fulfill({ json: { data: [request] } }),
  );
  await page.goto("/approvals");
}

test("a reason the viewer may not read is replaced by a plain note, with the outcome and decider still shown", async ({
  page,
}) => {
  await mockClosedRequest(page, false);
  await expect(page.getByText(/Rejected by Approver Ahmed/)).toBeVisible();
  await expect(page.getByText("reason not shown to you")).toBeVisible();
  await expect(
    page.getByText("The reason this request was closed is not shown to you."),
  ).toBeVisible();
  await expect(page.getByText(REASON)).toHaveCount(0);
});

test("a reason the viewer may read is shown, with no withheld note", async ({
  page,
}) => {
  await mockClosedRequest(page, true);
  await expect(page.getByText(/Rejected by Approver Ahmed/)).toBeVisible();
  await expect(page.getByText(REASON).first()).toBeVisible();
  await expect(page.getByText("reason not shown to you")).toHaveCount(0);
  await expect(page.getByText("not shown to you")).toHaveCount(0);
});
