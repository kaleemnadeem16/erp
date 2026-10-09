import type { OptionsStyle, Presentation } from "@erp/core-ui";

/**
 * How each record screen is shown: `"page"` (a page of its own) or `"sheet"` (a
 * side sheet over its list, which stays visible behind it). Both are implemented
 * for every screen listed here — change one word to try the other, nothing else.
 * What exists and where: docs/architecture/ui-building-blocks.md.
 */
export const presentation: {
  purchaseOrderCreate: Presentation;
  purchaseOrderDetail: Presentation;
  purchaseOrderEdit: Presentation;
  supplierCreate: Presentation;
  supplierDetail: Presentation;
  supplierEdit: Presentation;
  goodsReceiptCreate: Presentation;
  goodsReceiptDetail: Presentation;
  goodsReceiptEdit: Presentation;
  itemSupplierCreate: Presentation;
  itemSupplierDetail: Presentation;
  itemSupplierEdit: Presentation;
  itemCreate: Presentation;
  itemDetail: Presentation;
  itemEdit: Presentation;
} = {
  purchaseOrderCreate: "sheet",
  purchaseOrderDetail: "sheet",
  purchaseOrderEdit: "sheet",
  supplierCreate: "sheet",
  supplierDetail: "sheet",
  supplierEdit: "sheet",
  goodsReceiptCreate: "sheet",
  goodsReceiptDetail: "sheet",
  goodsReceiptEdit: "sheet",
  itemSupplierCreate: "sheet",
  itemSupplierDetail: "sheet",
  itemSupplierEdit: "sheet",
  itemCreate: "sheet",
  itemDetail: "sheet",
  itemEdit: "sheet",
};

/**
 * How the purchase order form puts its rarely-changed fields away: `"disclosure"` — one "More options" show/hide toggle (the
 * order's, and one per line), fields keep their defaults — or `"menu"` — the earlier "Advanced options" checkbox menus. Both
 * are implemented; change one word to try the other.
 */
export const purchaseOrderOptionsStyle: OptionsStyle = "disclosure";
