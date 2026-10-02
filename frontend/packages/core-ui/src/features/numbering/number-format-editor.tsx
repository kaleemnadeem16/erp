import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useNumberingPreview, useNumberingSeriesVersionsStore } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { AdvancedOptionSection } from '../../components/ui/advanced-option-section';
import type { AdvancedOption } from '../../components/ui/advanced-options-menu';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { Input } from '../../components/ui/input';
import { OptionCards } from '../../components/ui/option-cards';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { CountersSection, HistorySection, SeriesSection } from './numbering-advanced';
import { useNumberingInfo } from './numbering-api';
import { NumberingTester } from './numbering-tester';
import {
  CONTINUITY_LABELS,
  PERIOD_OPTIONS,
  type ContinuityCode,
  type NumberingDocumentType,
  type NumberingPreviewResult,
  type NumberingSeries,
  type ScopePeriodCode,
} from './numbering-types';

const schema = z.object({
  template: z.string().trim().min(1, 'Enter a format, for example PO-{YYYY}-{SEQ:5}').max(255),
  scope_period: z.enum(['NONE', 'YEAR', 'MONTH', 'DAY', 'FISCAL_YEAR', 'FISCAL_PERIOD']),
  dimensions: z.array(z.string()),
  continuity: z.enum(['GAPLESS', 'ALLOW_GAPS']),
  reason: z.string().trim().min(1, 'Say why the numbering is changing').max(1000),
});
type FormValues = z.infer<typeof schema>;

const PREVIEW_DELAY_MS = 350;

/**
 * Changes one document type's numbering (ADR 0079). The simple view is the format, how often it restarts, whether gaps are allowed,
 * and a live tester; counter-scope dimensions, series and routing, counters and history sit behind "Advanced options".
 * Every change needs a reason and applies only to numbers issued from then on.
 */
export function NumberFormatEditor({
  type,
  onAdvancedOptionsChange,
}: {
  type: NumberingDocumentType;
  onAdvancedOptionsChange: (options: AdvancedOption[]) => void;
}) {
  const [seriesId, setSeriesId] = useState(() => (type.series.find((series) => series.is_default) ?? type.series[0])?.id ?? 0);
  const found = type.series.find((candidate) => candidate.id === seriesId) ?? type.series[0];
  if (found === undefined) {
    // A type always has its default series once the overview has loaded it; this is only a guard.
    return <p className="text-sm text-destructive">This document type has no numbering series yet. Reload the page.</p>;
  }
  return <SeriesEditor type={type} series={found} seriesId={seriesId} setSeriesId={setSeriesId} onAdvancedOptionsChange={onAdvancedOptionsChange} />;
}

