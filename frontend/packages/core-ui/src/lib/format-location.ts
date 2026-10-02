/**
 * The app's one way of naming a location. A location's `code` is unique only
 * within its warehouse — two warehouses may each have a `stock` location — so
 * a code on its own cannot say where stock is, and a transfer between two such
 * locations reads "stock → stock". Every location the API returns carries its
 * warehouse, so the label is `warehouse / location`.
 *
 * Use this rather than `location.code` wherever a location is shown, so no
 * screen decides for itself and the ambiguity is fixed once.
 */
interface LocationLike {
  code: string;
  name?: string;
  warehouse?: { code: string } | null;
}

/** "main / stock". Falls back to the bare code if the warehouse was somehow not included. */
export function formatLocation(location: LocationLike): string;
/** Returns `undefined` for a missing location, so a table cell or detail field shows its "—" placeholder. */
export function formatLocation(location: LocationLike | null | undefined): string | undefined;
export function formatLocation(location: LocationLike | null | undefined): string | undefined {
  if (!location) return undefined;
  return location.warehouse ? `${location.warehouse.code} / ${location.code}` : location.code;
}

/** "main / stock — Main Stock": the label for a picker whose options benefit from the name too. */
export function formatLocationOption(location: LocationLike & { name: string }): string {
  return `${formatLocation(location)} — ${location.name}`;
}
