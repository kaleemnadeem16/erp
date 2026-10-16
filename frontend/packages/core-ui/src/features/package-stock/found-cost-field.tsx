import { useStockLayersIndex } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { DecimalInput } from "../../components/ui/decimal-input";
import { formatDate } from "../../lib/format-date";
import { decimalForEdit, formatDecimal } from "../../lib/decimal";

/**
 * The unit cost of stock found in a count. Never inferred: the most recent receipt's cost is offered with its source and date, and the reviewer
 * must choose it (or type another) before the count can post. Shown only to people who may see costs.
 */
export function FoundCostField({ itemId, value, onChange, unit }: { itemId: number; value: string; onChange: (value: string) => void; unit?: string }) {
  const layers = useStockLayersIndex({ "filter[item_id]": itemId, sort: "-received_at", per_page: 1 }, { query: { retry: false } });
  const response = layers.data as unknown as { status: number; data: { data: Array<{ unit_cost?: string; received_at: string; source_type: string }> } } | undefined;
  const latest = response?.status === 200 ? response.data.data[0] : undefined;
  const suggestion = latest?.unit_cost;

  return (
    <div className="flex flex-col items-end gap-1">
      <DecimalInput rule={{ spec: "money", sign: "nonNegative" }} className="h-8 w-24" value={value} onChange={onChange} aria-label="Found unit cost" data-testid="found-cost" />
      {suggestion !== undefined && value === "" && (
        <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => onChange(decimalForEdit(suggestion))} data-testid="found-cost-suggestion">
          Use {formatDecimal(suggestion)}{unit ? ` per ${unit}` : ""} — last receipt, {formatDate(latest?.received_at)}
        </Button>
      )}
    </div>
  );
}
