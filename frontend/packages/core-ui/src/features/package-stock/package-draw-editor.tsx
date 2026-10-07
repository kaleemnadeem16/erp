import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import type { DrawState } from "./draw-state";
import { packageLabel, trimQty } from "./quantity";
import type { SealedRow } from "./types";

export function Stepper({
  value,
  max,
  onChange,
  label,
}: {
  value: number;
  max: number;
  onChange: (value: number) => void;
  label: string;
}) {
  return (
    <div className="flex items-center gap-1" role="group" aria-label={label}>
      <Button
        type="button"
        variant="outline"
        size="sm"
        aria-label={`Fewer ${label}`}
        disabled={value <= 0}
        onClick={() => onChange(value - 1)}
      >
        −
      </Button>
      <span
        className="min-w-8 text-center tabular-nums"
        data-testid="stepper-value"
      >
        {value}
      </span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        aria-label={`More ${label}`}
        disabled={value >= max}
        onClick={() => onChange(value + 1)}
      >
        +
      </Button>
    </div>
  );
}

/**
 * Edits what is physically taken: whole sealed packages, loose litres, and packages opened for part of their contents. Opening is just another row of
 * the same draw — never a separate step. The quantity of an opened package is what is taken from it; the rest stays loose.
 */
export function PackageDrawEditor({
  sealed,
  loose,
  unit,
  value,
  onChange,
  canOpen,
}: {
  sealed: SealedRow[];
  loose: string;
  unit?: string;
  value: DrawState;
  onChange: (value: DrawState) => void;
  canOpen: boolean;
}) {
  const u = unit ? ` ${unit}` : "";

  return (
    <div className="space-y-3" data-testid="package-draw-editor">
      <div className="grid items-center gap-2 sm:grid-cols-[1fr_auto]">
        <label htmlFor="draw-loose" className="text-sm">
          Loose to take{" "}
          <span className="text-muted-foreground">
            (of {trimQty(loose)}
            {u} loose)
          </span>
        </label>
        <Input
          id="draw-loose"
          inputMode="decimal"
          className="w-32 text-right"
          value={value.loose}
          onChange={(event) =>
            onChange({ ...value, loose: event.target.value })
          }
          placeholder="0"
        />
      </div>
      {sealed
        .filter((row) => row.availability === "USABLE")
        .map((row) => {
          const open = value.open[row.item_packaging_id] ?? {
            count: 0,
            quantity: "",
          };
          const whole = value.whole[row.item_packaging_id] ?? 0;
          return (
            <div
              key={row.item_packaging_id}
              className="space-y-2 rounded-md border p-2"
              data-testid="draw-level"
            >
              <div className="grid items-center gap-2 sm:grid-cols-[1fr_auto]">
                <span className="text-sm">
                  Whole {packageLabel(row)}{" "}
                  <span className="text-muted-foreground">
                    ({row.count} sealed)
                  </span>
                </span>
                <Stepper
                  label={`whole ${packageLabel(row)}`}
                  value={whole}
                  max={Math.max(0, row.count - open.count)}
                  onChange={(count) =>
                    onChange({
                      ...value,
                      whole: { ...value.whole, [row.item_packaging_id]: count },
                    })
                  }
                />
              </div>
              {canOpen && (
                <div className="grid items-center gap-2 sm:grid-cols-[1fr_auto_auto]">
                  <span className="text-sm">
                    Open a {packageLabel(row)} and take only part
                  </span>
                  <Stepper
                    label={`opened ${packageLabel(row)}`}
                    value={open.count}
                    max={Math.max(0, row.count - whole)}
                    onChange={(count) =>
                      onChange({
                        ...value,
                        open: {
                          ...value.open,
                          [row.item_packaging_id]: { ...open, count },
                        },
                      })
                    }
                  />
                  <Input
                    aria-label={`Litres taken from opened ${packageLabel(row)}`}
                    inputMode="decimal"
                    className="w-24 text-right"
                    value={open.quantity}
                    disabled={open.count === 0}
                    placeholder="litres"
                    onChange={(event) =>
                      onChange({
                        ...value,
                        open: {
                          ...value.open,
                          [row.item_packaging_id]: {
                            ...open,
                            quantity: event.target.value,
                          },
                        },
                      })
                    }
                  />
                </div>
              )}
            </div>
          );
        })}
    </div>
  );
}
