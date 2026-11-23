import type { PurchaseOrderResource } from "@erp/core-api";

/**
 * How a purchase order's payment terms read on the detail screen and the print (ADR 0191). The terms are commercial: an order read without commercial visibility carries no
 * terms at all and nothing here may fill the gap from the supplier's record. An issued order that never recorded terms says so — today's supplier default is not what was agreed.
 * Returns null when there is nothing to show (no commercial access, or the order says nothing).
 */
export function paymentTermsLabel(
  order: Pick<PurchaseOrderResource, "payment_terms_state" | "payment_terms_effective_days">,
): string | null {
  const state = order.payment_terms_state;
  if (state === undefined) return null;
  if (state === "NOT_RECORDED") return "Not recorded";
  const days = order.payment_terms_effective_days;
  if (days === undefined || days === null) return null;
  const suffix: Record<string, string> = {
    DRAFT_ORDER: "set on this order",
    DRAFT_SUPPLIER_DEFAULT: "supplier default",
    ISSUED_ORDER: "agreed on this order",
    ISSUED_SUPPLIER_DEFAULT: "supplier default when issued",
  };
  return `${days} days (${suffix[state] ?? "supplier default"})`;
}
