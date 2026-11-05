import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_PREPARE,
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
} from "./quotation-access";

/** Approving or rejecting the recorded discount/override need of one draft (server: sales.quotation.approve, STAGED). */
export const QUOTATION_APPROVE = "sales.quotation.approve";

/**
 * Who sees the approval panel and its actions. Same pattern as the other quotation duties: the production constant keeps the whole
 * quotation UI off, and each duty is checked together with the independent view duty, as the server does. Convenience only - the
 * server refuses a request or decision without the duty, whatever the screen showed.
 */
export function quotationApprovalAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  const view = enabled && permissions.has(QUOTATION_VIEW);
  const prepare = view && permissions.has(QUOTATION_PREPARE);
  const decide = view && permissions.has(QUOTATION_APPROVE);
  return { read: prepare || decide, request: prepare, decide };
}
