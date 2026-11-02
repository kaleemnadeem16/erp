import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
/** Production rollout remains a separate reviewed change; this constant stays false. */
export const QUOTATION_UI_ENABLED = false;
export const QUOTATION_VIEW = "sales.quotation.view";
export const QUOTATION_PREPARE = "sales.quotation.prepare";
export function quotationAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  return {
    detail: enabled && permissions.has(QUOTATION_VIEW),
    review: enabled && permissions.has(QUOTATION_PREPARE),
  };
}
