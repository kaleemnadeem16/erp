import { PackageBreakdown } from "./package-breakdown";
import { toUnits } from "./quantity";
import type { PackagingOption } from "./types";

export interface CountedRow {
  packaging_id: number | null;
  count: number | null;
  quantity: string;
}

/** What was counted on a line — sealed packages per tracked size and the loose quantity — in the same breakdown used everywhere else. */
export function CountedComposition({ rows, packagings, unit, total }: { rows: CountedRow[]; packagings: PackagingOption[]; unit?: string; total: string }) {
  const sealed = rows
    .filter((row) => row.packaging_id !== null && (row.count ?? 0) > 0)
    .map((row) => {
      const definition = packagings.find((p) => p.id === row.packaging_id);

      return { item_packaging_id: row.packaging_id as number, code: definition?.code ?? null, name: definition?.name ?? null, count: row.count ?? 0 };
    });
  const loose = rows.filter((row) => row.packaging_id === null).reduce((sum, row) => sum + toUnits(row.quantity), 0n);

  return <PackageBreakdown total={total} sealed={sealed} loose={(Number(loose) / 10000).toFixed(4)} unit={unit} />;
}
