import { useState, type FormEvent } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  useInventoryCutoversShow,
  useInventoryCutoversBatchesStore,
  useInventoryCutoversValidate,
  useInventoryCutoversPreview,
  useInventoryCutoversApprove,
  useInventoryCutoversCommit,
  useInventoryCutoversOpenPurchaseOrdersCommit,
  useInventoryCutoversFinalise,
  useInventoryCutoversNegativeOpeningsStore,
  useInventoryCutoverNegativeOpeningsApprove,
  useItemsIndex,
  useLocationsIndex,
  type RequestInventoryCutoverNegativeOpeningRequest,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';
import { Combobox } from '../../components/ui/combobox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useAuthStore } from '../../stores/auth-store';
import { formatLocation } from '../../lib/format-location';

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'success' | 'destructive'> = {
  DRAFT: 'secondary',
  VALIDATED: 'default',
  APPROVED: 'default',
  FINALISED: 'success',
  FAILED: 'destructive',
};

const NEGATIVE_OPENING_STATUS_VARIANT: Record<string, 'secondary' | 'success'> = {
  REQUESTED: 'secondary',
  APPROVED: 'success',
};

/**
 * The workspace for one inventory cutover: header info, its lifecycle
 * actions, attached batches, an on-demand preview and the negative-opening
 * exception sub-flow. Every mutation refetches the cutover rather than
 * patching local state, matching physical-count-detail-screen.tsx.
 *
 * "Attach batch" takes a raw numeric Import Batch ID — the Imports module
 * has no HTTP API/UI of its own yet to browse or create batches through
 * (confirmed scope boundary, see docs/domain/inventory-cutover.md). The
 * negative-opening request form's "Responsible owner" is a raw numeric User
 * ID for the same reason — there is no user-picker endpoint yet either.
 */
