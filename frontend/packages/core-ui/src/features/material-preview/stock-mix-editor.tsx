import type {
  AssembliesMaterialPreview200DataInventoryItemsItem,
  UomResource,
} from '@erp/core-api';
import { AlertTriangle, Check } from 'lucide-react';
import { type LookupState } from '../../lib/api-errors';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';

/** What someone has typed for one material's hand-built mix: a quantity per location/batch, all in one unit. */
export interface MixDraft {
  enabled: boolean;
  uomId: string;
  quantities: Record<string, string>;
}

export const EMPTY_MIX: MixDraft = { enabled: false, uomId: '', quantities: {} };

/** The identity of one stock source in a mix: a location and, for a batch-tracked item, a batch. */
export function mixKey(locationId: number, batchId: number | null | undefined): string {
  return `${locationId}:${batchId ?? 0}`;
}

type Item = AssembliesMaterialPreview200DataInventoryItemsItem;

interface StockMixEditorProps {
  item: Item;
  draft: MixDraft | undefined;
  onChange: (draft: MixDraft) => void;
  uoms: UomResource[];
  unitsLookup: LookupState;
  mayViewCost: boolean;
  onCalculate?: () => void;
  isCalculating?: boolean;
}

/**
 * Lets someone choose the stock themselves instead of taking a preset's proposal (ADR 0072). What they type is a
 * quantity per location/batch; the server validates it against the real usable balances, prices it with the same cost
 * evidence as a proposal and answers below. Any figure shown before that answer is guidance only. Nothing is reserved.
 */
