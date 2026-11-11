import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
} from "../quotations/quotation-access";
import { CUSTOMER_DECISION_VIEW } from "../customer-decisions/customer-decision-access";

/** Staged duty (server: PermissionVocabulary). The screens never grant it and no seeder holds it. */
export const CUSTOMER_DECISION_ACCEPT = "sales.customer-decision.accept";

/**
 * Presentation gate for recording and reading a customer acceptance. Same production gate as the quotation and Customer Messages
 * screens (off in production, whatever a person holds). The server repeats every rule: accepting needs the accept duty AND the
 * independent decision read AND the independent quotation read; reading an acceptance needs the last two.
 */
export function customerAcceptanceAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  const read =
    enabled &&
    permissions.has(CUSTOMER_DECISION_VIEW) &&
    permissions.has(QUOTATION_VIEW);
  return {
    /** The acceptance detail and its readiness. */
    read,
    /** The Accept panel on an issued quotation. */
    accept: read && permissions.has(CUSTOMER_DECISION_ACCEPT),
  };
}

/** Why the Accept panel cannot be used by this person, in words; null when they hold what it needs. */
export function acceptDisabledReason(
  permissions: ReadonlySet<string>,
): string | null {
  if (!permissions.has(CUSTOMER_DECISION_VIEW))
    return "You need permission to see customer messages before you can record an acceptance.";
  if (!permissions.has(CUSTOMER_DECISION_ACCEPT))
    return "Recording an acceptance needs a separate permission, which your access does not include. Ask an administrator.";
  return null;
}