function SeriesEditor({
  type,
  series,
  seriesId,
  setSeriesId,
  onAdvancedOptionsChange,
}: {
  type: NumberingDocumentType;
  series: NumberingSeries;
  seriesId: number;
  setSeriesId: (id: number) => void;
  onAdvancedOptionsChange: (options: AdvancedOption[]) => void;
}) {
  const { info } = useNumberingInfo();
  const version = series.version;
  const canManage = type.can_manage;
  const mayRaise = usePermission('numbering.counter.raise');

  const [showDimensions, setShowDimensions] = useState(false);
  const [showSeries, setShowSeries] = useState(false);
  const [showCounters, setShowCounters] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [saved, setSaved] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const defaults = useMemo<FormValues>(
    () => ({
      template: version?.template ?? type.default_template,
      scope_period: version?.scope_period ?? type.default_scope_period,
      dimensions: version?.scope_dimensions ?? [],
      continuity: version?.continuity ?? type.default_continuity,
      reason: '',
    }),
    [version, type],
  );
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults });
  useEffect(() => {
    form.reset(defaults);
  }, [defaults, form]);
  // "Saved." stays until the person edits again (the reset above follows the refreshed series and must not clear it).
  useEffect(() => {
    const subscription = form.watch((_values, { type }) => type === 'change' && setSaved(false));
    return () => subscription.unsubscribe();
  }, [form]);
  useEffect(() => setSaved(false), [seriesId]);

  const [template, period, dimensions, continuity] = form.watch(['template', 'scope_period', 'dimensions', 'continuity']);
  const unchanged =
    version !== null &&
    template === version.template &&
    period === version.scope_period &&
    continuity === version.continuity &&
    [...dimensions].sort().join() === [...version.scope_dimensions].sort().join();

  // The tester asks the server once typing pauses; only the newest answer is shown.
  const preview = useNumberingPreview();
  const [result, setResult] = useState<NumberingPreviewResult | null>(null);
  const [previewFailed, setPreviewFailed] = useState(false);
  const latest = useRef(0);
  const previewKey = JSON.stringify([type.key, series.code, template, period, dimensions, continuity]);
  useEffect(() => {
    const id = ++latest.current;
    const handle = window.setTimeout(() => {
      preview.mutate(
        { data: { document_type: type.key, template, scope_period: period, scope_dimensions: dimensions, continuity, series_code: series.code } },
        {
          onSuccess: (response) => {
            if (id !== latest.current) return;
            setPreviewFailed(response.status !== 200);
            if (response.status === 200) setResult(response.data.data as unknown as NumberingPreviewResult);
          },
          onError: () => id === latest.current && setPreviewFailed(true),
        },
      );
    }, PREVIEW_DELAY_MS);
    return () => window.clearTimeout(handle);
    // `preview.mutate` is stable; the key captures every input of the request.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewKey]);

  const templateInput = useRef<HTMLInputElement | null>(null);
  function insert(token: string) {
    const input = templateInput.current;
    const current = form.getValues('template');
    const start = input?.selectionStart ?? current.length;
    const end = input?.selectionEnd ?? current.length;
    form.setValue('template', current.slice(0, start) + token + current.slice(end), { shouldDirty: true, shouldValidate: true });
    window.requestAnimationFrame(() => {
      input?.focus();
      input?.setSelectionRange(start + token.length, start + token.length);
    });
  }

  const save = useNumberingSeriesVersionsStore();
  function submit(values: FormValues) {
    setFormError(null);
    setSaved(false);
    save.mutate(
      { series: series.id, data: { template: values.template.trim(), scope_period: values.scope_period, scope_dimensions: values.dimensions, continuity: values.continuity, reason: values.reason.trim() } },
      { onSuccess: () => setSaved(true), onError: (error) => setFormError(getApiErrorMessage(error) ?? 'The change could not be saved.') },
    );
  }

  useEffect(() => {
    onAdvancedOptionsChange([
      ...(type.dimensions.length > 0 ? [{ id: 'dimensions', label: 'Count separately by…', checked: showDimensions, onCheckedChange: setShowDimensions }] : []),
      { id: 'series', label: 'Series and routing', checked: showSeries, onCheckedChange: setShowSeries },
      { id: 'counters', label: 'Counters', checked: showCounters, onCheckedChange: setShowCounters },
      { id: 'history', label: 'History', checked: showHistory, onCheckedChange: setShowHistory },
    ]);
  }, [type.dimensions.length, showDimensions, showSeries, showCounters, showHistory, onAdvancedOptionsChange]);

  const variableChips = [
    ...(info?.engine_variables ?? []).map((variable) => ({ token: `{${variable.key}}`, label: variable.label, hint: variable.description })),
    ...type.variables.map((variable) => ({ token: `{${variable.key}}`, label: variable.label, hint: `Supplied by the ${type.label.toLowerCase()} itself.` })),
    ...(info?.constants ?? []).map((constant) => ({ token: `{${constant.code}}`, label: `${constant.code} = ${constant.value}`, hint: 'A company constant.' })),
  ];

  return (
    <div className="space-y-4">
      {!canManage && (
        <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm" role="note">
          You can see how {type.label.toLowerCase()} numbers are built but not change them. Changing them needs <code>{type.manage_permission}</code>.
        </p>
      )}
      {type.series.length > 1 && (
        <div className="max-w-xs space-y-1">
          <label htmlFor="series-picker" className="text-sm font-medium">Series</label>
          <Select value={String(seriesId)} onValueChange={(value) => setSeriesId(Number(value))}>
            <SelectTrigger id="series-picker" aria-label="Series"><SelectValue /></SelectTrigger>
            <SelectContent>{type.series.map((candidate) => <SelectItem key={candidate.id} value={String(candidate.id)}>{candidate.name}{candidate.is_default ? ' (default)' : ''}</SelectItem>)}</SelectContent>
          </Select>
        </div>
      )}

      <Form {...form}>
        <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
          <DetailSection title="Format" description={`How ${type.label.toLowerCase()} numbers look. They are taken ${type.official_when}.`} fullWidth>
            <div className="space-y-3 sm:col-span-2">
              <FormField
                control={form.control}
                name="template"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Number format</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        ref={(element) => { field.ref(element); templateInput.current = element; }}
                        className="font-mono"
                        disabled={!canManage}
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="PO-{YYYY}-{SEQ:5}"
                      />
                    </FormControl>
                    <FormDescription>Fixed text, variables in braces and exactly one counter, {'{SEQ:5}'} for five digits.</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {canManage && (
                <div className="flex flex-wrap gap-1.5" role="group" aria-label="Insert a variable">
                  <Button type="button" size="sm" variant="outline" className="h-7 font-mono text-xs" title="The running counter, zero-padded to 5 digits" onClick={() => insert('{SEQ:5}')}>{'{SEQ:5}'}</Button>
                  {variableChips.map((chip) => (
                    <Button key={chip.token} type="button" size="sm" variant="outline" className="h-7 text-xs" title={chip.hint} onClick={() => insert(chip.token)}>
                      <span className="font-mono">{chip.token}</span>
                      <span className="ml-1.5 text-muted-foreground">{chip.label}</span>
                    </Button>
                  ))}
                </div>
              )}

              <FormField
                control={form.control}
                name="scope_period"
                render={({ field }) => (
                  <FormItem className="max-w-sm">
                    <FormLabel>Restart the count</FormLabel>
                    <Select value={field.value} onValueChange={(value) => field.onChange(value as ScopePeriodCode)} disabled={!canManage}>
                      <FormControl><SelectTrigger aria-label="Restart the count"><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>{PERIOD_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectContent>
                    </Select>
                    <FormDescription>{PERIOD_OPTIONS.find((option) => option.value === field.value)?.help}</FormDescription>
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="continuity"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>If a document fails to save</FormLabel>
                    <OptionCards<ContinuityCode>
                      label="Gaps"
                      value={field.value}
                      onChange={(value) => canManage && !type.continuity_locked && field.onChange(value)}
                      className="sm:grid-cols-2 lg:grid-cols-2"
                      options={[
                        { value: 'ALLOW_GAPS', label: CONTINUITY_LABELS.ALLOW_GAPS, description: 'A number used by a save that fails is not given back. Issues never wait on each other. Right for most documents.' },
                        { value: 'GAPLESS', label: CONTINUITY_LABELS.GAPLESS, description: 'Every number is used. Issues of one counter are processed one after another. For documents the law says must have no gaps.' },
                      ]}
                    />
                    {type.continuity_locked && <FormDescription>This kind of document must stay {CONTINUITY_LABELS[type.default_continuity].toLowerCase()}; it cannot be changed.</FormDescription>}
                  </FormItem>
                )}
              />

              <AdvancedOptionSection expanded={showDimensions}>
                <FormField
                  control={form.control}
                  name="dimensions"
                  render={({ field }) => (
                    <FormItem className="rounded-md border p-3">
                      <FormLabel>Count separately for each…</FormLabel>
                      <FormDescription>Each value gets its own counter. Make sure the text of the number tells them apart — the tester checks.</FormDescription>
                      <div className="flex flex-wrap gap-4">
                        {type.dimensions.map((dimension) => (
                          <label key={dimension} className="flex items-center gap-2 text-sm">
                            <Checkbox
                              checked={field.value.includes(dimension)}
                              disabled={!canManage}
                              onCheckedChange={(checked) => field.onChange(checked === true ? [...field.value, dimension] : field.value.filter((value) => value !== dimension))}
                              aria-label={dimension}
                            />
                            {type.variables.find((variable) => variable.key === dimension)?.label ?? dimension}
                          </label>
                        ))}
                      </div>
                    </FormItem>
                  )}
                />
              </AdvancedOptionSection>
            </div>
          </DetailSection>

          <DetailSection title="Preview" description="Never takes a number — it only shows what this format would do." fullWidth>
            <div className="sm:col-span-2"><NumberingTester result={result} loading={preview.isPending} failed={previewFailed} /></div>
          </DetailSection>

          {canManage && (
            <DetailSection title="Save the change" description="Applies to numbers issued from now on. Numbers already issued do not change." fullWidth>
              <div className="space-y-3 sm:col-span-2">
                <FormField
                  control={form.control}
                  name="reason"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Why is it changing?</FormLabel>
                      <FormControl><Textarea {...field} rows={2} placeholder="Kept with the change, so anyone can see who changed it and why." /></FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                {formError && <p role="alert" className="text-sm text-destructive">{formError}</p>}
                {saved && <p role="status" className="text-sm text-status-success">Saved. New numbers follow the new format.</p>}
                <Button type="submit" disabled={save.isPending || unchanged || (result !== null && (result.errors.length > 0 || result.collisions.length > 0))}>
                  {save.isPending ? 'Saving…' : unchanged ? 'No change to save' : 'Save format'}
                </Button>
              </div>
            </DetailSection>
          )}
        </form>
      </Form>

      <AdvancedOptionSection expanded={showSeries}>
        <SeriesSection type={type} selectedId={seriesId} onSelect={setSeriesId} canManage={canManage} />
      </AdvancedOptionSection>
      <AdvancedOptionSection expanded={showCounters}>
        <CountersSection series={series} canRaise={mayRaise} />
      </AdvancedOptionSection>
      <AdvancedOptionSection expanded={showHistory}>
        <HistorySection series={series} />
      </AdvancedOptionSection>
    </div>
  );
}
