import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUomsIndex } from '@erp/core-api';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DIRECTION_LABELS, EFFECT_OPTIONS, MEASURE_LABELS } from './variance-labels';

const NO_FLOOR = 'NONE';
const DECIMAL = /^\d{1,12}(\.\d{1,6})?$/;

const bandSchema = z
  .object({
    direction: z.enum(['OVER', 'UNDER']),
    measure: z.enum(['QUANTITY', 'PERCENT', 'COST_AMOUNT', 'COST_PERCENT']),
    threshold: z.string().refine((v) => DECIMAL.test(v), 'Enter a number of 0 or more'),
    uom_id: z.string(),
    effect: z.enum(['AUDIT_ONLY', 'REASON_REQUIRED', 'BLOCKED']),
  })
  .superRefine((band, ctx) => {
    if (band.measure === 'QUANTITY' && band.uom_id === '') {
      ctx.addIssue({ code: 'custom', path: ['uom_id'], message: 'Choose a unit for a quantity' });
    }
    const percent = band.measure === 'PERCENT' || band.measure === 'COST_PERCENT';
    if (percent && band.direction === 'UNDER' && DECIMAL.test(band.threshold) && Number(band.threshold) > 100) {
      ctx.addIssue({ code: 'custom', path: ['threshold'], message: 'An under-plan percentage cannot exceed 100' });
    }
  });

const schema = z.object({
  reason: z.string().trim().min(3, 'Say why this version is being set').max(1000),
  minimum_effect: z.enum([NO_FLOOR, 'AUDIT_ONLY', 'REASON_REQUIRED', 'BLOCKED']),
  bands: z.array(bandSchema).max(50),
});

export type VariancePolicyFormValues = z.infer<typeof schema>;

export const EMPTY_POLICY_VALUES: VariancePolicyFormValues = { reason: '', minimum_effect: NO_FLOOR, bands: [] };

const NEW_BAND: VariancePolicyFormValues['bands'][number] = { direction: 'OVER', measure: 'PERCENT', threshold: '10', uom_id: '', effect: 'REASON_REQUIRED' };

interface EditorProps {
  initial: VariancePolicyFormValues;
  submitLabel: string;
  isSubmitting: boolean;
  formError: string | null;
  onSubmit: (values: VariancePolicyFormValues) => void;
  onCancel: () => void;
}

/**
 * One draft rule set: why this version exists, an optional floor ("at least this much control for anything
 * that changes"), and the bands. Approval-required and waste bands are not offered: they need the shared
 * approval workflow and package-level stock tracking, neither of which exists yet (ADR 0054).
 */
