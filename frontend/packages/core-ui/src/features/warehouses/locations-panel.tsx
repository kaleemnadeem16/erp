import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  LocationType,
  useLocationsIndex,
  useLocationsStore,
  useLocationsUpdate,
  type LocationResource,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';

const NONE = 'NONE';
const FOLLOW = 'FOLLOW';
const COUNTS = 'COUNTS';
const NOT_COUNTED = 'NOT_COUNTED';

const TYPE_LABELS: Record<string, string> = {
  STORAGE: 'Storage',
  RECEIVING: 'Receiving',
  QUARANTINE: 'Quarantine',
  DISPATCH: 'Dispatch',
  RETURNS: 'Returns',
  TRANSIT: 'In transit',
  SCRAP: 'Scrap',
  PRODUCTION: 'Production',
  VIEW: 'Grouping only',
};

const detailsSchema = z.object({
  code: z.string().trim().min(1, 'A code is required').max(255),
  name: z.string().trim().min(1, 'A name is required').max(255),
  type: z.enum(LocationType),
  parent_id: z.string(),
  barcode: z.string().trim().max(255),
  sort_order: z.string().regex(/^\d{1,5}$/, 'A whole number from 0 to 32767'),
  allows_stock: z.boolean(),
  active: z.boolean(),
});
type DetailsValues = z.infer<typeof detailsSchema>;
const DETAIL_FIELDS = ['code', 'name', 'type', 'parent_id', 'barcode', 'sort_order', 'allows_stock', 'active'] as const;

const usableSchema = z.object({
  choice: z.enum([FOLLOW, COUNTS, NOT_COUNTED]),
  reason: z.string().trim().max(1000),
});
type UsableValues = z.infer<typeof usableSchema>;

interface LocationsPanelProps {
  warehouseId: number;
  /** STRUCTURED location mode: hierarchy, types and the usable-stock mark exist. SIMPLE shows a plain list. */
  structured: boolean;
  /** `inventory.location.manage`: add and edit locations. */
  mayManage: boolean;
  /** `inventory.location-policy.manage`: change what counts as usable stock. */
  mayMarkUsable: boolean;
}

type Editing = { kind: 'new' } | { kind: 'details' | 'usable'; id: number } | null;

/**
 * A warehouse's locations. A SIMPLE company sees a plain list (its one location is managed for it); a
 * STRUCTURED company sees the hierarchy with each location's type, and — for someone allowed — can add and
 * edit locations and decide, per location, whether its stock counts as usable for availability (ADR 0067).
 * Whether a location counts is shown for everyone, with whether the company's own mark or the location's
 * type decided, so a planner can see why a shortfall is what it is.
 */
