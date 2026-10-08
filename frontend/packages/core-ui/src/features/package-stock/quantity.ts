import type { DrawLine, PackageBalance, SealedRow } from "./types";

/*
 * Stock quantities are exact decimals with four places. They are handled here as whole units of 0.0001 (bigint) so a preview never drifts
 * from what the server will prove; strings go in and out, as the API sends them.
 */
const SCALE = 4;
const UNIT = 10n ** BigInt(SCALE);

export function toUnits(value: string | number | null | undefined): bigint {
  const text = String(value ?? "").trim();
  if (
    !/^-?\d*\.?\d*$/.test(text) ||
    text === "" ||
    text === "." ||
    text === "-"
  )
    return 0n;
  const negative = text.startsWith("-");
  const [whole = "0", fraction = ""] = text.replace("-", "").split(".");
  const units =
    BigInt(whole || "0") * UNIT + BigInt((fraction + "0000").slice(0, SCALE));

  return negative ? -units : units;
}

export function fromUnits(units: bigint): string {
  const negative = units < 0n;
  const absolute = negative ? -units : units;
  const whole = absolute / UNIT;
  const fraction = (absolute % UNIT).toString().padStart(SCALE, "0");

  return `${negative ? "-" : ""}${whole}.${fraction}`;
}

/** `20.0000` → `20`; `2.5000` → `2.5`. Display only. */
export function trimQty(value: string | number | null | undefined): string {
  return fromUnits(toUnits(value))
    .replace(/(\.\d*?)0+$/, "$1")
    .replace(/\.$/, "");
}

export function isExactQuantity(value: string): boolean {
  return /^\d+(\.\d{1,4})?$/.test(value.trim());
}

export function packageLabel(row: {
  name?: string | null;
  code?: string | null;
}): string {
  return row.name ?? row.code ?? "package";
}

/** What a balance would look like after a draw — the same arithmetic the server proves, used to preview "Leaves: …". */
export function applyDraw(
  balance: Pick<PackageBalance, "sealed" | "loose" | "total">,
  lines: DrawLine[],
): { sealed: SealedRow[]; loose: string; total: string; valid: boolean } {
  let valid = true;
  const sealed = balance.sealed.map((row) => ({ ...row }));
  let loose = toUnits(balance.loose);
  let taken = 0n;
  for (const line of lines) {
    if (line.kind === "LOOSE") {
      const quantity = toUnits(line.quantity);
      loose -= quantity;
      taken += quantity;
      continue;
    }
    const row = sealed.find(
      (candidate) => candidate.item_packaging_id === line.packaging_id,
    );
    if (!row) {
      valid = false;
      continue;
    }
    row.count -= line.count;
    const content = toUnits(row.content_quantity);
    if (line.kind === "SEALED") {
      taken += content * BigInt(line.count);
    } else {
      const quantity = toUnits(line.quantity);
      loose += content * BigInt(line.count) - quantity;
      taken += quantity;
    }
  }
  if (loose < 0n || sealed.some((row) => row.count < 0)) valid = false;

  return {
    sealed,
    loose: fromUnits(loose),
    total: fromUnits(toUnits(balance.total) - taken),
    valid,
  };
}

export function drawTotal(
  balance: Pick<PackageBalance, "sealed">,
  lines: DrawLine[],
): string {
  let total = 0n;
  for (const line of lines) {
    if (line.kind === "LOOSE" || line.kind === "OPEN_AND_DRAW") {
      total += toUnits(line.quantity);
    } else {
      const row = balance.sealed.find(
        (candidate) => candidate.item_packaging_id === line.packaging_id,
      );
      total += toUnits(row?.content_quantity) * BigInt(line.count);
    }
  }

  return fromUnits(total);
}

/**
 * What a destination looks like after a draw arrives (the same rules the server applies): whole sealed packages arrive sealed, loose quantity arrives
 * loose, and what is taken from an opened package arrives loose. A package size the destination did not hold yet appears as a new sealed row.
 */
export function applyArrival(
  destination: Pick<PackageBalance, "sealed" | "loose" | "total">,
  source: Pick<PackageBalance, "sealed">,
  lines: DrawLine[],
): { sealed: SealedRow[]; loose: string; total: string } {
  const sealed = destination.sealed.map((row) => ({ ...row }));
  let loose = toUnits(destination.loose);
  let arriving = 0n;
  for (const line of lines) {
    if (line.kind === "LOOSE" || line.kind === "OPEN_AND_DRAW") {
      const quantity = toUnits(line.quantity);
      loose += quantity;
      arriving += quantity;
      continue;
    }
    const identity = source.sealed.find((row) => row.item_packaging_id === line.packaging_id);
    if (!identity) continue;
    const existing = sealed.find((row) => row.item_packaging_id === line.packaging_id);
    if (existing) existing.count += line.count;
    else sealed.push({ ...identity, count: line.count, quantity: "0" });
    arriving += toUnits(identity.content_quantity) * BigInt(line.count);
  }

  return { sealed, loose: fromUnits(loose), total: fromUnits(toUnits(destination.total) + arriving) };
}

/** The usable part of a balance (what can be issued, transferred or opened): the total less stock held for QA or rejected (ADR 0094). */
export function usableOf<T extends { total: string; usable_total?: string }>(balance: T): T {
  return balance.usable_total === undefined ? balance : { ...balance, total: balance.usable_total };
}
