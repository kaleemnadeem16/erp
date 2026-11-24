import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
} from "../quotations/quotation-access";
import { CUSTOMER_DECISION_VIEW } from "../customer-decisions/customer-decision-access";

/** Staged duty (server: PermissionVocabulary). The screens never grant it and no seeder holds it. */
export const HANDOVER_PREPARE = "sales.handover.prepare";

/**
 * Presentation gate for preparing a handover: the same production gate as the other quotation-family screens, the exact preparation duty and
 * the independent decision and quotation reads. Every rule is repeated by the server. Preparing is never permission to release.
 */
export function handoverPrepareAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  return (
    enabled &&
    permissions.has(HANDOVER_PREPARE) &&
    permissions.has(CUSTOMER_DECISION_VIEW) &&
    permissions.has(QUOTATION_VIEW)
  );
}