export function LocationsPanel({ warehouseId, structured, mayManage, mayMarkUsable }: LocationsPanelProps) {
  const [editing, setEditing] = useState<Editing>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const query = useLocationsIndex({ 'filter[warehouse_id]': warehouseId, per_page: 100, sort: 'sort_order' });
  const rows = useMemo(() => (query.data?.status === 200 ? query.data.data.data : []), [query.data]);
  const ordered = useMemo(() => inHierarchyOrder(rows), [rows]);

  const done = () => {
    setEditing(null);
    setActionError(null);
    void query.refetch();
  };

  return (
    <DetailSection
      title="Locations"
      description={
        structured
          ? 'Zones, bins and operational locations. Whether a location’s stock counts as usable for availability follows its type unless your company has marked it.'
          : 'Every warehouse has at least one real location; switch on structured locations in Inventory settings for a hierarchy.'
      }
      fullWidth
    >
      <div className="space-y-3 sm:col-span-2">
        {actionError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>}

        {query.isError ? (
          <ListError message="Couldn't load the locations." error={query.error} onRetry={() => void query.refetch()} />
        ) : query.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {ordered.map(({ location, depth }) => (
              <li key={location.id} className="space-y-3 p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div style={{ paddingLeft: `${depth * 1.25}rem` }} className={location.active ? '' : 'text-muted-foreground'}>
                    <span className="font-medium">{location.name}</span>{' '}
                    <span className="text-xs text-muted-foreground">({location.code})</span>
                    {location.barcode && <span className="ml-2 text-xs text-muted-foreground">barcode {location.barcode}</span>}
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {structured && <Badge variant="outline">{TYPE_LABELS[location.type] ?? location.type}</Badge>}
                    {!location.active && <Badge variant="secondary">Inactive</Badge>}
                    {location.allows_stock ? <UsableBadge location={location} /> : <Badge variant="secondary">Holds no stock</Badge>}
                    {mayManage && (
                      <Button type="button" size="sm" variant="outline" aria-label={`Edit ${location.name}`} onClick={() => setEditing({ kind: 'details', id: location.id })}>
                        Edit
                      </Button>
                    )}
                    {structured && mayMarkUsable && location.allows_stock && (
                      <Button type="button" size="sm" variant="outline" aria-label={`Change usable stock rule for ${location.name}`} onClick={() => setEditing({ kind: 'usable', id: location.id })}>
                        Usable stock
                      </Button>
                    )}
                  </div>
                </div>
                {editing && editing.kind === 'details' && editing.id === location.id && (
                  <DetailsForm
                    location={location}
                    locations={rows}
                    structured={structured}
                    onCancel={() => setEditing(null)}
                    onSaved={done}
                    onError={setActionError}
                  />
                )}
                {editing && editing.kind === 'usable' && editing.id === location.id && (
                  <UsableForm location={location} onCancel={() => setEditing(null)} onSaved={done} onError={setActionError} />
                )}
              </li>
            ))}
          </ul>
        )}

        {structured && mayManage &&
          (editing?.kind === 'new' ? (
            <DetailsForm warehouseId={warehouseId} locations={rows} structured onCancel={() => setEditing(null)} onSaved={done} onError={setActionError} />
          ) : (
            <Button type="button" variant="outline" onClick={() => setEditing({ kind: 'new' })}>Add a location</Button>
          ))}
      </div>
    </DetailSection>
  );
}

/** Whether a location's stock counts, and who decided: the company's mark or the location's type. */
function UsableBadge({ location }: { location: LocationResource }) {
  const marked = location.counts_as_usable !== null;
  const source = marked ? 'marked by your company' : 'by its type';
  return location.counts_as_usable_effective ? (
    <Badge variant="success" title={`Counts as usable stock, ${source}`}>Counts as usable{marked ? ' (marked)' : ''}</Badge>
  ) : (
    <Badge variant="warning" title={`Not counted as usable stock, ${source}`}>Not counted{marked ? ' (marked)' : ''}</Badge>
  );
}

/** Parents before their children, siblings by sort order, each with its depth for indenting. */
function inHierarchyOrder(rows: LocationResource[]): Array<{ location: LocationResource; depth: number }> {
  const ids = new Set(rows.map((row) => row.id));
  const byParent = new Map<number | null, LocationResource[]>();
  for (const row of rows) {
    const key = row.parent_id !== null && ids.has(row.parent_id) ? row.parent_id : null;
    byParent.set(key, [...(byParent.get(key) ?? []), row]);
  }
  const out: Array<{ location: LocationResource; depth: number }> = [];
  const walk = (parent: number | null, depth: number) => {
    for (const child of byParent.get(parent) ?? []) {
      out.push({ location: child, depth });
      walk(child.id, depth + 1);
    }
  };
  walk(null, 0);
  return out;
}

function descendantIds(id: number, rows: LocationResource[]): Set<number> {
  const found = new Set<number>();
  const queue = [id];
  while (queue.length > 0) {
    const current = queue.pop() as number;
    for (const row of rows) {
      if (row.parent_id === current && !found.has(row.id)) {
        found.add(row.id);
        queue.push(row.id);
      }
    }
  }
  return found;
}

