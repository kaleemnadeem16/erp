import { useState, type FormEvent, type ReactNode } from 'react';
import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { useLocation, useNavigate, useParams } from 'react-router';
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
  useLocationsIndex,
  type InventoryCutoverBatchResource,
  type InventoryCutoverNegativeOpeningResource,
  type InventoryCutoverResource,
  type RequestInventoryCutoverNegativeOpeningRequest,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Badge } from '../../components/ui/badge';
import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { Skeleton } from '../../components/ui/skeleton';
import { Combobox } from '../../components/ui/combobox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { formatDate, formatDateTime } from '../../lib/format-date';
import { useAuthStore } from '../../stores/auth-store';
import { formatLocation } from '../../lib/format-location';
import { usePermission } from '../../hooks/use-permission';
import { DecimalInput } from '../../components/ui/decimal-input';
import { RecordPresenter } from '../../components/page/record-presenter';
import type { Presentation } from '../../lib/presentation';

const STATUS_TONE: Record<string, StatusTone> = {
  DRAFT: 'draft',
  VALIDATED: 'progress',
  APPROVED: 'active',
  FINALISED: 'success',
  FAILED: 'danger',
};

type ConfirmSpec = {
  title: string;
  description: ReactNode;
  confirmLabel: string;
  pendingLabel: string;
  destructive?: boolean;
  run: () => Promise<unknown>;
};

type SummaryRow = { label: string; value: ReactNode };

