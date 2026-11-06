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
    review:
      enabled &&
      permissions.has(QUOTATION_VIEW) &&
      permissions.has(QUOTATION_PREPARE),
  };
}
/** Creation keeps its original prepare capability gate; review/edit require view + prepare. */
export function quotationCreationAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
): boolean {
  return enabled && permissions.has(QUOTATION_PREPARE);
}
export const QUOTATION_DISCOUNT = "sales.quotation.discount";
export const QUOTATION_PRICE_OVERRIDE = "sales.quotation.price.override";
export const QUOTATION_COMMERCIAL_VIEW = "sales.quotation.commercial.view";

/**
 * Which concession controls the editor offers. They follow the same shape as the other quotation duties: the production constant keeps every one off, each duty is
 * checked with the independent view and prepare duties (as the server's Request and Action do), and this is only a convenience — the server refuses a body that touches a
 * discount or an override without the duty, whatever the screen showed.
 */
export function quotationConcessionAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED || quotationDevModeEnabled(),
) {
  const base =
    enabled &&
    permissions.has(QUOTATION_VIEW) &&
    permissions.has(QUOTATION_PREPARE);
  return {
    discount: base && permissions.has(QUOTATION_DISCOUNT),
    override: base && permissions.has(QUOTATION_PRICE_OVERRIDE),
  };
}
