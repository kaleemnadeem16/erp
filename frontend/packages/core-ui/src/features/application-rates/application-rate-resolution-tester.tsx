import { useEffect, useState } from 'react';
import {
  useApplicationRatesResolve,
  useItemsIndex,
  useItemsShow,
  useRateDimensionsIndex,
  useUomsIndex,
  useUomsShow,
} from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { RemoteLookupCombobox } from '../../components/ui/remote-lookup-combobox';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';

const NONE = 'NONE';

const METHOD_LABEL: Record<string, string> = {
  EXACT_MATCH: 'Exact match',
  REQUIRED_ONLY_MATCH: 'Matched on required dimensions only',
  DEFAULT: 'Fell back to the default rate',
};

/**
 * Preview of docs/domain/application-rates.md#resolution — lets someone see
 * which rate wins for a combination, and whether that was a real match or a
 * fallback, before any document exists to reference one (Phase 4).
 */
export function ApplicationRateResolutionTester() {
  const [itemSearch, setItemSearch] = useState('');
  const [debouncedItemSearch, setDebouncedItemSearch] = useState('');
  const [uomSearch, setUomSearch] = useState('');
  const [debouncedUomSearch, setDebouncedUomSearch] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedItemSearch(itemSearch), 300);
    return () => clearTimeout(timer);
  }, [itemSearch]);
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedUomSearch(uomSearch), 300);
    return () => clearTimeout(timer);
  }, [uomSearch]);
  const itemsResult = useItemsIndex({
    'filter[search]': debouncedItemSearch || undefined,
    per_page: 100,
    sort: 'name',
  });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const uomsResult = useUomsIndex({ 'filter[search]': debouncedUomSearch || undefined, per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const dimensionsResult = useRateDimensionsIndex({ per_page: 100, sort: 'sort_order', include: 'values' });
  const dimensions = dimensionsResult.data?.status === 200 ? dimensionsResult.data.data.data : [];

  const [itemId, setItemId] = useState('');
  const [workUomId, setWorkUomId] = useState('');
  const selectedItemQuery = useItemsShow(Number(itemId || 0), undefined, { query: { enabled: !!itemId } });
  const selectedItem = selectedItemQuery.data?.status === 200 ? selectedItemQuery.data.data.data : undefined;
  const selectedUomQuery = useUomsShow(Number(workUomId || 0), { query: { enabled: !!workUomId } });
  const selectedUom = selectedUomQuery.data?.status === 200 ? selectedUomQuery.data.data.data : undefined;
  const [dimensionValues, setDimensionValues] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState<{
    itemId: number;
    workUomId: number;
    dimensionValueIds: number[];
  } | null>(null);

  const test = () => {
    if (!itemId || !workUomId) return;
    const dimensionValueIds = Object.values(dimensionValues)
      .filter((v) => v !== NONE)
      .map(Number);
    setSubmitted({ itemId: Number(itemId), workUomId: Number(workUomId), dimensionValueIds });
  };

  const result = useApplicationRatesResolve(
    {
      item_id: submitted?.itemId ?? 0,
      work_uom_id: submitted?.workUomId ?? 0,
      'dimension_value_ids[]': submitted?.dimensionValueIds,
    },
    { query: { enabled: submitted !== null } },
  );
  const resolution = result.data?.status === 200 ? result.data.data.data : undefined;

  return (
    <DetailSection
      title="Test resolution"
      description="Preview which rate wins for a given item, work unit and combination of dimension values, and how it was chosen."
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <RemoteLookupCombobox
          {...lookupState(itemsResult, 'items')}
          label="Item"
          value={itemId}
          selectedLabel={selectedItem ? `${selectedItem.code} — ${selectedItem.name}` : undefined}
          onValueChange={setItemId}
          search={itemSearch}
          onSearchChange={setItemSearch}
          loading={itemsResult.isLoading || itemSearch !== debouncedItemSearch}
          options={(itemSearch === debouncedItemSearch ? items : []).map((i) => ({
            value: String(i.id),
            label: `${i.code} — ${i.name}`,
          }))}
        />
        <RemoteLookupCombobox
          {...lookupState(uomsResult, 'units')}
          label="Work unit"
          value={workUomId}
          selectedLabel={selectedUom ? `${selectedUom.name} (${selectedUom.code})` : undefined}
          onValueChange={setWorkUomId}
          search={uomSearch}
          onSearchChange={setUomSearch}
          loading={uomsResult.isLoading || uomSearch !== debouncedUomSearch}
          options={(uomSearch === debouncedUomSearch ? uoms : []).map((u) => ({
            value: String(u.id),
            label: `${u.name} (${u.code})`,
          }))}
        />
        {dimensions.map((dimension) => (
          <Combobox
            {...lookupState(dimensionsResult, 'rate dimensions')}
            key={dimension.id}
            aria-label={dimension.name}
            value={dimensionValues[dimension.id] ?? NONE}
            onValueChange={(v) => setDimensionValues((prev) => ({ ...prev, [dimension.id]: v }))}
            options={[
              { value: NONE, label: `${dimension.name}: not specified` },
              ...(dimension.values ?? []).map((value) => ({
                value: String(value.id),
                label: `${dimension.name}: ${value.name}`,
              })),
            ]}
            searchPlaceholder={`Search ${dimension.name.toLowerCase()}…`}
            emptyText="No values defined."
          />
        ))}
      </div>
      <Button type="button" onClick={test} disabled={!itemId || !workUomId}>
        Resolve
      </Button>
      {result.isFetching && submitted && <p className="text-sm text-muted-foreground">Resolving…</p>}
      {resolution && (
        <div className="rounded-md border p-3">
          <p className="text-sm font-medium">{METHOD_LABEL[resolution.method] ?? resolution.method}</p>
          <p className="text-sm text-muted-foreground">
            {resolution.rate.qty_per_work_unit} {resolution.rate.uom?.code ?? ''} per{' '}
            {resolution.rate.work_uom?.code ?? ''}
            {resolution.rate.dimension_values.length > 0 &&
              ` — tagged ${resolution.rate.dimension_values.map((v) => v.name).join(', ')}`}
          </p>
          {resolution.method === 'DEFAULT' && (
            <p className="mt-1 text-sm text-amber-600 dark:text-amber-400">
              No rate matched this combination — this is the fallback default, not a specific match.
            </p>
          )}
        </div>
      )}
      {result.isError && submitted && (
        <p className="text-sm text-destructive">
          {getApiErrorMessage(result.error) ?? 'No application rate resolves for this combination.'}
        </p>
      )}
    </DetailSection>
  );
}