interface DetailsFormProps {
  /** Present when editing; absent when adding (then `warehouseId` is required). */
  location?: LocationResource;
  warehouseId?: number;
  locations: LocationResource[];
  structured: boolean;
  onCancel: () => void;
  onSaved: () => void;
  onError: (message: string | null) => void;
}

function DetailsForm({ location, warehouseId, locations, structured, onCancel, onSaved, onError }: DetailsFormProps) {
  const store = useLocationsStore();
  const update = useLocationsUpdate();
  const form = useForm<DetailsValues>({
    resolver: zodResolver(detailsSchema),
    defaultValues: {
      code: location?.code ?? '',
      name: location?.name ?? '',
      type: (location?.type as DetailsValues['type'] | undefined) ?? LocationType.STORAGE,
      parent_id: location?.parent_id != null ? String(location.parent_id) : NONE,
      barcode: location?.barcode ?? '',
      sort_order: String(location?.sort_order ?? 0),
      allows_stock: location?.allows_stock ?? true,
      active: location?.active ?? true,
    },
  });
  const type = form.watch('type');
  const blocked = location ? new Set([location.id, ...descendantIds(location.id, locations)]) : new Set<number>();
  const parents = locations.filter((row) => !blocked.has(row.id));
  const pending = store.isPending || update.isPending;

  function submit(values: DetailsValues) {
    onError(null);
    const structuredFields = {
      type: values.type,
      parent_id: values.parent_id === NONE ? null : Number(values.parent_id),
      barcode: values.barcode === '' ? null : values.barcode,
      sort_order: Number(values.sort_order),
      allows_stock: values.type === LocationType.VIEW ? false : values.allows_stock,
    };
    const handlers = {
      onSuccess: onSaved,
      onError: (e: unknown) => onError(applyApiErrorsToForm(e, form.setError, DETAIL_FIELDS)),
    };
    if (location) {
      update.mutate(
        { location: location.id, data: { name: values.name, active: values.active, ...(structured ? { code: values.code, ...structuredFields } : {}) } },
        handlers,
      );
    } else {
      store.mutate({ data: { warehouse_id: warehouseId as number, code: values.code, name: values.name, active: values.active, ...structuredFields } }, handlers);
    }
  }

  return (
    <Form {...form}>
      <form className="space-y-3 rounded-md border bg-muted/30 p-3" onSubmit={form.handleSubmit(submit)} aria-label={location ? `Edit ${location.name}` : 'Add a location'}>
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField control={form.control} name="name" render={({ field }) => (
            <FormItem><FormLabel>Name</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
          )} />
          {structured && (
            <FormField control={form.control} name="code" render={({ field }) => (
              <FormItem><FormLabel>Code</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
            )} />
          )}
          {structured && (
            <FormField control={form.control} name="type" render={({ field }) => (
              <FormItem>
                <FormLabel>Type</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl><SelectTrigger aria-label="Type"><SelectValue /></SelectTrigger></FormControl>
                  <SelectContent>
                    {Object.values(LocationType).map((value) => <SelectItem key={value} value={value}>{TYPE_LABELS[value] ?? value}</SelectItem>)}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )} />
          )}
          {structured && (
            <FormField control={form.control} name="parent_id" render={({ field }) => (
              <FormItem>
                <FormLabel>Inside</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl><SelectTrigger aria-label="Inside"><SelectValue /></SelectTrigger></FormControl>
                  <SelectContent>
                    <SelectItem value={NONE}>Top level</SelectItem>
                    {parents.map((row) => <SelectItem key={row.id} value={String(row.id)}>{row.name} ({row.code})</SelectItem>)}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )} />
          )}
          {structured && (
            <FormField control={form.control} name="barcode" render={({ field }) => (
              <FormItem><FormLabel>Barcode (optional)</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
            )} />
          )}
          {structured && (
            <FormField control={form.control} name="sort_order" render={({ field }) => (
              <FormItem><FormLabel>Order</FormLabel><FormControl><Input {...field} inputMode="numeric" /></FormControl><FormMessage /></FormItem>
            )} />
          )}
        </div>
        <div className="flex flex-wrap gap-6">
          {structured && type !== LocationType.VIEW && (
            <FormField control={form.control} name="allows_stock" render={({ field }) => (
              <FormItem className="flex items-center gap-2 space-y-0">
                <FormControl><Checkbox checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} /></FormControl>
                <FormLabel className="font-normal">Holds stock</FormLabel>
              </FormItem>
            )} />
          )}
          <FormField control={form.control} name="active" render={({ field }) => (
            <FormItem className="flex items-center gap-2 space-y-0">
              <FormControl><Checkbox checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} /></FormControl>
              <FormLabel className="font-normal">Active</FormLabel>
            </FormItem>
          )} />
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onCancel}>Cancel</Button>
          <Button type="submit" disabled={pending}>{pending ? 'Saving…' : location ? 'Save location' : 'Add location'}</Button>
        </div>
      </form>
    </Form>
  );
}

