/** The one gate every package control reads: a quantity-only item shows nothing package-related anywhere. */
export function useItemPackageMode(
  item: { package_tracking_mode?: string } | undefined,
): "quantity_only" | "grouped" {
  return item?.package_tracking_mode === "PACKAGE_BALANCE"
    ? "grouped"
    : "quantity_only";
}
