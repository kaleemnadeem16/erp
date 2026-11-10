import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_VIEW,
} from "../quotations/quotation-access";

/** Staged duties (server: PermissionVocabulary). Neither is granted to anyone by the screens. */
export const FINANCIAL_CONFIGURE = "sales.financial.configure";
export const AUTHORITY_EVIDENCE_VIEW = "sales.discount-authority.evidence.view";

/**
 * Presentation gate for the discount authority screens. Same pattern as the other quotation duties: the production constant
 * keeps the quotation UI off, so a production build denies every screen whatever a person holds (owner decision D13: the
 * feature stays inactive by default). The server still refuses without the duty; this is only a convenience.
 */
export function discountAuthorityAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  return {
    /** Configuration: policy versions and named assignments (financial configuration duty only). */
    configure: enabled && permissions.has(FINANCIAL_CONFIGURE),
    /** Protected evidence on one quotation: its own duty AND an independent quotation view. */
    evidence:
      enabled &&
      permissions.has(QUOTATION_VIEW) &&
      permissions.has(AUTHORITY_EVIDENCE_VIEW),
  };
}