function UsableForm({ location, onCancel, onSaved, onError }: { location: LocationResource; onCancel: () => void; onSaved: () => void; onError: (message: string | null) => void }) {
  const update = useLocationsUpdate();
  const current = location.counts_as_usable === null ? FOLLOW : location.counts_as_usable ? COUNTS : NOT_COUNTED;
  const form = useForm<UsableValues>({ resolver: zodResolver(usableSchema), defaultValues: { choice: current, reason: '' } });
  const choice = form.watch('choice');
  const changed = choice !== current;

  function submit(values: UsableValues) {
    if (!changed) return;
    if (values.reason === '') {
      form.setError('reason', { message: 'Say why: this changes what planners see, and it is recorded.' });
      return;
    }
    onError(null);
    update.mutate(
      {
        location: location.id,
        data: {
          counts_as_usable: values.choice === FOLLOW ? null : values.choice === COUNTS,
          counts_as_usable_reason: values.reason,
        },
      },
      { onSuccess: onSaved, onError: (e) => onError(getApiErrorMessage(e) ?? 'Could not change this location.') },
    );
  }

  return (
    <Form {...form}>
      <form className="space-y-3 rounded-md border bg-muted/30 p-3" onSubmit={form.handleSubmit(submit)} aria-label={`Usable stock rule for ${location.name}`}>
        <p className="text-xs text-muted-foreground">
          Decides whether stock here counts as usable when the Material Preview checks availability. By default a location follows its type
          ({TYPE_LABELS[location.type] ?? location.type}: {defaultCounts(location.type) ? 'counts' : 'does not count'}). A change is recorded in the inventory policy history.
        </p>
        <FormField control={form.control} name="choice" render={({ field }) => (
          <FormItem>
            <FormLabel>Stock in this location</FormLabel>
            <Select value={field.value} onValueChange={field.onChange}>
              <FormControl><SelectTrigger aria-label="Stock in this location"><SelectValue /></SelectTrigger></FormControl>
              <SelectContent>
                <SelectItem value={FOLLOW}>Follow the location type (default)</SelectItem>
                <SelectItem value={COUNTS}>Always counts as usable</SelectItem>
                <SelectItem value={NOT_COUNTED}>Never counts as usable</SelectItem>
              </SelectContent>
            </Select>
            <FormMessage />
          </FormItem>
        )} />
        {changed && (
          <FormField control={form.control} name="reason" render={({ field }) => (
            <FormItem>
              <FormLabel>Why is this changing?</FormLabel>
              <FormControl><Textarea {...field} rows={2} /></FormControl>
              <FormMessage />
            </FormItem>
          )} />
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onCancel}>Cancel</Button>
          <Button type="submit" disabled={!changed || update.isPending}>{update.isPending ? 'Saving…' : 'Save rule'}</Button>
        </div>
      </form>
    </Form>
  );
}

/** Mirrors the server's type default (`LocationType::holdsUsableStock()`), for the explanation only. */
function defaultCounts(type: string): boolean {
  return type === 'STORAGE' || type === 'RECEIVING';
}
