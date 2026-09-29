/**
 * Screen-level presentation gate. API/Action authorisation remains authoritative.
 * Some legacy setup screens have no dedicated capability yet; keep them behind
 * the two administration capabilities until their server-side retrofit lands.
 */
type Rule = {
  path: string;
  anyOf?: readonly string[];
  allOf?: readonly string[];
};

const legacySetup = [
  "administration.member.manage",
  "administration.access.assign",
] as const;

const purchaseOrder = [
  "purchasing.purchase-order.view",
  "purchasing.purchase-order.create",
  "purchasing.purchase-order.issue",
  "purchasing.purchase-order.cancel",
  "purchasing.purchase-order.short-close",
] as const;
const goodsReceipt = [
  "purchasing.goods-receipt.view",
  "purchasing.goods-receipt.create",
  "purchasing.goods-receipt.post",
  "purchasing.goods-receipt.reverse",
  "purchasing.quality-disposition.post",
] as const;
const physicalCount = [
  "inventory.physical_count.create",
  "inventory.physical_count.count",
  "inventory.physical_count.post",
] as const;
const cutover = [
  "inventory.cutover.create",
  "inventory.cutover.stage",
  "inventory.cutover.validate",
  "inventory.cutover.approve",
  "inventory.cutover.commit",
  "inventory.cutover.finalise",
  "inventory.cutover.negative_opening.request",
  "inventory.cutover.negative_opening.approve",
] as const;
const negativeStock = [
  "inventory.negative-stock.request",
  "inventory.negative-stock.override",
  "inventory.negative-stock.approve",
  "inventory.negative-stock.review",
] as const;
const recalculation = [
  "inventory.recalculation.request",
  "inventory.recalculation.approve",
  "inventory.recalculation.approve-high-risk",
  "inventory.recalculation.execute",
  "inventory.recalculation.review-regeneration",
] as const;
const stockRead = [
  "inventory.item.manage",
  "inventory.category.manage",
  ...physicalCount,
  ...cutover,
  ...negativeStock,
  ...recalculation,
] as const;

const rules: readonly Rule[] = [
  { path: "/", allOf: legacySetup },
  { path: "/purchase-orders", anyOf: purchaseOrder },
  { path: "/goods-receipts", anyOf: goodsReceipt },
  {
    path: "/item-suppliers",
    anyOf: ["purchasing.item-supplier.view", "purchasing.item-supplier.manage"],
  },
  { path: "/items", anyOf: ["inventory.item.manage"] },
  { path: "/item-quantity-units", anyOf: ["inventory.item.manage"] },
  { path: "/categories", anyOf: ["inventory.category.manage"] },
  { path: "/warehouses", allOf: legacySetup },
  { path: "/inventory-overview", anyOf: stockRead },
  { path: "/stock-moves", anyOf: stockRead },
  { path: "/physical-counts", anyOf: physicalCount },
  { path: "/inventory-cutovers", anyOf: cutover },
  { path: "/negative-stock-overrides", anyOf: negativeStock },
  { path: "/recalculation-requests", anyOf: recalculation },
  { path: "/rate-dimensions", allOf: legacySetup },
  { path: "/application-rates", allOf: legacySetup },
  { path: "/formulations", allOf: legacySetup },
  { path: "/assembly-groups", allOf: legacySetup },
  { path: "/assemblies", allOf: legacySetup },
  { path: "/resource-rates", allOf: legacySetup },
  { path: "/material-preview", anyOf: ["estimating.material_preview.run"] },
  {
    path: "/settings/purchasing",
    anyOf: [
      "purchasing.over-receipt-policy.manage",
      "purchasing.policy-history.view",
    ],
  },
  {
    path: "/settings/inventory",
    anyOf: [
      "inventory.location-policy.manage",
      "inventory.valuation-policy.manage",
      "inventory.negative-stock-policy.manage",
      "inventory.backdating-horizon-policy.manage",
      "inventory.recalculation-policy.manage",
      "inventory.policy-history.view",
    ],
  },
  {
    path: "/settings/estimating",
    anyOf: [
      "estimating.overhead-policy.manage",
      "estimating.policy-history.view",
    ],
  },
  {
    path: "/settings/material-variance",
    anyOf: ["estimating.material_variance_policy.manage"],
  },
  { path: "/settings/members", anyOf: ["administration.member.view"] },
  { path: "/settings/roles", anyOf: ["administration.role.view"] },
  { path: "/settings/account" },
  { path: "/settings/appearance" },
  { path: "/settings/printing", allOf: legacySetup },
];

function matches(pathname: string, path: string): boolean {
  return pathname === path || (path !== "/" && pathname.startsWith(`${path}/`));
}

export function canAccessScreen(
  pathname: string,
  permissions: ReadonlySet<string>,
): boolean {
  // A view-only grant must not mount an edit form through a bookmarked URL.
  if (
    pathname === "/purchase-orders/new" ||
    /^\/purchase-orders\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("purchasing.purchase-order.create");
  }
  if (pathname === "/goods-receipts/new") {
    return permissions.has("purchasing.goods-receipt.create");
  }
  if (
    pathname === "/item-suppliers/new" ||
    /^\/item-suppliers\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("purchasing.item-supplier.manage");
  }
  const rule = rules.find((candidate) => matches(pathname, candidate.path));
  if (!rule) return false;
  return (
    (!rule.allOf ||
      rule.allOf.every((permission) => permissions.has(permission))) &&
    (!rule.anyOf ||
      rule.anyOf.some((permission) => permissions.has(permission)))
  );
}

/** A restricted user's landing page should be work they can actually perform. */
export function homeScreen(permissions: ReadonlySet<string>): string {
  return (
    rules.find((rule) => canAccessScreen(rule.path, permissions))?.path ??
    "/settings/account"
  );
}
