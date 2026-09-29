import { useAuthStore } from "../stores/auth-store";

/** Mirrors the Purchasing API's minimum PO-draft read plus explicit cost grant. */
export function usePurchasingCommercialCost(): boolean {
  const permissions = useAuthStore((state) => state.user?.permissions);

  return (
    Array.isArray(permissions) &&
    (permissions.includes("purchasing.commercial-cost.view") ||
      permissions.includes("purchasing.purchase-order.create"))
  );
}