export function InventoryCutoverDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const cutoverId = Number(params.id);

  const cutoverQuery = useInventoryCutoversShow(cutoverId, { include: 'batches,policyTransitions,negativeOpenings,creator' });
  const cutover = cutoverQuery.data?.status === 200 ? cutoverQuery.data.data.data : undefined;
  const batches = cutover?.batches ?? [];
  const negativeOpenings = cutover?.negative_openings ?? [];

  const currentUser = useAuthStore((state) => state.user);

  const [actionError, setActionError] = useState<string | null>(null);
  const [importBatchId, setImportBatchId] = useState('');
  const [showNegativeOpeningForm, setShowNegativeOpeningForm] = useState(false);
  const [negativeOpeningDraft, setNegativeOpeningDraft] = useState({
    item_id: '', location_id: '', batch_no: '', provisional_unit_cost: '',
    reason: '', responsible_owner_id: '', resolution_plan: '',
  });

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name', 'filter[status]': 'ACTIVE' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: 'code' });
  const locations = locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [];

  const previewQuery = useInventoryCutoversPreview(cutoverId, { query: { enabled: false } });

  const attachBatch = useInventoryCutoversBatchesStore();
  const validateCutover = useInventoryCutoversValidate();
  const approveCutover = useInventoryCutoversApprove();
  const commitCutover = useInventoryCutoversCommit();
  const commitOpenPurchaseOrders = useInventoryCutoversOpenPurchaseOrdersCommit();
  const finaliseCutover = useInventoryCutoversFinalise();
  const requestNegativeOpening = useInventoryCutoversNegativeOpeningsStore();
  const approveNegativeOpening = useInventoryCutoverNegativeOpeningsApprove();

  function refetch() {
    cutoverQuery.refetch();
  }

  function handleAttachBatch(event: FormEvent) {
    event.preventDefault();
    if (!importBatchId) return;
    setActionError(null);
    attachBatch.mutate(
      { inventoryCutover: cutoverId, data: { import_batch_id: Number(importBatchId) } },
      { onSuccess: () => { setImportBatchId(''); refetch(); }, onError: (error) => setActionError(getApiErrorMessage(error)) }
    );
  }

  function handleValidate() {
    setActionError(null);
    validateCutover.mutate({ inventoryCutover: cutoverId }, { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) });
  }

  function handleApprove() {
    setActionError(null);
    approveCutover.mutate({ inventoryCutover: cutoverId }, { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) });
  }

  function handleCommit() {
    setActionError(null);
    commitCutover.mutate({ inventoryCutover: cutoverId }, { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) });
  }

  function handleFinalise() {
    setActionError(null);
    finaliseCutover.mutate({ inventoryCutover: cutoverId }, { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) });
  }

  function handleCommitOpenPurchaseOrders() {
    setActionError(null);
    commitOpenPurchaseOrders.mutate(
      { inventoryCutover: cutoverId },
      { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) }
    );
  }

  function handleApproveNegativeOpening(id: number) {
    setActionError(null);
    approveNegativeOpening.mutate({ inventoryCutoverNegativeOpening: id }, { onSuccess: refetch, onError: (error) => setActionError(getApiErrorMessage(error)) });
  }

  function handleRequestNegativeOpening(event: FormEvent) {
    event.preventDefault();
    const draft = negativeOpeningDraft;
    if (!draft.item_id || !draft.location_id || !draft.provisional_unit_cost || !draft.reason || !draft.responsible_owner_id || !draft.resolution_plan) return;
    setActionError(null);
    requestNegativeOpening.mutate(
      {
        inventoryCutover: cutoverId,
        data: {
          item_id: Number(draft.item_id),
          location_id: Number(draft.location_id),
          batch_no: draft.batch_no.trim() || undefined,
          provisional_unit_cost: draft.provisional_unit_cost.trim(),
          reason: draft.reason,
          // The generated type is imprecise here for the same reason as
          // target_valuation_policy — Scramble defaults a bare 'array' rule
          // to string[] even though the Action only requires non-empty JSON.
          supporting_evidence: { note: draft.reason } as unknown as RequestInventoryCutoverNegativeOpeningRequest['supporting_evidence'],
          responsible_owner_id: Number(draft.responsible_owner_id),
          resolution_plan: draft.resolution_plan,
        },
      },
      {
        onSuccess: () => {
          setNegativeOpeningDraft({ item_id: '', location_id: '', batch_no: '', provisional_unit_cost: '', reason: '', responsible_owner_id: '', resolution_plan: '' });
          setShowNegativeOpeningForm(false);
          refetch();
        },
        onError: (error) => setActionError(getApiErrorMessage(error)),
      }
    );
  }

  if (cutoverQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (cutoverQuery.isError || !cutover) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this inventory cutover.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/inventory-cutovers')}>
          Back to inventory cutovers
        </Button>
      </div>
    );
  }

  const isDraft = cutover.status === 'DRAFT';
  const isValidated = cutover.status === 'VALIDATED';
  const isApproved = cutover.status === 'APPROVED';
  // Mirrors ApproveInventoryCutover's own separation-of-duties check: approval
  // must be independent from both creation and validation.
  const isOwnCutover =
    !!currentUser && (currentUser.id === cutover.created_by || currentUser.id === cutover.validated_by);
  const hasPendingOpenPurchaseOrders = batches.some(
    (batch) => batch.entity_type === 'OPEN_PURCHASE_ORDER' && batch.status !== 'COMMITTED'
  );
  const preview = previewQuery.data?.status === 200 ? previewQuery.data.data.data : undefined;

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Inventory cutover #${cutover.id}`}
        description={cutover.source_system}
        breadcrumbs={[{ label: 'Inventory Cutovers', to: '/inventory-cutovers' }, { label: `#${cutover.id}` }]}
        actions={
          <>
            <Button variant="outline" onClick={() => previewQuery.refetch()} disabled={previewQuery.isFetching}>
              {previewQuery.isFetching ? 'Loading…' : 'Preview'}
            </Button>
            {isDraft && (
              <Button onClick={handleValidate} disabled={validateCutover.isPending}>
                Validate
              </Button>
            )}
            {isValidated && (
              <div className="flex flex-col items-end gap-1">
                <Button
                  onClick={handleApprove}
                  disabled={approveCutover.isPending || isOwnCutover}
                  title={isOwnCutover ? "You can't approve a cutover you created or validated" : undefined}
                >
                  Approve
                </Button>
                {isOwnCutover && (
                  <span className="text-xs text-muted-foreground">
                    You can't approve your own cutover
                  </span>
                )}
              </div>
            )}
            {isApproved && (
              <>
                <Button variant="outline" onClick={handleCommit} disabled={commitCutover.isPending}>
                  Commit opening state
                </Button>
                {hasPendingOpenPurchaseOrders && (
                  <Button
                    variant="outline"
                    onClick={handleCommitOpenPurchaseOrders}
                    disabled={commitOpenPurchaseOrders.isPending}
                  >
                    Commit open purchase orders
                  </Button>
                )}
                <Button onClick={handleFinalise} disabled={finaliseCutover.isPending}>
                  Finalise
                </Button>
              </>
            )}
          </>
        }
      />

      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}

      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Status">
            <Badge variant={STATUS_VARIANT[cutover.status] ?? 'secondary'}>{cutover.status}</Badge>
          </DetailField>
          <DetailField label="Mode">{cutover.mode}</DetailField>
          <DetailField label="Cutover at">{formatDateTime(cutover.cutover_at)}</DetailField>
          <DetailField label="Source system">{cutover.source_system}</DetailField>
          <DetailField label="Control quantity">{cutover.control_quantity}</DetailField>
          <DetailField label="Control value">{cutover.control_value}</DetailField>
        </DetailSection>

        {preview && (
          <DetailSection title="Preview" description="Staged/control totals as of now — read-only, doesn't change state.">
            <DetailField label="Batches">{preview.batch_count}</DetailField>
            <DetailField label="Records (accepted / rejected)">{`${preview.accepted_record_count} / ${preview.rejected_record_count}`}</DetailField>
            <DetailField label="Source quantity">{preview.source_quantity}</DetailField>
            <DetailField label="Control quantity">{preview.control_quantity}</DetailField>
            <DetailField label="Source value">{preview.source_value}</DetailField>
            <DetailField label="Control value">{preview.control_value}</DetailField>
          </DetailSection>
        )}

        <DetailSection title="Batches" description={isDraft ? 'Attach a validated Imports batch by its ID.' : undefined} fullWidth>
          <div className="overflow-x-auto rounded-md border border-border sm:col-span-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Batch</TableHead>
                  <TableHead>Entity type</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Records</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Value</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {batches.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-16 text-center text-muted-foreground">
                      No batches attached yet.
                    </TableCell>
                  </TableRow>
                ) : (
                  batches.map((batch) => (
                    <TableRow key={batch.id}>
                      <TableCell>#{batch.import_batch_id}</TableCell>
                      <TableCell>{batch.entity_type}</TableCell>
                      <TableCell>
                        <Badge variant={batch.status === 'COMMITTED' ? 'success' : 'secondary'}>{batch.status}</Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{batch.accepted_record_count} / {batch.source_record_count}</TableCell>
                      <TableCell className="text-right tabular-nums">{batch.source_quantity}</TableCell>
                      <TableCell className="text-right tabular-nums">{batch.source_value}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          {isDraft && (
            <form onSubmit={handleAttachBatch} className="flex flex-wrap items-end gap-2 border-t border-border pt-4 sm:col-span-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Import batch ID</span>
                <Input
                  type="number"
                  className="h-9 w-40"
                  value={importBatchId}
                  onChange={(event) => setImportBatchId(event.target.value)}
                />
              </div>
              <Button type="submit" disabled={!importBatchId || attachBatch.isPending}>
                Attach batch
              </Button>
            </form>
          )}
        </DetailSection>

        <DetailSection title="Negative openings" description="ADR 0037's cutover-shortage exception — an evidenced negative opening balance, requested and approved by different actors." fullWidth>
          <div className="overflow-x-auto rounded-md border border-border sm:col-span-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Provisional cost</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {negativeOpenings.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-16 text-center text-muted-foreground">
                      No negative-opening exceptions.
                    </TableCell>
                  </TableRow>
                ) : (
                  negativeOpenings.map((exception) => (
                    <TableRow key={exception.id}>
                      <TableCell>{exception.item ? `${exception.item.code} — ${exception.item.name}` : exception.item_id}</TableCell>
                      <TableCell>{formatLocation(exception.location) ?? exception.location_id}</TableCell>
                      <TableCell className="text-right tabular-nums">{exception.source_quantity}</TableCell>
                      <TableCell className="text-right tabular-nums">{exception.provisional_unit_cost}</TableCell>
                      <TableCell>
                        <Badge variant={NEGATIVE_OPENING_STATUS_VARIANT[exception.status] ?? 'secondary'}>{exception.status}</Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        {exception.status === 'REQUESTED' && (
                          <Button variant="outline" size="sm" onClick={() => handleApproveNegativeOpening(exception.id)} disabled={approveNegativeOpening.isPending}>
                            Approve
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          {!showNegativeOpeningForm ? (
            <div className="sm:col-span-2">
              <Button type="button" variant="outline" size="sm" onClick={() => setShowNegativeOpeningForm(true)}>
                Request negative-opening exception
              </Button>
            </div>
          ) : (
            <form onSubmit={handleRequestNegativeOpening} className="grid grid-cols-1 gap-3 border-t border-border pt-4 sm:col-span-2 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Item</span>
                <Combobox {...lookupState(itemsQuery, 'items')}
                  className="h-9"
                  value={negativeOpeningDraft.item_id}
                  onValueChange={(value) => setNegativeOpeningDraft((draft) => ({ ...draft, item_id: value }))}
                  placeholder="Select an item"
                  searchPlaceholder="Search items…"
                  emptyText="No items found."
                  options={items.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Location</span>
                <Combobox {...lookupState(locationsQuery, 'locations')}
                  className="h-9"
                  value={negativeOpeningDraft.location_id}
                  onValueChange={(value) => setNegativeOpeningDraft((draft) => ({ ...draft, location_id: value }))}
                  placeholder="Select a location"
                  searchPlaceholder="Search locations…"
                  emptyText="No locations found."
                  options={locations.map((location) => ({ value: String(location.id), label: formatLocation(location) }))}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Batch (optional)</span>
                <Input value={negativeOpeningDraft.batch_no} onChange={(event) => setNegativeOpeningDraft((draft) => ({ ...draft, batch_no: event.target.value }))} />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Provisional unit cost</span>
                <Input
                  type="number"
                  step="0.0001"
                  value={negativeOpeningDraft.provisional_unit_cost}
                  onChange={(event) => setNegativeOpeningDraft((draft) => ({ ...draft, provisional_unit_cost: event.target.value }))}
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Responsible owner (User ID)</span>
                <Input
                  type="number"
                  value={negativeOpeningDraft.responsible_owner_id}
                  onChange={(event) => setNegativeOpeningDraft((draft) => ({ ...draft, responsible_owner_id: event.target.value }))}
                />
              </div>
              <div className="flex flex-col gap-1 sm:col-span-2">
                <span className="text-xs font-medium text-muted-foreground">Reason</span>
                <Textarea value={negativeOpeningDraft.reason} onChange={(event) => setNegativeOpeningDraft((draft) => ({ ...draft, reason: event.target.value }))} />
              </div>
              <div className="flex flex-col gap-1 sm:col-span-2">
                <span className="text-xs font-medium text-muted-foreground">Resolution plan</span>
                <Textarea value={negativeOpeningDraft.resolution_plan} onChange={(event) => setNegativeOpeningDraft((draft) => ({ ...draft, resolution_plan: event.target.value }))} />
              </div>
              <div className="flex justify-end gap-2 sm:col-span-2">
                <Button type="button" variant="outline" onClick={() => setShowNegativeOpeningForm(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={requestNegativeOpening.isPending}>
                  Submit request
                </Button>
              </div>
            </form>
          )}
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
