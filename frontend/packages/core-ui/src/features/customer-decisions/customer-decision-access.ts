import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import { QUOTATION_UI_ENABLED } from "../quotations/quotation-access";

/** Staged duties (server: PermissionVocabulary). Neither is granted to anyone by the screens. */
export const CUSTOMER_DECISION_VIEW = "sales.customer-decision.view";
export const CUSTOMER_DECISION_RECORD =
  "sales.customer-decision.evidence.record";

/**
 * Presentation gate for the customer decision screens ("what the customer sent"). Same pattern as the quotation and discount authority
 * screens: the production constant keeps the quotation UI off, so a production build denies every screen whatever a person holds (the
 * feature stays inactive by default). The server still refuses without the duty; this is only a convenience.
 *
 * Recording needs reading as well: a person who can record but not read could not see what they recorded, so the screens offer neither.
 */
export function customerDecisionAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  const view = enabled && permissions.has(CUSTOMER_DECISION_VIEW);
  return {
    /** The case list and a case's detail. */
    view,
    /** Opening a case and adding an observation (its own duty AND the independent read). */
    record: view && permissions.has(CUSTOMER_DECISION_RECORD),
  };
}