export function StockMixEditor({ item, draft, onChange, uoms, unitsLookup, mayViewCost, onCalculate, isCalculating }: StockMixEditorProps) {
  const value = draft ?? EMPTY_MIX;
  const set = (patch: Partial<MixDraft>) => onChange({ ...value, ...patch });
  const stockUnit = item.uom.code;
  const uomId = value.uomId || String(item.uom.id);
  const usable = item.balances.filter((balance) => Number(balance.usable_quantity) > 0);
  const mix = item.selected_mix;

  if (!value.enabled) {
    return (
      <div className="border-t pt-3">
        <Button type="button" size="sm" variant="outline" onClick={() => set({ enabled: true, uomId: value.uomId || String(item.uom.id) })}>
          Choose the stock yourself
        </Button>
        <p className="mt-1 text-xs text-muted-foreground">Pick how much to take from each location instead of using a preset's proposal.</p>
      </div>
    );
  }

  // Guidance only, and only when the quantities are in the stock unit (no conversion is guessed here).
  const inStockUnit = Number(uomId) === item.uom.id;
  const chosen = usable.reduce((sum, balance) => sum + (Number(value.quantities[mixKey(balance.location.id, balance.batch?.id)]) || 0), 0);
  const required = Number(item.required_quantity);

  return (
    <div className="space-y-3 border-t pt-3" role="group" aria-label={`Your own mix for ${item.item.code}`}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h3 className="text-sm font-medium">Your own mix</h3>
          <p className="text-xs text-muted-foreground">
            Type how much to take from each place, in any unit that converts to {stockUnit}. A part of a pack is just a smaller quantity.
            The total can be less than the requirement (the rest stays uncovered), but never more: to use more than planned, change the planned quantity.
          </p>
        </div>
        <Button type="button" size="sm" variant="ghost" onClick={() => onChange({ enabled: false, uomId: '', quantities: {} })}>
          Use the proposal instead
        </Button>
      </div>

      <div className="max-w-sm space-y-1 text-sm">
        <span className="font-medium">Unit you are typing in</span>
        <Combobox
          {...unitsLookup}
          aria-label={`Unit for your mix of ${item.item.code}`}
          value={uomId}
          onValueChange={(next) => set({ uomId: next })}
          options={uoms.map((row) => ({ value: String(row.id), label: `${row.name} (${row.code})` }))}
          searchPlaceholder="Search units…"
          emptyText="No units found."
        />
      </div>

      {usable.length === 0 ? (
        <p className="text-sm text-muted-foreground">There is no usable stock of this material to choose from.</p>
      ) : (
        <Table>
          <TableCaption className="sr-only">Usable stock of {item.item.code} and how much to take from each place</TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead>Where</TableHead>
              <TableHead className="text-right">Usable ({stockUnit})</TableHead>
              <TableHead className="text-right">Take</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {usable.map((balance) => {
              const key = mixKey(balance.location.id, balance.batch?.id);
              const label = `${balance.warehouse.name} / ${balance.location.name}${balance.batch ? ` · batch ${balance.batch.code}` : ''}`;
              return (
                <TableRow key={key}>
                  <TableCell>{label}</TableCell>
                  <TableCell className="text-right">{qty(balance.usable_quantity)}</TableCell>
                  <TableCell className="text-right">
                    <Input
                      className="ml-auto w-28 text-right"
                      inputMode="decimal"
                      value={value.quantities[key] ?? ''}
                      onChange={(event) => set({ quantities: { ...value.quantities, [key]: event.target.value } })}
                      aria-label={`Take from ${label}`}
                      placeholder="0"
                    />
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p className="text-muted-foreground">
          {inStockUnit
            ? `You have chosen ${qty(String(chosen))} of the ${qty(item.required_quantity)} ${stockUnit} needed${chosen > required ? ' — more than the plan needs; the server will refuse it.' : '.'}`
            : 'The total is checked, and converted to the stock unit, when you calculate.'}
        </p>
        {onCalculate && (
          <Button type="button" size="sm" onClick={onCalculate} disabled={isCalculating}>
            {isCalculating ? 'Calculating…' : 'Calculate with my mix'}
          </Button>
        )}
      </div>

      {mix && (
        <div className="space-y-3 rounded-md bg-muted/50 p-3 text-sm" role="status">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="font-medium">
              {mix.is_complete ? <Check className="mr-1 inline size-4" aria-hidden="true" /> : <AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />}
              Your mix covers {qty(mix.covered_quantity)} of {qty(mix.required_quantity)} {stockUnit}
              {mix.is_complete ? '' : `, leaving ${qty(mix.shortage_quantity)} ${stockUnit} uncovered`}
            </p>
            <div className="flex flex-wrap gap-2">
              {mix.same_as_recommendation && <Badge variant="secondary">Same as the proposal</Badge>}
              <Badge variant="outline">{mix.pick_count} {mix.pick_count === 1 ? 'place' : 'places'}</Badge>
            </div>
          </div>

          <Table>
            <TableCaption className="sr-only">Where each quantity of your mix comes from</TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead>Where</TableHead>
                <TableHead className="text-right">You typed</TableHead>
                <TableHead className="text-right">Taken ({stockUnit})</TableHead>
                <TableHead className="text-right">Left there</TableHead>
                {mayViewCost && <TableHead className="text-right">Unit cost</TableHead>}
                {mayViewCost && <TableHead className="text-right">Cost</TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {mix.lines.map((line) => (
                <TableRow key={mixKey(line.location.id, line.batch?.id)}>
                  <TableCell>{line.warehouse.name} / {line.location.name}{line.batch ? ` · batch ${line.batch.code}` : ''}</TableCell>
                  <TableCell className="text-right">
                    {qty(line.entered_quantity)} {line.entered_uom.code}
                    {line.entered_uom.code !== stockUnit && (
                      <span className="block text-xs text-muted-foreground">1 {line.entered_uom.code} = {qty(line.stock_unit_factor)} {stockUnit}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right">{qty(line.quantity)}</TableCell>
                  <TableCell className="text-right">{qty(line.remaining_after_quantity)}</TableCell>
                  {mayViewCost && <TableCell className="text-right">{line.unit_cost === null ? '—' : qty(line.unit_cost)}</TableCell>}
                  {mayViewCost && <TableCell className="text-right">{line.line_cost === null ? '—' : qty(line.line_cost)}</TableCell>}
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {mayViewCost && (
            <p>
              Estimated internal cost{' '}
              <span className="font-medium">{mix.estimated_cost === null ? 'unavailable — some stock has no cost evidence' : qty(mix.estimated_cost)}</span>
              {mix.cost_difference !== null && mix.recommendation_estimated_cost !== null && (
                <span className="text-muted-foreground">
                  {' · '}the proposal costs {qty(mix.recommendation_estimated_cost)}; yours is{' '}
                  {Number(mix.cost_difference) === 0 ? 'the same' : `${Number(mix.cost_difference) > 0 ? '' : '−'}${qty(mix.cost_difference.replace('-', ''))} ${Number(mix.cost_difference) > 0 ? 'more' : 'less'}`}
                  {mix.cost_difference_percent !== null && Number(mix.cost_difference) !== 0 ? ` (${qty(mix.cost_difference_percent.replace('-', ''))}%)` : ''}
                </span>
              )}
            </p>
          )}
          {mix.limitations.map((limitation) => <p key={limitation} className="text-muted-foreground">{limitation}</p>)}
        </div>
      )}
    </div>
  );
}

function qty(value: string): string {
  const trimmed = value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
  return trimmed === '' ? '0' : trimmed;
}
