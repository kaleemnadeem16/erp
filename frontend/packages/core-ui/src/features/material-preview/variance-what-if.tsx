import type {
  AssembliesMaterialPreview200DataVarianceAnyOf,
  AssembliesMaterialPreview200DataVarianceAnyOfItemsItem,
  MaterialVarianceReasonResource,
  UomResource,
} from '@erp/core-api';
import { AlertTriangle, Check } from 'lucide-react';
import { Badge } from '../../components/ui/badge';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { DIRECTION_LABELS, EFFECT_LABELS, MEASURE_LABELS, REASON_STATUS_LABELS, trimZeros } from '../material-variance/variance-labels';

const NO_REASON = 'NO_REASON';

/** What someone has typed for one material; nothing is sent for a material whose quantity is blank. */
export interface WhatIfPlan {
  quantity: string;
  uomId: string;
  reasonId: string;
  note: string;
}

export const EMPTY_PLAN: WhatIfPlan = { quantity: '', uomId: '', reasonId: NO_REASON, note: '' };

interface WhatIfEditorProps {
  itemCode: string;
  standardUomId: number;
  plan: WhatIfPlan | undefined;
  onChange: (plan: WhatIfPlan) => void;
  uoms: UomResource[];
  reasons: MaterialVarianceReasonResource[];
}

/**
 * The "what if we plan a different amount" inputs for one material (ADR 0058). Nothing typed here is
 * saved; it only changes the question the next preview answers.
 */