export function VariancePolicyEditor({ initial, submitLabel, isSubmitting, formError, onSubmit, onCancel }: EditorProps) {
  const form = useForm<VariancePolicyFormValues>({ resolver: zodResolver(schema), defaultValues: initial });
  const bands = useFieldArray({ control: form.control, name: 'bands' });
  const uomsQuery = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomOptions = uoms.map((uom) => ({ value: String(uom.id), label: `${uom.name} (${uom.code})` }));

  return (
    <Form {...form}>
      <form className="space-y-4 rounded-md border p-3" onSubmit={form.handleSubmit(onSubmit)}>
        {formError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>}

        <FormField control={form.control} name="reason" render={({ field }) => (
          <FormItem><FormLabel>Why is this version being set?</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
        )} />

        <FormField control={form.control} name="minimum_effect" render={({ field }) => (
          <FormItem>
            <FormLabel>Minimum control for any change</FormLabel>
            <Select value={field.value} onValueChange={field.onChange}>
              <FormControl><SelectTrigger aria-label="Minimum control for any change"><SelectValue /></SelectTrigger></FormControl>
              <SelectContent>
                <SelectItem value={NO_FLOOR}>None — only the bands below decide</SelectItem>
                {EFFECT_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}
              </SelectContent>
            </Select>
            <FormMessage />
          </FormItem>
        )} />

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <h4 className="text-sm font-medium">Bands</h4>
            <Button type="button" size="sm" variant="outline" onClick={() => bands.append({ ...NEW_BAND })}>
              <Plus className="size-4" aria-hidden="true" /> Add a band
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            A band applies when the planned quantity is at or beyond its threshold; the strongest matching band wins. A change matching no band is just recorded.
          </p>
          {bands.fields.length === 0 && <p className="text-sm text-muted-foreground">No bands: every change is only recorded (or held to the minimum above).</p>}

          {bands.fields.map((row, index) => {
            const measure = form.watch(`bands.${index}.measure`);
            return (
              <div key={row.id} className="grid items-start gap-2 rounded-md bg-muted/40 p-2 sm:grid-cols-[1fr_1.4fr_0.8fr_1fr_1fr_auto]">
                <FormField control={form.control} name={`bands.${index}.direction`} render={({ field }) => (
                  <FormItem>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl><SelectTrigger aria-label={`Band ${index + 1} direction`}><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>{Object.entries(DIRECTION_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
                    </Select>
                  </FormItem>
                )} />
                <FormField control={form.control} name={`bands.${index}.measure`} render={({ field }) => (
                  <FormItem>
                    <Select value={field.value} onValueChange={(value) => { field.onChange(value); if (value !== 'QUANTITY') form.setValue(`bands.${index}.uom_id`, ''); }}>
                      <FormControl><SelectTrigger aria-label={`Band ${index + 1} measure`}><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>{Object.entries(MEASURE_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
                    </Select>
                  </FormItem>
                )} />
                <FormField control={form.control} name={`bands.${index}.threshold`} render={({ field }) => (
                  <FormItem><FormControl><Input inputMode="decimal" aria-label={`Band ${index + 1} threshold`} {...field} /></FormControl><FormMessage /></FormItem>
                )} />
                {measure === 'QUANTITY' ? (
                  <FormField control={form.control} name={`bands.${index}.uom_id`} render={({ field }) => (
                    <FormItem>
                      <FormControl>
                        <Combobox aria-label={`Band ${index + 1} unit`} value={field.value} onValueChange={field.onChange} options={uomOptions} placeholder="Unit" searchPlaceholder="Search units…" emptyText="No units found." />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )} />
                ) : (
                  <span aria-hidden="true" />
                )}
                <FormField control={form.control} name={`bands.${index}.effect`} render={({ field }) => (
                  <FormItem>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl><SelectTrigger aria-label={`Band ${index + 1} effect`}><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>{EFFECT_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectContent>
                    </Select>
                  </FormItem>
                )} />
                <Button type="button" size="icon" variant="ghost" aria-label={`Remove band ${index + 1}`} onClick={() => bands.remove(index)}>
                  <Trash2 className="size-4" aria-hidden="true" />
                </Button>
              </div>
            );
          })}
          <p className="text-xs text-muted-foreground">
            “Approval required” and waste bands will appear here once the approval workflow and package-level stock tracking exist.
          </p>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onCancel}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Saving…' : submitLabel}</Button>
        </div>
      </form>
    </Form>
  );
}

/** The API's shape for a form: numbers for ids, null for "no floor", no unit on a non-quantity band. */
export function toPolicyPayload(values: VariancePolicyFormValues) {
  return {
    reason: values.reason.trim(),
    minimum_effect: values.minimum_effect === NO_FLOOR ? null : values.minimum_effect,
    bands: values.bands.map((band) => ({
      direction: band.direction,
      measure: band.measure,
      threshold: band.threshold,
      uom_id: band.measure === 'QUANTITY' ? Number(band.uom_id) : null,
      effect: band.effect,
    })),
  };
}

/** A saved rule set back into the form (editing a draft, or starting a new version from the active one). */
export function toFormValues(policy: { reason: string; minimum_effect: string | null; bands?: Array<{ direction: string; measure: string; threshold: string; uom_id: number | null; effect: string }> }, keepReason: boolean): VariancePolicyFormValues {
  return {
    reason: keepReason ? policy.reason : '',
    minimum_effect: (policy.minimum_effect ?? NO_FLOOR) as VariancePolicyFormValues['minimum_effect'],
    bands: (policy.bands ?? []).map((band) => ({
      direction: band.direction as 'OVER' | 'UNDER',
      measure: band.measure as VariancePolicyFormValues['bands'][number]['measure'],
      threshold: band.threshold.includes('.') ? band.threshold.replace(/0+$/, '').replace(/\.$/, '') : band.threshold,
      uom_id: band.uom_id === null ? '' : String(band.uom_id),
      effect: band.effect as VariancePolicyFormValues['bands'][number]['effect'],
    })),
  };
}
