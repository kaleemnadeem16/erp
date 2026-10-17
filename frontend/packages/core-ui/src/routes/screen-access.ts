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
const supplierReturn = [
  "purchasing.supplier-return.create",
  "purchasing.supplier-return.post",
] as const;
const stockScrap = ["inventory.scrap.create", "inventory.scrap.post"] as const;
const salesRead = [
  "sales.pipeline.view",
  "sales.lead.manage",
  "sales.opportunity.manage",
  "sales.opportunity.assign",
  "sales.opportunity.close",
] as const;
const physicalCount = [
  "inventory.valuation.view",
  "inventory.stock.view",
  "inventory.physical_count.create",
  "inventory.physical_count.count",
  "inventory.physical_count.post",
] as const;
const cutover = [
  "inventory.valuation.view",
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
  "inventory.stock.view",
] as const;
const recalculation = [
  "inventory.valuation.view",
  "inventory.recalculation.request",
  "inventory.recalculation.approve",
  "inventory.recalculation.approve-high-risk",
  "inventory.recalculation.execute",
  "inventory.recalculation.review-regeneration",
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
  {
    path: "/warehouses",
    anyOf: ["inventory.warehouse.manage", "inventory.location.manage"],
  },
  // Stock reads are their own capabilities (ADR 0083): the summary needs availability or stock, the ledger needs movement.
  {
    path: "/inventory-overview",
    anyOf: ["inventory.availability.view", "inventory.stock.view"],
  },
  { path: "/stock-moves", anyOf: ["inventory.movement.view"] },
  { path: "/physical-counts", anyOf: physicalCount },
  { path: "/opportunities", anyOf: salesRead },
  { path: "/leads", anyOf: salesRead },
  { path: "/sales-stages", anyOf: [...salesRead, "sales.stage.manage"] },
  { path: "/supplier-returns", anyOf: supplierReturn },
  { path: "/stock-scraps", anyOf: stockScrap },
  { path: "/inventory-cutovers", anyOf: cutover },
  { path: "/negative-stock-overrides", anyOf: negativeStock },
  { path: "/recalculation-requests", anyOf: recalculation },
  { path: "/rate-dimensions", anyOf: ["estimating.rate-dimension.manage"] },
  {
    path: "/application-rates",
    anyOf: [
      "estimating.application-rate.manage",
      "estimating.material_preview.run",
      "estimating.recipe.view",
    ],
  },
  {
    path: "/formulations",
    anyOf: ["estimating.formulation.manage", "estimating.recipe.view"],
  },
  { path: "/assembly-groups", anyOf: ["estimating.assembly-group.manage"] },
  {
    path: "/assemblies",
    anyOf: ["estimating.assembly.manage", "estimating.recipe.view"],
  },
  {
    path: "/resource-rates",
    anyOf: ["estimating.resource-rate.manage", "estimating.internal_cost.view"],
  },
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
    path: "/settings/material-selection",
    anyOf: [
      "estimating.selection-policy.manage",
      "estimating.stock_mix_policy.manage",
      "estimating.policy-history.view",
    ],
  },
  // Everyone can read the company; each field enforces its own edit permission.
  { path: "/settings/company" },
  { path: "/settings/document-numbering", anyOf: ["numbering.view"] },
  {
    path: "/settings/material-variance",
    anyOf: ["estimating.material_variance_policy.manage"],
  },
  // Anyone signed in: an empty approvals inbox is the honest answer, and delegation is open to all.
  { path: "/approvals" },
  {
    path: "/settings/approvals",
    anyOf: [
      "approvals.policy.manage",
      "approvals.settings.manage",
      "approvals.authority.manage",
      "approvals.authority.view",
      "approvals.delegation.manage",
      "approvals.history.view",
    ],
  },
  { path: "/settings/members", anyOf: ["administration.member.view"] },
  { path: "/settings/roles", anyOf: ["administration.role.view"] },
  {
    path: "/settings/organisation",
    anyOf: ["administration.organisation.view", "administration.organisation.manage"],
  },
  {
    path: "/suppliers/new",
    allOf: [
      "parties.supplier-account.manage",
      "parties.payment-terms.manage",
      "accounting.account-mapping.manage",
    ],
  },
  {
    path: "/suppliers",
    anyOf: [
      "parties.supplier-account.manage",
      "purchasing.purchase-order.view",
      "purchasing.purchase-order.create",
      "purchasing.goods-receipt.create",
      "purchasing.item-supplier.manage",
    ],
  },
  {
    path: "/settings/custom-fields",
    allOf: ["administration.custom-field.manage"],
  },
  { path: "/settings" },
  { path: "/settings/numbers" },
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
  if (
    pathname === "/goods-receipts/new" ||
    /^\/goods-receipts\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("purchasing.goods-receipt.create");
  }
  if (/^\/suppliers\/[^/]+\/edit$/.test(pathname))
    return permissions.has("parties.supplier-account.manage");
  if (pathname === "/recalculation-requests/new") {
    return permissions.has("inventory.recalculation.request");
  }
  if (pathname === "/inventory-cutovers/new") {
    return permissions.has("inventory.cutover.create");
  }
  if (
    pathname === "/supplier-returns/new" ||
    /^\/supplier-returns\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("purchasing.supplier-return.create");
  }
  if (
    pathname === "/stock-scraps/new" ||
    /^\/stock-scraps\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("inventory.scrap.create");
  }
  if (pathname === "/opportunities/new") {
    return permissions.has("sales.opportunity.manage");
  }
  if (pathname === "/leads/new") {
    return permissions.has("sales.lead.manage");
  }
  if (pathname === "/physical-counts/new") {
    return permissions.has("inventory.physical_count.create");
  }
  if (
    pathname === "/item-suppliers/new" ||
    /^\/item-suppliers\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("purchasing.item-supplier.manage");
  }
  if (
    pathname === "/application-rates/new" ||
    /^\/application-rates\/[^/]+\/edit$/.test(pathname)
  ) {
    return permissions.has("estimating.application-rate.manage");
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