export function WhatIfEditor({ itemCode, standardUomId, plan, onChange, uoms, reasons }: WhatIfEditorProps) {
  const value = plan ?? EMPTY_PLAN;
  const set = (patch: Partial<WhatIfPlan>) => onChange({ ...value, ...patch });

  return (
    <div className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4">
      <label className="space-y-1 text-sm">
        <span className="font-medium">Planned quantity (optional)</span>
        <Input
          value={value.quantity}
          onChange={(event) => set({ quantity: event.target.value })}
          inputMode="decimal"
          placeholder="Same as the standard"
          aria-label={`Planned quantity of ${itemCode}`}
        />
      </label>
      <div className="space-y-1 text-sm">
        <span className="font-medium">Planned unit</span>
        <Combobox
          aria-label={`Planned unit of ${itemCode}`}
          value={value.uomId || String(standardUomId)}
          onValueChange={(uomId) => set({ uomId })}
          options={uoms.map((row) => ({ value: String(row.id), label: `${row.name} (${row.code})` }))}
          searchPlaceholder="Search units…"
          emptyText="No units found."
        />
      </div>
      <div className="space-y-1 text-sm">
        <span className="font-medium">Reason</span>
        <Select value={value.reasonId} onValueChange={(reasonId) => set({ reasonId })}>
          <SelectTrigger aria-label={`Reason for the plan of ${itemCode}`}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={NO_REASON}>No reason</SelectItem>
            {reasons.map((reason) => (
              <SelectItem key={reason.id} value={String(reason.id)}>{reason.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <label className="space-y-1 text-sm">
        <span className="font-medium">Note</span>
        <Input
          value={value.note}
          onChange={(event) => set({ note: event.target.value })}
          aria-label={`Note for the plan of ${itemCode}`}
        />
      </label>
    </div>
  );
}

/** The whole-plan headline: can this plan be adopted as it stands, and if not, why not. */
export function VarianceSummary({ variance }: { variance: AssembliesMaterialPreview200DataVarianceAnyOf }) {
  const { summary } = variance;
  return (
    <div
      className={`space-y-1 rounded-md border p-3 text-sm ${summary.can_adopt ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/10'}`}
      role="status"
    >
      <p className="font-medium">
        {summary.can_adopt ? <Check className="mr-1 inline size-4" aria-hidden="true" /> : <AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />}
        {summary.can_adopt ? 'This plan can be adopted as it stands.' : 'This plan cannot be adopted yet.'}
      </p>
      {summary.needs_reason_count > 0 && (
        <p>{summary.needs_reason_count} {summary.needs_reason_count === 1 ? 'material needs' : 'materials need'} a reason.</p>
      )}
      {summary.blocked_count > 0 && (
        <p>{summary.blocked_count} {summary.blocked_count === 1 ? 'material is' : 'materials are'} blocked by policy.</p>
      )}
      {variance.limitations.map((limitation) => <p key={limitation} className="text-muted-foreground">{limitation}</p>)}
    </div>
  );
}

/** The verdict for one material: the standard beside the plan, the effect, and the conditions behind it. */
export function VarianceVerdict({ item, mayViewCost }: { item: AssembliesMaterialPreview200DataVarianceAnyOfItemsItem; mayViewCost: boolean }) {
  const unit = item.uom.code;
  const changed = item.direction !== null;
  const tone = item.can_adopt ? 'success' : 'warning';

  return (
    <div className="mt-3 space-y-2 rounded-md bg-muted/50 p-3 text-sm" role="group" aria-label={`Variance verdict for ${item.item.code}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p>
          Standard <span className="font-medium">{trimZeros(item.standard_quantity)} {unit}</span>
          {' · '}
          Planned <span className="font-medium">{trimZeros(item.planned_quantity)} {unit}</span>
          {item.entered.uom_code !== unit && (
            <span className="text-muted-foreground"> (entered as {trimZeros(item.entered.quantity)} {item.entered.uom_code})</span>
          )}
          {changed && (
            <span className="text-muted-foreground">
              {' · '}{DIRECTION_LABELS[item.direction ?? ''] ?? item.direction} by {trimZeros(item.delta_quantity.replace('-', ''))} {unit}
              {item.delta_percent !== null ? ` (${trimZeros(item.delta_percent.replace('-', ''))}%)` : ''}
            </span>
          )}
        </p>
        <div className="flex flex-wrap gap-2">
          <Badge variant={tone}>{item.effect_label ?? 'No control applies'}</Badge>
          <Badge variant="outline">{REASON_STATUS_LABELS[item.reason_status] ?? item.reason_status}</Badge>
        </div>
      </div>

      {item.reason && <p>Reason: <span className="font-medium">{item.reason.name}</span>{item.note ? ` — ${item.note}` : ''}</p>}
      {!item.reason && item.note && <p>Note: {item.note}</p>}

      {item.reason_status === 'MISSING' && <p className="text-amber-700 dark:text-amber-400">Choose a reason for this difference, then calculate again.</p>}
      {item.reason_status === 'NOT_ACCEPTED' && <p className="text-amber-700 dark:text-amber-400">Policy blocks this difference; a reason does not lift it. Change the planned quantity.</p>}

      {item.matched.length > 0 && (
        <ul className="space-y-1">
          {item.matched.map((condition, index) => (
            <li key={`${condition.band_id ?? 'floor'}-${index}`} className="text-muted-foreground">
              {conditionSentence(condition, mayViewCost)}
            </li>
          ))}
        </ul>
      )}
      {item.skipped.length > 0 && (
        <ul className="space-y-1">
          {item.skipped.map((skipped) => (
            <li key={skipped.band_id} className="text-muted-foreground">Not judged: {skipped.reason}</li>
          ))}
        </ul>
      )}
      {item.limitations.map((limitation) => <p key={limitation} className="text-muted-foreground">{limitation}</p>)}
    </div>
  );
}

function conditionSentence(
  condition: AssembliesMaterialPreview200DataVarianceAnyOfItemsItem['matched'][number],
  mayViewCost: boolean,
): string {
  const effect = EFFECT_LABELS[condition.effect] ?? condition.effect;
  if (condition.band_id === null || condition.measure === null || condition.direction === null || condition.threshold === null) {
    return `Company rule: ${effect}.`;
  }
  const measure = MEASURE_LABELS[condition.measure] ?? condition.measure;
  const direction = (DIRECTION_LABELS[condition.direction] ?? condition.direction).toLowerCase();
  // Cost observations arrive null without the cost capability: hidden, not zero.
  const observed = condition.observed !== null && (mayViewCost || !condition.measure.startsWith('COST'))
    ? ` (this plan: ${trimZeros(condition.observed)}${condition.unit ? ` ${condition.unit}` : ''})`
    : '';

  return `${measure}, ${direction} ${trimZeros(condition.threshold)}${condition.unit ? ` ${condition.unit}` : ''} or more${observed} → ${effect}`;
}
