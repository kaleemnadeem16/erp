/** Disabled until the approved endpoint, record policy and rollout are delivered. */
export const QUOTATION_ISSUE_PREVIEW_ENABLED = false;

export function issuePreviewAccess(
  permissions: { prepare: boolean; issue: boolean; view: boolean },
  enabled = QUOTATION_ISSUE_PREVIEW_ENABLED,
) {
  return {
    readiness: enabled && permissions.prepare,
    approval: enabled && permissions.issue && permissions.view,
  };
}
