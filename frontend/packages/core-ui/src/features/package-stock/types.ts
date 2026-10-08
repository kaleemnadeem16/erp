/**
 * The shapes the package-stock screens read. They mirror the JSON of /package-balances, /items/{item}/package-tracking and /package-draws/suggest
 * (docs/phases/package-balance-ui.md); quantities are decimal strings exactly as the server sends them.
 */
export interface SealedRow {
  item_packaging_id: number;
  code: string | null;
  name: string | null;
  count: number;
  content_quantity: string;
  quantity: string;
  availability: string;
}

export interface PackageBalance {
  item_id: number;
  batch_id: number | null;
  location: { id: number; code: string; name: string; warehouse_name: string };
  package_tracking_mode:
    "QUANTITY_ONLY" | "PACKAGE_BALANCE" | "CONTAINER_TRACKED";
  total: string;
  /** Total of the usable breakdown; equals `total` unless some stock is held for QA or rejected. */
  usable_total?: string;
  sealed: SealedRow[];
  loose: string;
  held?: PackageGroup;
  rejected?: PackageGroup;
  fresh: boolean;
  reasons: string[];
  operation_id: number | null;
}

/** Held (awaiting QA) or rejected packages at a location (ADR 0094). */
export interface PackageGroup {
  sealed: SealedRow[];
  loose: string;
  total: string;
}

export interface PackagingOption {
  id: number;
  code: string;
  name: string;
  stock_quantity: string;
  inner_packaging_id?: number | null;
}

export interface TrackingState {
  item_id: number;
  mode: "QUANTITY_ONLY" | "PACKAGE_BALANCE" | "CONTAINER_TRACKED";
  stock_uom_id: number;
  activated_at: string | null;
  activation_reason: string | null;
  tracked_levels: PackagingOption[];
  packagings: PackagingOption[];
  can_activate: boolean;
  can_view_balances: boolean;
}

export type DrawLine =
  | { kind: "SEALED"; packaging_id: number; count: number; name?: string }
  | { kind: "LOOSE"; quantity: string }
  | {
      kind: "OPEN_AND_DRAW";
      packaging_id: number;
      count: number;
      quantity: string;
      name?: string;
    };

export interface SuggestedDraw {
  lines: Array<DrawLine & { quantity?: string }>;
  total: string;
  requires_opening: boolean;
  leaves: {
    sealed: Array<{
      item_packaging_id: number;
      name: string;
      count: number;
      content_quantity: string;
    }>;
    loose: string;
    total: string;
  };
}

export const REASON_HINTS: Record<string, string> = {
  NEEDS_COUNT_FIRST:
    "This location has not been counted by package yet — count it first.",
  STALE_EVIDENCE:
    "Stock moved since the last package count — recount this location.",
  DRAW_NOT_ON_HAND:
    "That is no longer on the shelf — review what is available.",
  UNTRACKED_LEVEL: "This item does not track that package size.",
  NEEDS_OBSERVED_COMPOSITION: "Record what physically arrived before posting.",
  ACTIVATION_BLOCKED: "Something is blocking activation.",
  NEEDS_DECLARED_PORTION:
    "State which held packages this QA decision applies to.",
  QA_STOCK_HELD:
    "This location has packages under QA inspection or rejected: finish the QA decisions before counting it.",
  PACKAGE_TRACKED_ITEM:
    "This item is package-tracked; use its package-aware operation.",
  NOTHING_REJECTED_TO_EXIT:
    "There is no rejected stock left on this delivery line.",
  OUTCOME_CHANGE_REASON_REQUIRED:
    "This differs from the quality decision — say why.",
  DOCUMENT_NOT_DRAFT: "This document is no longer a draft, so it cannot be changed.",
  EMPTY_DOCUMENT: "Add at least one delivery line.",
  DUPLICATE_RECEIPT_LINE: "That delivery line is already on this document.",
  MIXED_SUPPLIERS: "A supplier return goes to one supplier — remove the lines from the other supplier.",
  MIXED_WAREHOUSES: "A document covers one warehouse — remove the lines from the other warehouse.",
  OUT_OF_SCOPE: "That stock is outside the warehouses you can work in.",
};
