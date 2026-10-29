/** Owner activation is a separate reviewed change. Never infer activation from a grant. */
export const QUOTATION_UI_ENABLED = false;
export const QUOTATION_VIEW = "sales.quotation.view";
export const QUOTATION_PREPARE = "sales.quotation.prepare";

export function quotationAccess(
  permissions: ReadonlySet<string>,
  enabled: boolean = QUOTATION_UI_ENABLED,
) {
  return {
    detail: enabled && permissions.has(QUOTATION_VIEW),
    review: enabled && permissions.has(QUOTATION_PREPARE),
  };
}
