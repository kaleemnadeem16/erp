import { useItemsPackageTrackingShow } from "@erp/core-api";
import type { TrackingState } from "./types";

/** An item's package structure and tracked levels, for screens that are not the item page (counts, reversals). Undefined until loaded or when unreadable. */
export function useTrackingState(itemId: number, enabled: boolean): TrackingState | undefined {
  const tracking = useItemsPackageTrackingShow(itemId, { query: { enabled, retry: false } });
  const response = tracking.data as unknown as { status: number; data: { data: TrackingState } } | undefined;

  return response?.status === 200 ? response.data.data : undefined;
}
