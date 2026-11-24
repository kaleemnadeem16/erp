/**
 * Whether THIS purchase order's money is shown to the signed-in person. The server decides it order by order (the commercial capability AND the order inside that
 * capability's own reach, ADR 0192) and says so on the order; screens, headings, controls and the print follow it. The general permission only says someone *may*
 * hold a commercial duty — it cannot tell one order from another, so it is never used to show an order's money.
 */
export function mayViewOrderCommercial(order: { commercial_visible?: boolean } | null | undefined): boolean {
  return order?.commercial_visible === true;
}
