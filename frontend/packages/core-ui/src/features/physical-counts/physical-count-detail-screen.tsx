import { useMemo, useState, type FormEvent } from 'react';
import { useParams, useNavigate } from 'react-router';
import { Check, Plus } from 'lucide-react';
import {
  useItemsIndex,
  useLocationsIndex,
  usePhysicalCountLinesCount,
  usePhysicalCountsCancel,
  usePhysicalCountsLinesStore,
  usePhysicalCountsPost,
  usePhysicalCountsShow,
  type PhysicalCountLineResource,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';
import { Combobox } from '../../components/ui/combobox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/auth-store';
import { formatLocation } from '../../lib/format-location';
import { usePermission } from '../../hooks/use-permission';

const STATUS_VARIANT: Record<string, 'secondary' | 'success' | 'destructive'> = {
  DRAFT: 'secondary',
  POSTED: 'success',
  CANCELLED: 'destructive',
};

// Null without a book quantity: a counter who counts blind (ADR 0083) is never shown the variance, which would give the book figure away.
function lineVariance(line: PhysicalCountLineResource): number | null {
  return line.counted_qty === null || line.book_qty === undefined ? null : Number(line.counted_qty) - Number(line.book_qty);
}

/**
 * The workspace for one count: header info plus a lines table that's the
 * count's whole working surface while DRAFT — add a line, record its
 * quantity, supply a found cost for anything counted higher than book, then
 * post. Every mutation refetches the count rather than patching local state,
 * since the truth (variance, whether every line is counted) always comes
 * from the server, not from re-deriving it client-side.
 */
export function PhysicalCountDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const mayCreate = usePermission('inventory.physical_count.create');
  const mayCount = usePermission('inventory.physical_count.count');
  const mayPost = usePermission('inventory.physical_count.post');
  const mayViewValuation = usePermission('inventory.valuation.view');
  const maySeeFoundCost = mayPost || mayViewValuation;
  // Mirrors the server (StockVisibility::bookQuantity): a counter who holds only the count capability counts blind.
  const mayStockView = usePermission('inventory.stock.view');
  const maySeeBook = mayStockView || mayCreate || mayPost;
  const countId = Number(params.id);

  const countQuery = usePhysicalCountsShow(countId, { include: 'warehouse,lines,lines.item,lines.location' });
  const count = countQuery.data?.status === 200 ? countQuery.data.data.data : undefined;
  const lines = useMemo(() => count?.lines ?? [], [count]);

  const currentUser = useAuthStore((state) => state.user);
  const isOwnCount = !!currentUser && !!count && currentUser.id === count.created_by;

  const [countedDrafts, setCountedDrafts] = useState<Record<number, string>>({});
  const [foundCostDrafts, setFoundCostDrafts] = useState<Record<number, string>>({});
  const [actionError, setActionError] = useState<string | null>(null);

  const [newItemId, setNewItemId] = useState('');
  const [newLocationId, setNewLocationId] = useState('');
  const [newBatchNo, setNewBatchNo] = useState('');
  const [newSerialNo, setNewSerialNo] = useState('');

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name', 'filter[status]': 'ACTIVE' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];

  const locationsQuery = useLocationsIndex(
    { 'filter[warehouse_id]': count?.warehouse_id, 'filter[active]': true, per_page: 100, sort: 'code' },
    { query: { enabled: !!count } }
  );
  const locations = locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [];

  const addLine = usePhysicalCountsLinesStore();
  const recordLine = usePhysicalCountLinesCount();
  const postCount = usePhysicalCountsPost();
  const cancelCount = usePhysicalCountsCancel();

  const isDraft = count?.status === 'DRAFT';
  const everyLineCounted = lines.length > 0 && lines.every((line) => line.counted_qty !== null);

  function handleAddLine(event: FormEvent) {
    event.preventDefault();
    if (!newItemId || !newLocationId) return;
    setActionError(null);
    addLine.mutate(
      {
        physicalCount: countId,
        data: {
          item_id: Number(newItemId),
          location_id: Number(newLocationId),
          batch_no: newBatchNo.trim() || undefined,
          serial_no: newSerialNo.trim() || undefined,
        },
      },
      {
        onSuccess: () => {
          setNewItemId('');
          setNewLocationId('');
          setNewBatchNo('');
          setNewSerialNo('');
          countQuery.refetch();
        },
        onError: (error) => setActionError(getApiErrorMessage(error)),
      }
    );
  }

  function handleRecordLine(line: PhysicalCountLineResource) {
    const value = countedDrafts[line.id] ?? line.counted_qty ?? '';
    if (value === '') return;
    setActionError(null);
    recordLine.mutate(
      { physicalCountLine: line.id, data: { counted_qty: String(value).trim() } },
      { onSuccess: () => countQuery.refetch(), onError: (error) => setActionError(getApiErrorMessage(error)) }
    );
  }

  function handlePost() {
    setActionError(null);
    const foundCosts = lines
      .filter((line) => (lineVariance(line) ?? 0) > 0)
      .map((line) => ({
        physical_count_line_id: line.id,
        unit_cost: String(foundCostDrafts[line.id] ?? line.found_unit_cost ?? '0').trim(),
      }));

    postCount.mutate(
      { physicalCount: countId, data: { found_costs: foundCosts } },
      { onSuccess: () => countQuery.refetch(), onError: (error) => setActionError(getApiErrorMessage(error)) }
    );
  }

  function handleCancel() {
    setActionError(null);
    cancelCount.mutate(
      { physicalCount: countId },
      { onSuccess: () => countQuery.refetch(), onError: (error) => setActionError(getApiErrorMessage(error)) }
    );
  }

  if (countQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (countQuery.isError || !count) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this physical count.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/physical-counts')}>
          Back to physical counts
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Physical count #${count.id}`}
        description={count.reference ?? undefined}
        breadcrumbs={[{ label: 'Physical Counts', to: '/physical-counts' }, { label: `#${count.id}` }]}
        actions={
          isDraft && (mayCreate || mayPost) && (
            <>
              {mayCreate && <Button variant="outline" onClick={handleCancel} disabled={cancelCount.isPending}>
                Cancel count
              </Button>}
              {mayPost && <div className="flex flex-col items-end gap-1">
                <Button
                  onClick={handlePost}
                  disabled={!everyLineCounted || postCount.isPending || isOwnCount}
                  title={isOwnCount ? "You can't post a physical count you created" : undefined}
                >
                  {postCount.isPending ? 'Posting…' : 'Post count'}
                </Button>
                {isOwnCount && (
                  <span className="text-xs text-muted-foreground">You can't post your own count</span>
                )}
              </div>}
            </>
          )
        }
      />

      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}

      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Warehouse">{count.warehouse?.name}</DetailField>
          <DetailField label="Status">
            <Badge variant={STATUS_VARIANT[count.status] ?? 'secondary'}>{count.status}</Badge>
          </DetailField>
          <DetailField label="Reference">{count.reference}</DetailField>
          <DetailField label="Created">{formatDateTime(count.created_at)}</DetailField>
          <DetailField label="Posted">{formatDateTime(count.posted_at)}</DetailField>
          <DetailField label="Cancelled">{formatDateTime(count.cancelled_at)}</DetailField>
        </DetailSection>

        <DetailSection title="Lines" description={isDraft ? 'Record a physical quantity for every line before posting.' : undefined} fullWidth>
          <div className="overflow-x-auto rounded-md border border-border sm:col-span-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>Batch / serial</TableHead>
                  {maySeeBook && <TableHead className="text-right">Book qty</TableHead>}
                  <TableHead className="text-right">Counted qty</TableHead>
                  {maySeeBook && <TableHead className="text-right">Variance</TableHead>}
                  {maySeeFoundCost && <TableHead className="text-right">Found cost</TableHead>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={4 + (maySeeBook ? 2 : 0) + (maySeeFoundCost ? 1 : 0)} className="h-16 text-center text-muted-foreground">
                      No lines yet.
                    </TableCell>
                  </TableRow>
                ) : (
                  lines.map((line) => {
                    const varianceValue = lineVariance(line);
                    const needsFoundCost = isDraft && (varianceValue ?? 0) > 0;

                    return (
                      <TableRow key={line.id}>
                        <TableCell>{line.item ? `${line.item.code} — ${line.item.name}` : line.item_id}</TableCell>
                        <TableCell>{formatLocation(line.location) ?? line.location_id}</TableCell>
                        <TableCell>{line.batch_id ? `Batch #${line.batch_id}` : (line.serial_no ?? '—')}</TableCell>
                        {maySeeBook && <TableCell className="text-right tabular-nums">{line.book_qty}</TableCell>}
                        <TableCell className="text-right">
                          {isDraft && mayCount ? (
                            <div className="flex items-center justify-end gap-1">
                              <Input
                                type="number"
                                step="0.0001"
                                className="h-8 w-24 text-right"
                                value={countedDrafts[line.id] ?? line.counted_qty ?? ''}
                                onChange={(event) => setCountedDrafts((prev) => ({ ...prev, [line.id]: event.target.value }))}
                              />
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    className="h-8 w-8 shrink-0"
                                    onClick={() => handleRecordLine(line)}
                                    disabled={recordLine.isPending}
                                    aria-label="Save count"
                                  >
                                    <Check className="h-4 w-4" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>Save count</TooltipContent>
                              </Tooltip>
                            </div>
                          ) : (
                            <span className="tabular-nums">{line.counted_qty ?? '—'}</span>
                          )}
                        </TableCell>
                        {maySeeBook && (
                          <TableCell
                            className={cn(
                              'text-right tabular-nums',
                              varianceValue !== null && varianceValue > 0 && 'text-status-success',
                              varianceValue !== null && varianceValue < 0 && 'text-destructive'
                            )}
                          >
                            {varianceValue === null ? '—' : varianceValue.toFixed(4)}
                          </TableCell>
                        )}
                        {maySeeFoundCost && <TableCell className="text-right">
                          {needsFoundCost && mayPost ? (
                            <Input
                              type="number"
                              step="0.0001"
                              className="h-8 w-24 text-right"
                              value={foundCostDrafts[line.id] ?? line.found_unit_cost ?? ''}
                              onChange={(event) => setFoundCostDrafts((prev) => ({ ...prev, [line.id]: event.target.value }))}
                            />
                          ) : (
                            <span className="tabular-nums">{line.found_unit_cost ?? '—'}</span>
                          )}
                        </TableCell>}
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </div>

          {isDraft && mayCreate && (
            <form onSubmit={handleAddLine} className="flex flex-wrap items-end gap-2 border-t border-border pt-4 sm:col-span-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Item</span>
                <Combobox {...lookupState(itemsQuery, 'items')}
                  className="h-9 w-56"
                  value={newItemId}
                  onValueChange={setNewItemId}
                  placeholder="Select an item"
                  searchPlaceholder="Search items…"
                  emptyText="No items found."
                  options={items.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Location</span>
                <Combobox {...lookupState(locationsQuery, 'locations')}
                  className="h-9 w-40"
                  value={newLocationId}
                  onValueChange={setNewLocationId}
                  placeholder="Select a location"
                  searchPlaceholder="Search locations…"
                  emptyText="No locations found."
                  options={locations.map((location) => ({ value: String(location.id), label: formatLocation(location) }))}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Batch</span>
                <Input value={newBatchNo} onChange={(event) => setNewBatchNo(event.target.value)} className="h-9 w-28" />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Serial</span>
                <Input value={newSerialNo} onChange={(event) => setNewSerialNo(event.target.value)} className="h-9 w-28" />
              </div>
              <Button type="submit" disabled={!newItemId || !newLocationId || addLine.isPending}>
                <Plus className="h-4 w-4" />
                Add line
              </Button>
            </form>
          )}
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
