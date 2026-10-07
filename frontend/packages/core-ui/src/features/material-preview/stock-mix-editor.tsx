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
import { Textarea } from '../../components/ui/textarea';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';

/** What someone has typed for one material's hand-built mix: a quantity per location/batch, all in one unit. */
export interface MixDraft {
  enabled: boolean;
  uomId: string;
  quantities: Record<string, string>;
  /** Why this mix was chosen, where the company's control asks for a reason (ADR 0076). */
  note: string;
}

export const EMPTY_MIX: MixDraft = { enabled: false, uomId: '', quantities: {}, note: '' };

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
  /** Whether the caller holds `estimating.stock_mix.choose`; without it the control is not offered. */
  mayChoose: boolean;
  onCalculate?: () => void;
  isCalculating?: boolean;
}

/**
 * Lets someone choose the stock themselves instead of taking a preset's proposal (ADR 0072). What they type is a
 * quantity per location/batch; the server validates it against the real usable balances, prices it with the same cost
 * evidence as a proposal and answers below. Any figure shown before that answer is guidance only. Nothing is reserved.
 */
export function StockMixEditor({ item, draft, onChange, uoms, unitsLookup, mayViewCost, mayChoose, onCalculate, isCalculating }: StockMixEditorProps) {
  const value = draft ?? EMPTY_MIX;
  const set = (patch: Partial<MixDraft>) => onChange({ ...value, ...patch });
  const stockUnit = item.uom.code;
  const uomId = value.uomId || String(item.uom.id);
  const usable = item.balances.filter((balance) => Number(balance.usable_quantity) > 0);
  const mix = item.selected_mix;
  const control = item.mix_control;
  const levelCode = String(control.level.code);
  const threshold = control.threshold_percent === null ? null : trimNumber(String(control.threshold_percent));

  if (levelCode === 'NOT_ALLOWED') {
    return <p className="border-t pt-3 text-sm text-muted-foreground">Your company asks you to follow the proposal for this material, so choosing stock by hand is not offered.</p>;
  }
  if (!mayChoose) {
    return <p className="border-t pt-3 text-sm text-muted-foreground">Choosing stock by hand needs the <code>estimating.stock_mix.choose</code> capability, which your access does not include.</p>;
  }

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
        <Button type="button" size="sm" variant="ghost" onClick={() => onChange({ enabled: false, uomId: '', quantities: {}, note: '' })}>
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

      {(levelCode === 'REASON_REQUIRED' || levelCode === 'APPROVAL_REQUIRED') && threshold !== null && (
        <label className="block space-y-1 text-sm">
          <span className="font-medium">Reason for choosing this mix</span>
          <Textarea
            value={value.note}
            onChange={(event) => set({ note: event.target.value })}
            rows={2}
            aria-label={`Reason for the mix of ${item.item.code}`}
            placeholder={`Needed if the mix costs ${threshold}% or more above the proposal`}
          />
        </label>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
        <p className="text-muted-foreground">
          {inStockUnit
            ? `You have chosen ${qty(String(chosen))} of the ${qty(item.required_quantity)} ${stockUnit} needed${chosen > required ? ' — more than the plan needs; the server will refuse it.' : '.'}`
            : 'The total is checked, and converted to the stock unit, when you calculate.'}
        </p>
        {onCalculate && (
          <Button type="button" variant="outline" size="sm" onClick={onCalculate} disabled={isCalculating}>
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
          <MixVerdict control={mix.control} mayViewCost={mayViewCost} />
          {mix.limitations.map((limitation) => <p key={limitation} className="text-muted-foreground">{limitation}</p>)}
        </div>
      )}
    </div>
  );
}

type Control = NonNullable<Item['selected_mix']>['control'];

const VERDICT_TEXT: Record<string, string> = {
  WITHIN_LIMIT: 'Within your company’s limit.',
  NOT_JUDGED: 'Could not be compared with the proposal’s cost, so the company’s limit was not applied.',
  REASON_MISSING: 'This mix costs more than your company’s limit allows without a reason. Give one above, then calculate again.',
  REASON_PROVIDED: 'Costs more than the limit, and a reason has been given.',
  APPROVAL_NEEDED: 'Costs more than the limit: this mix would need approval before the plan could be adopted.',
  BLOCKED: 'Costs more than the limit and needs approval, but approval is not switched on, so it is blocked.',
};

/** What the company's control says about this mix (ADR 0076), and the approval it would need. A preview commits nothing. */
function MixVerdict({ control, mayViewCost }: { control: Control; mayViewCost: boolean }) {
  const status = String(control.status);
  if (status === 'NOT_CONTROLLED') return null;
  const ok = status === 'WITHIN_LIMIT' || status === 'REASON_PROVIDED';
  const steps = (control.approval?.steps ?? []) as Array<{ name: string; stage: number; required_approvals: number; uses_authority_limit: boolean }>;

  return (
    <div className={`space-y-1 rounded-md border p-2 ${ok ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/10'}`} role="group" aria-label="Company control on this mix">
      <p className="font-medium">
        {ok ? <Check className="mr-1 inline size-4" aria-hidden="true" /> : <AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />}
        {VERDICT_TEXT[status] ?? status}
      </p>
      <p className="text-muted-foreground">
        Company rule: {String(control.level.label).toLowerCase()}{control.threshold_percent !== null ? ` — ${qty(String(control.threshold_percent))}%` : ''}
        {mayViewCost && control.observed_percent !== null ? `. This mix: ${qty(String(control.observed_percent))}% above the proposal per unit` : ''}
        {mayViewCost && control.extra_cost !== null ? ` (${qty(String(control.extra_cost))} extra)` : ''}.
      </p>
      {control.note && <p>Reason given: <span className="font-medium">{String(control.note)}</span></p>}
      {control.approval && (
        <div>
          <p className="font-medium">{control.approval.policy ? `Needs approval — ${String(control.approval.policy.name)}` : 'Needs approval'}</p>
          {steps.length > 0 && (
            <ol className="list-decimal pl-5 text-muted-foreground">
              {steps.map((step) => <li key={`${step.stage}-${step.name}`}>{step.name} — {step.required_approvals} {step.required_approvals === 1 ? 'approval' : 'approvals'}</li>)}
            </ol>
          )}
          {control.approval.note && <p className="text-muted-foreground">{String(control.approval.note)}</p>}
          <p className="text-muted-foreground">A mix is only hypothetical here; it is submitted for approval when its plan is adopted.</p>
        </div>
      )}
      {control.limitations.map((limitation) => <p key={String(limitation)} className="text-muted-foreground">{String(limitation)}</p>)}
    </div>
  );
}

function trimNumber(value: string): string {
  return qty(value);
}

function qty(value: string): string {
  const trimmed = value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
  return trimmed === '' ? '0' : trimmed;
}