/** The record being acted on, shown inside a confirmation so the person sees exactly what they are about to make permanent. */
function ConfirmSummary({ consequence, rows, label }: { consequence: ReactNode; rows: SummaryRow[]; label: string }) {
  return (
    <div className="space-y-3 text-left">
      <p className="text-sm text-foreground">{consequence}</p>
      <dl aria-label={label} className="divide-y divide-border rounded-md border border-border bg-muted/40 text-sm" data-testid="cutover-confirm-summary">
        {rows.map((row) => (
          <div key={row.label} className="flex flex-col gap-0.5 px-3 py-2 sm:flex-row sm:justify-between sm:gap-4">
            <dt className="text-muted-foreground">{row.label}</dt>
            <dd className="min-w-0 break-words font-medium tabular-nums text-foreground sm:text-right">{row.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

/** Server refusals that use developer wording, restated for the owner. The server's own message and codes are untouched; anything not listed is shown as sent. */
const PLAIN_REFUSALS: [RegExp, (match: RegExpMatchArray, hasBatches: boolean) => string][] = [
  [/^Only an approved cutover can materialise open purchase orders\.?$/i, () => 'Only an approved cutover can create its open purchase orders.'],
  [/^Every open-purchase-order batch must be validated before commit\.?$/i, () => 'Every open-purchase-order batch must be checked (validated) before the orders can be created.'],
  [/^Source purchase order (.+) must be staged in one immutable import batch\.?$/i, (m) => `Purchase order ${m[1]} from the old system is split across more than one import batch; it must be in exactly one.`],
  [/^Source purchase order (.+) has inconsistent header facts\.?$/i, (m) => `Purchase order ${m[1]} from the old system has conflicting header details (for example different suppliers or dates) across its lines.`],
  [/^Source purchase order (.+) contains duplicate line identity\.?$/i, (m) => `Purchase order ${m[1]} from the old system has the same line number or line identifier twice.`],
  [/^Every cutover batch must be committed by its owning domain Action before finalisation\.?$/i, (_m, hasBatches) => hasBatches ? 'Finalising is not possible yet: every batch must be committed first. Commit the remaining batches, then try again.' : 'Finalising is not possible yet: the cutover must have its batches attached and committed first.'],
  [/^The actor who requested this exception cannot also approve it\.?$/i, () => 'The person who requested this negative opening cannot also approve it.'],
];

export function plainCutoverRefusal(message: string | null, hasBatches = true): string | null {
  if (!message) return message;
  for (const [pattern, plain] of PLAIN_REFUSALS) {
    const match = message.match(pattern);
    if (match) return plain(match, hasBatches);
  }
  return message;
}

/** Runs a confirmed action; a refusal is rethrown with plain wording so the dialog's alert shows it. */
async function withPlainRefusal(run: () => Promise<unknown>, hasBatches = true): Promise<unknown> {
  try {
    return await run();
  } catch (failure) {
    const message = getApiErrorMessage(failure);
    const plain = plainCutoverRefusal(message, hasBatches);
    if (plain && plain !== message) throw new Error(plain);
    throw failure;
  }
}

function batchRows(batches: InventoryCutoverBatchResource[]): SummaryRow[] {
  const total = (key: 'accepted_record_count' | 'source_record_count') => batches.reduce((sum, batch) => sum + batch[key], 0);
  return [
    { label: 'Batches', value: String(batches.length) },
    { label: 'Source records accepted / in the batch', value: `${total('accepted_record_count')} / ${total('source_record_count')}` },
    { label: 'Quantity', value: batches.reduce((sum, batch) => sum + Number(batch.source_quantity), 0).toLocaleString('en-US', { maximumFractionDigits: 4 }) },
    { label: 'Value', value: batches.reduce((sum, batch) => sum + Number(batch.source_value), 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
  ];
}

const EXCEPTION_LABEL: Record<string, string> = {
  REJECTED_RECORDS: 'Rejected source records (left out of the cutover)',
  NEGATIVE_OPENING_REQUESTED: 'Negative openings awaiting approval',
  NEGATIVE_OPENING_APPROVED: 'Negative openings approved (carried as shortages)',
};

/** Plain-language exception rows for the Finalise confirmation; says outright when there are none, or when the API did not send the summary. */
function exceptionRows(summary: InventoryCutoverResource['exception_summary'] | undefined): SummaryRow[] {
  if (!summary) return [{ label: 'Exceptions', value: 'Not available — reload the cutover before finalising' }];
  if (summary.total === 0) return [{ label: 'Exceptions', value: 'None — nothing was rejected and there are no negative openings' }];
  return [
    ...summary.categories.map((category) => ({ label: EXCEPTION_LABEL[category.code] ?? category.code, value: `${category.count}${category.unresolved ? ' (unresolved)' : ''}` })),
    { label: 'Still unresolved', value: summary.unresolved === 0 ? 'None' : String(summary.unresolved) },
  ];
}

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
export function InventoryCutoverDetailScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayStage = usePermission('inventory.cutover.stage');
  const mayValidate = usePermission('inventory.cutover.validate');
  const mayApprove = usePermission('inventory.cutover.approve');
  const mayCommit = usePermission('inventory.cutover.commit');
  const mayFinalise = usePermission('inventory.cutover.finalise');
  const mayCommitOpenOrders = usePermission('purchasing.cutover.commit');
  const mayRequestNegativeOpening = usePermission('inventory.cutover.negative_opening.request');
  const mayApproveNegativeOpening = usePermission('inventory.cutover.negative_opening.approve');
  const cutoverId = Number(params.id);

  const cutoverQuery = useInventoryCutoversShow(cutoverId, { include: 'batches,policyTransitions,negativeOpenings,creator' });
  const cutover = cutoverQuery.data?.status === 200 ? cutoverQuery.data.data.data : undefined;
  const batches = cutover?.batches ?? [];
  const negativeOpenings = cutover?.negative_openings ?? [];

  const currentUser = useAuthStore((state) => state.user);

  const [actionError, setActionError] = useState<string | null>(null);
  // The high-impact steps ask first; the dialog shows what will happen and the server's own refusal if there is one.
  const [confirming, setConfirming] = useState<ConfirmSpec | null>(null);
  const [importBatchId, setImportBatchId] = useState('');
  const [showNegativeOpeningForm, setShowNegativeOpeningForm] = useState(false);
  const [negativeOpeningDraft, setNegativeOpeningDraft] = useState({
    item_id: '', location_id: '', batch_no: '', provisional_unit_cost: '',
    reason: '', responsible_owner_id: '', resolution_plan: '',
  });

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
      { onSuccess: () => { setImportBatchId(''); refetch(); }, onError: (error) => setActionError(plainCutoverRefusal(getApiErrorMessage(error))) }
    );
  }

  function handleValidate() {
    setActionError(null);
    validateCutover.mutate({ inventoryCutover: cutoverId }, { onSuccess: refetch, onError: (error) => setActionError(plainCutoverRefusal(getApiErrorMessage(error))) });
  }

  function askApprove() {
    setConfirming({
      title: 'Approve this cutover?',
      description: 'Approval clears the cutover for its opening state to be committed. You cannot approve a cutover you created or validated.',
      confirmLabel: 'Approve',
      pendingLabel: 'Approving…',
      destructive: false,
      run: () => approveCutover.mutateAsync({ inventoryCutover: cutoverId }).then(refetch),
    });
  }

  function askCommit() {
    setConfirming({
      title: 'Commit the opening state?',
      description: 'This writes the opening stock balances from the attached batches into live inventory. It cannot be undone from this screen; later corrections are made with stock adjustments.',
      confirmLabel: 'Commit opening state',
      pendingLabel: 'Committing…',
      run: () => commitCutover.mutateAsync({ inventoryCutover: cutoverId }).then(refetch),
    });
  }

  function askFinalise() {
    const committed = batches.filter((batch) => batch.status === 'COMMITTED').length;
    const unresolved = cutover?.exception_summary?.unresolved ?? 0;
    setConfirming({
      title: 'Finalise this cutover?',
      description: (
        <ConfirmSummary
          label="Cutover being finalised"
          consequence={`Finalising closes the cutover for good. The cutover and all its batches become read-only, no more batches can be attached or committed, and nobody can reopen it. If a balance is wrong afterwards it can only be corrected with a stock adjustment.${unresolved > 0 ? ` ${unresolved} exception${unresolved === 1 ? '' : 's'} listed in the table below will be left unresolved by finalising.` : ''}`}
          rows={[
            { label: 'Cutover', value: `#${cutover?.id ?? cutoverId}` },
            { label: 'Source system', value: cutover?.source_system ?? '—' },
            { label: 'Cutover date', value: formatDate(cutover?.cutover_at) ?? '—' },
            { label: 'Batches committed', value: `${committed} of ${batches.length}` },
            ...batchRows(batches).slice(1),
            { label: 'Expected totals (quantity / value)', value: `${cutover?.control_quantity ?? '—'} / ${cutover?.control_value ?? '—'}` },
            ...exceptionRows(cutover?.exception_summary),
          ]}
        />
      ),
      confirmLabel: 'Finalise',
      pendingLabel: 'Finalising…',
      run: () => withPlainRefusal(() => finaliseCutover.mutateAsync({ inventoryCutover: cutoverId }).then(refetch), batches.length > 0),
    });
  }

  function askCommitOpenPurchaseOrders() {
    const orderBatches = batches.filter((batch) => batch.entity_type === 'OPEN_PURCHASE_ORDER');
    const toCreate = orderBatches.filter((batch) => batch.status !== 'COMMITTED');
    const plural = (n: number) => `${n} open-order import batch${n === 1 ? '' : 'es'}`;
    // The table lists every attached batch; only those not yet committed will create orders now.
    const batchCount = toCreate.length === orderBatches.length
      ? `${plural(orderBatches.length)} listed in the table below`
      : `${plural(toCreate.length)} still to commit (${orderBatches.length} listed in the table below, including ${orderBatches.length - toCreate.length} already committed)`;
    setConfirming({
      title: 'Commit the open purchase orders?',
      description: (
        <ConfirmSummary
          label="Open purchase orders being committed"
          consequence={`This creates real purchase orders in live purchasing from the open orders in the ${batchCount}, for cutover #${cutover?.id ?? cutoverId}. Your team can start receiving goods against these orders straight away. This cannot be undone here: a wrong order can only be cancelled or corrected afterwards in Purchasing.`}
          rows={[
            { label: 'Cutover', value: `#${cutover?.id ?? cutoverId}` },
            { label: 'Cutover date', value: formatDate(cutover?.cutover_at) ?? '—' },
            { label: 'Source system', value: cutover?.source_system ?? '—' },
            ...batchRows(orderBatches).map((row) => (row.label === 'Batches' ? { ...row, label: 'Import batches attached' } : row.label === 'Value' ? { ...row, label: 'Value (as imported)' } : row)),
            { label: 'Imported order files (batch numbers)', value: orderBatches.map((batch) => `#${batch.import_batch_id}${batch.status === 'COMMITTED' ? ' (already committed)' : ''}`).join(', ') || 'None attached' },
            { label: 'Purchase orders and suppliers', value: 'Counted and named when created; this screen does not hold them' },
          ]}
        />
      ),
      confirmLabel: 'Commit open purchase orders',
      pendingLabel: 'Committing…',
      run: () => withPlainRefusal(() => commitOpenPurchaseOrders.mutateAsync({ inventoryCutover: cutoverId }).then(refetch)),
    });
  }

  function askApproveNegativeOpening(exception: InventoryCutoverNegativeOpeningResource) {
    setConfirming({
      title: 'Approve this negative opening?',
      description: (
        <ConfirmSummary
          label="Negative opening being approved"
          consequence="Approving accepts that this item starts below zero stock at this location, valued at the provisional cost shown. The shortage is carried into live inventory at the opening and can distort stock value until the resolution plan is carried out. This cannot be undone from this screen."
          rows={[
            { label: 'Item', value: exception.item ? `${exception.item.code} — ${exception.item.name}` : `#${exception.item_id}` },
            { label: 'Warehouse / location', value: formatLocation(exception.location) ?? `#${exception.location_id}` },
            { label: 'Opening quantity', value: exception.source_quantity },
            { label: 'Provisional unit cost', value: exception.provisional_unit_cost },
            { label: 'Opening value', value: exception.source_value },
            { label: 'Reason', value: exception.reason },
            { label: 'Resolution plan', value: exception.resolution_plan },
            { label: 'Resolve by', value: formatDate(exception.target_date) ?? exception.target_date },
          ]}
        />
      ),
      confirmLabel: 'Approve negative opening',
      pendingLabel: 'Approving…',
      destructive: false,
      run: () => withPlainRefusal(() => approveNegativeOpening.mutateAsync({ inventoryCutoverNegativeOpening: exception.id }).then(refetch)),
    });
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
        onError: (error) => setActionError(plainCutoverRefusal(getApiErrorMessage(error))),
      }
    );
  }

  if (cutoverQuery.isLoading) {
    return (
      <RecordPresenter
        presentation={presentation}
        title="Inventory cutover"
        listPath="/inventory-cutovers"
        listLabel="Inventory Cutovers"
        size="xl"
      >
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </RecordPresenter>
    );
  }

  if (cutoverQuery.isError || !cutover) {
    return (
      <RecordPresenter
        presentation={presentation}
        title="Inventory cutover"
        listPath="/inventory-cutovers"
        listLabel="Inventory Cutovers"
        size="xl"
      >
        <p className="text-sm text-destructive">Couldn't load this inventory cutover.</p>
        <Button variant="outline" size="sm" onClick={() => navigate({ pathname: '/inventory-cutovers', search })}>
          Back to inventory cutovers
        </Button>
      </RecordPresenter>
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
    <RecordPresenter
      presentation={presentation}
      title={`Inventory cutover #${cutover.id}`}
      description={cutover.source_system}
      actions={
          <>
            <Button variant="outline" onClick={() => previewQuery.refetch()} disabled={previewQuery.isFetching}>
              {previewQuery.isFetching ? 'Loading…' : 'Preview'}
            </Button>
            {isDraft && mayValidate && (
              <Button onClick={handleValidate} disabled={validateCutover.isPending}>
                Validate
              </Button>
            )}
            {isValidated && mayApprove && (
              <div className="flex flex-col items-end gap-1">
                <Button
                  onClick={askApprove}
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
                {mayCommit && (
                  <Button variant="outline" onClick={askCommit} disabled={commitCutover.isPending}>
                    Commit opening state
                  </Button>
                )}
                {hasPendingOpenPurchaseOrders && mayCommitOpenOrders && (
                  <Button
                    variant="outline"
                    onClick={askCommitOpenPurchaseOrders}
                    disabled={commitOpenPurchaseOrders.isPending}
                  >
                    Commit open purchase orders
                  </Button>
                )}
                {mayFinalise && (
                  <Button onClick={askFinalise} disabled={finaliseCutover.isPending}>
                    Finalise
                  </Button>
                )}
              </>
            )}
          </>
        }
      listPath="/inventory-cutovers"
      listLabel="Inventory Cutovers"
      size="xl"
    >

      {confirming && (
        <ConfirmActionDialog
          open
          onOpenChange={(next) => !next && setConfirming(null)}
          title={confirming.title}
          description={confirming.description}
          confirmLabel={confirming.confirmLabel}
          pendingLabel={confirming.pendingLabel}
          cancelLabel="Not now"
          destructive={confirming.destructive ?? true}
          onConfirm={confirming.run}
          testId="cutover-confirm-dialog"
        />
      )}

      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}

      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Status">
            <StatusBadge tone={STATUS_TONE[cutover.status] ?? 'draft'}>{cutover.status}</StatusBadge>
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

          {isDraft && mayStage && (
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
                        {exception.status === 'REQUESTED' && mayApproveNegativeOpening && (
                          <Button variant="outline" size="sm" onClick={() => askApproveNegativeOpening(exception)} disabled={approveNegativeOpening.isPending}>
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

          {mayRequestNegativeOpening && (!showNegativeOpeningForm ? (
            <div className="sm:col-span-2">
              <Button type="button" variant="outline" size="sm" onClick={() => setShowNegativeOpeningForm(true)}>
                Request negative-opening exception
              </Button>
            </div>
          ) : (
            <form onSubmit={handleRequestNegativeOpening} className="grid grid-cols-1 gap-3 border-t border-border pt-4 sm:col-span-2 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Item</span>
                <ItemCombobox label="Item" placeholder="Select an item" className="h-9" value={negativeOpeningDraft.item_id} onValueChange={(value) => setNegativeOpeningDraft((draft) => ({ ...draft, item_id: value }))} />
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
                <DecimalInput
                  rule={{ spec: 'money', sign: 'nonNegative' }}
                  value={negativeOpeningDraft.provisional_unit_cost}
                  onChange={(next) => setNegativeOpeningDraft((draft) => ({ ...draft, provisional_unit_cost: next }))}
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
          ))}
        </DetailSection>
      </DetailPageLayout>
    </RecordPresenter>
  );
}
