import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
} from "../quotations/quotation-access";
import { CUSTOMER_DECISION_VIEW } from "../customer-decisions/customer-decision-access";

/** Staged duties (server: PermissionVocabulary). The screens never grant them and no seeder holds them. */
export const HANDOVER_COMPLETION_BASIS_RECORD =
  "sales.handover.completion-basis.record";
export const HANDOVER_RELEASE = "sales.handover.release";

/**
 * Presentation gate for the handover and release screen. Same production gate as the quotation, Customer Messages and acceptance
 * screens (off in production, whatever a person holds). Every rule is repeated by the server: reading a prepared handover needs the
 * independent decision read and quotation read; recording a basis and reviewing or releasing each need their own exact duty, and a
 * release additionally needs the Jobs receiving duty, which only the server can judge.
 */
export function handoverReleaseAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  const read =
    enabled &&
    permissions.has(CUSTOMER_DECISION_VIEW) &&
    permissions.has(QUOTATION_VIEW);
  return {
    /** The prepared handover and its acceptance. */
    read,
    /** The release review (the same duty as the release itself). */
    review: read && permissions.has(HANDOVER_RELEASE),
    /** Recording customer-agreed completion conditions. */
    recordBasis: read && permissions.has(HANDOVER_COMPLETION_BASIS_RECORD),
    release: read && permissions.has(HANDOVER_RELEASE),
  };
}
