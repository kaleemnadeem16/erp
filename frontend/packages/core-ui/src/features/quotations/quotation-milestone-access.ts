import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import {
  QUOTATION_UI_ENABLED,
  QUOTATION_PREPARE,
  QUOTATION_VIEW,
} from "./quotation-access";

/**
 * Duties for the list, issue and document screens. They follow the exact pattern of
 * `quotationAccess`: the production constant stays false, so a production build denies every
 * screen below whatever permissions a person holds. Each duty is checked together with the
 * independent view duty, as the server does.
 */
export const QUOTATION_ISSUE = "sales.quotation.issue";
export const DOCUMENT_VIEW = "sales.document.view";
export const DOCUMENT_GENERATE = "sales.document.generate";
export const DOCUMENT_DOWNLOAD = "sales.document.download";
export const DOCUMENT_REPAIR = "sales.document.repair";
export const DELIVERY_VIEW = "sales.delivery.view";
export const DELIVERY_RECORD = "sales.delivery.record";

export interface QuotationMilestoneAccess {
  /** The list and its side panel. */
  list: boolean;
  /** Draft actions on the list (Create draft). */
  create: boolean;
  /** Read-only readiness view (needs prepare, as the server's readiness Action does). */
  readiness: boolean;
  /** The Issue action (needs issue AND an independent view). */
  issue: boolean;
  /** Document status, history and preview. */
  documents: boolean;
  generate: boolean;
  download: boolean;
  check: boolean;
  deliveries: boolean;
  recordDelivery: boolean;
}

export const NO_MILESTONE_ACCESS: QuotationMilestoneAccess = {
  list: false,
  create: false,
  readiness: false,
  issue: false,
  documents: false,
  generate: false,
  download: false,
  check: false,
  deliveries: false,
  recordDelivery: false,
};

export function quotationMilestoneAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
): QuotationMilestoneAccess {
  if (!enabled) return NO_MILESTONE_ACCESS;
  const view = permissions.has(QUOTATION_VIEW);
  const docs = view && permissions.has(DOCUMENT_VIEW);
  return {
    list: view,
    create: view && permissions.has(QUOTATION_PREPARE),
    readiness: view && permissions.has(QUOTATION_PREPARE),
    issue: view && permissions.has(QUOTATION_ISSUE),
    documents: docs,
    generate: docs && permissions.has(DOCUMENT_GENERATE),
    download: docs && permissions.has(DOCUMENT_DOWNLOAD),
    check: docs && permissions.has(DOCUMENT_REPAIR),
    deliveries: view && permissions.has(DELIVERY_VIEW),
    recordDelivery:
      view &&
      permissions.has(DELIVERY_VIEW) &&
      permissions.has(DELIVERY_RECORD),
  };
}
