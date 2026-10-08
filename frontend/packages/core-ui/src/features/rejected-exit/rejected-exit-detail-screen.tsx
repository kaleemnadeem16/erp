import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useCurrenciesIndex, useItemsShow, useWarehousesShow } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage, getApiReasonCode, isApiError } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { usePermission } from '../../hooks/use-permission';
import { trimQty } from '../package-stock/quantity';
import { REASON_HINTS } from '../package-stock/types';
import { useTrackingState } from '../package-stock/use-tracking-state';
import { EXIT_CONFIG, useExitApi, useExitDocument, type ExitKind, type ExitLineRecord, type PreviewLine } from './rejected-exit-api';
import { ExitLineCard, type DraftLine } from './rejected-exit-line-card';

export const EXIT_STATUS_TONE: Record<string, StatusTone> = { DRAFT: 'draft', POSTED: 'success', CANCELLED: 'danger' };
export const EXIT_STATUS_LABEL: Record<string, string> = { DRAFT: 'Draft', POSTED: 'Posted', CANCELLED: 'Cancelled' };

/** One Supplier Return / Scrap: a draft is previewed and posted here; a posted or cancelled one is shown frozen. */
export function ExitDetailScreen({ kind }: { kind: ExitKind }) {
  const config = EXIT_CONFIG[kind];
  const navigate = useNavigate();
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const mayCreate = usePermission(config.createPermission);
  const mayPost = usePermission(config.postPermission);
  const api = useExitApi(kind);
  const { query, document } = useExitDocument(kind, id);
  const warehouse = useWarehousesShow(document?.warehouse_id ?? 0, undefined, { query: { enabled: !!document } });
  const warehouseName = warehouse.data?.status === 200 ? warehouse.data.data.data.name : undefined;
  const [previews, setPreviews] = useState<Record<number, PreviewLine>>({});
  const [confirming, setConfirming] = useState<'post' | 'cancel' | null>(null);
  const [actionError, setActionError] = useState<{ message: string | null; code: string | null } | null>(null);

  const isDraft = document?.status === 'DRAFT';
  const lines = useMemo(() => document?.lines ?? [], [document]);
  const runPreview = api.preview.run;

  // The preview a person reads before posting is the same one the server runs again at post time.
  useEffect(() => {
    if (!isDraft || lines.length === 0) return;
    runPreview(
      lines.map((line) => ({
        goods_receipt_line_id: line.goods_receipt_line_id,
        quantity: line.quantity,
        outcome_change_reason: line.outcome_change_reason ?? undefined,
        package_portion: line.package_portion ?? undefined,
      })),
    )
      .then((rows) => setPreviews(Object.fromEntries(rows.map((row) => [row.goods_receipt_line_id, row]))))
      .catch((error) => setActionError({ message: getApiErrorMessage(error), code: getApiReasonCode(error) }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isDraft, lines]);

  const blockers = Object.values(previews).flatMap((preview) => preview.blockers);
  const previewReady = lines.length > 0 && lines.every((line) => previews[line.goods_receipt_line_id]);
  const canPost = isDraft && previewReady && blockers.length === 0;

  async function act(which: 'post' | 'cancel') {
    setActionError(null);
    try {
      await (which === 'post' ? api.post.run(id) : api.cancel.run(id));
      setConfirming(null);
      await query.refetch();
    } catch (error) {
      setConfirming(null);
      setActionError({ message: getApiErrorMessage(error), code: getApiReasonCode(error) });
    }
  }

  if (query.isLoading) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }
  if (query.isError || !document) {
    const denied = isApiError(query.error) && query.error.status === 403;

    return (
      <div className="space-y-4 p-4 md:p-6" data-testid={denied ? 'exit-denied' : 'exit-error'}>
        <p className={denied ? 'text-sm text-muted-foreground' : 'text-sm text-destructive'}>
          {denied ? `You don't have permission to view this ${config.noun}.` : `Couldn't load this ${config.noun}.`}
        </p>
        <Button variant="outline" size="sm" onClick={() => navigate(config.basePath)}>Back to {config.plural.toLowerCase()}</Button>
      </div>
    );
  }

  const title = document.number ?? `${config.title} draft #${document.id}`;
  const actionable = isDraft && (mayCreate || mayPost);

  return (
    <ScreenTemplate kind="detail" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="exit-detail">
        <PageHeader
          title={title}
          description={document.reason}
          breadcrumbs={[{ label: config.plural, to: config.basePath }, { label: title }]}
          actions={
            actionable && (
              <>
                {mayCreate && <Button variant="outline" onClick={() => setConfirming('cancel')} data-testid="exit-cancel">Cancel draft</Button>}
                {mayCreate && <Button variant="outline" onClick={() => navigate(`${config.basePath}/${id}/edit`)} data-testid="exit-edit">Edit</Button>}
                {mayPost && (
                  <Button onClick={() => setConfirming('post')} disabled={!canPost || api.post.isPending} data-testid="exit-post">
                    Post {config.noun}
                  </Button>
                )}
              </>
            )
          }
        />

        {actionable && mayPost && !canPost && previewReady && (
          <p className="text-sm text-destructive" data-testid="exit-post-blocked">Posting is blocked until the points below are fixed — edit the draft.</p>
        )}
        {isDraft && mayCreate && !mayPost && (
          <p className="text-sm text-muted-foreground" data-testid="exit-needs-poster">Someone with permission to post {config.plural.toLowerCase()} will post this draft.</p>
        )}

        {actionError && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="exit-action-error" data-reason-code={actionError.code ?? undefined}>
            {(actionError.code && REASON_HINTS[actionError.code]) || actionError.message || 'That did not work.'}
          </div>
        )}

        <DetailPageLayout>
          <DetailSection title="General">
            <DetailField label="Status">
              <StatusBadge tone={EXIT_STATUS_TONE[document.status] ?? 'draft'}>{EXIT_STATUS_LABEL[document.status] ?? document.status}</StatusBadge>
            </DetailField>
            <DetailField label="Warehouse">{warehouseName}</DetailField>
            <DetailField label="Reason">{document.reason}</DetailField>
            <DetailField label="Created">{formatDateTime(document.created_at)}</DetailField>
            {document.posted_at && <DetailField label="Posted">{formatDateTime(document.posted_at)}</DetailField>}
            {document.cancelled_at && <DetailField label="Cancelled">{formatDateTime(document.cancelled_at)}</DetailField>}
            {/* Optional header fields appear only once they hold something. */}
            {kind === 'SUPPLIER_RETURN' ? (
              <>
                {document.supplier_reference && <DetailField label="Supplier's reference">{document.supplier_reference}</DetailField>}
                {document.carrier_note && <DetailField label="Carrier note">{document.carrier_note}</DetailField>}
                <ExpectedCredit document={document} />
              </>
            ) : (
              <>
                {document.disposal_method && <DetailField label="Disposal method">{document.disposal_method}</DetailField>}
                {document.disposal_reference && <DetailField label="Disposal reference">{document.disposal_reference}</DetailField>}
              </>
            )}
          </DetailSection>

          <DetailSection
            title="Stock"
            description={isDraft ? 'This is what posting would take out. Nothing has moved yet.' : document.status === 'POSTED' ? 'What left the warehouse. This record can no longer be changed.' : undefined}
            fullWidth
          >
            <div className="space-y-3 sm:col-span-2">
              {isDraft
                ? lines.map((line) => (
                    <ExitLineCard
                      key={line.id}
                      config={config}
                      line={asDraftLine(line)}
                      preview={previews[line.goods_receipt_line_id]}
                      readOnly
                      showErrors={false}
                      onChange={() => undefined}
                    />
                  ))
                : lines.map((line) => <FrozenLine key={line.id} line={line} />)}
            </div>
          </DetailSection>
        </DetailPageLayout>

        <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
          <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="exit-confirm">
            <DialogHeader>
              <DialogTitle>{confirming === 'post' ? `Post this ${config.noun}?` : 'Cancel this draft?'}</DialogTitle>
              <DialogDescription>
                {confirming === 'post'
                  ? `${config.consequence} It covers ${lines.length} line${lines.length === 1 ? '' : 's'}.`
                  : 'The draft is discarded. No stock has moved, so nothing changes in the warehouse.'}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setConfirming(null)}>Go back</Button>
              <Button onClick={() => void act(confirming ?? 'post')} disabled={api.post.isPending || api.cancel.isPending} data-testid="exit-confirm-action">
                {confirming === 'post' ? (api.post.isPending ? 'Posting…' : `Post ${config.noun}`) : 'Cancel draft'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </ScreenTemplate>
  );
}

function asDraftLine(line: ExitLineRecord): DraftLine {
  return {
    key: String(line.id),
    goods_receipt_line_id: line.goods_receipt_line_id,
    quantity: line.quantity,
    outcomeChangeReason: line.outcome_change_reason ?? '',
    portion: line.package_portion,
  };
}

/** Information only: nothing is booked against the supplier until finance exists (ADR 0097). */
function ExpectedCredit({ document }: { document: { expected_credit_amount?: string | null; expected_credit_currency_id?: number | null; expected_credit_reference?: string | null } }) {
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' }, { query: { enabled: !!document.expected_credit_currency_id } });
  const rows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  const code = rows.find((row) => row.id === document.expected_credit_currency_id)?.code;
  if (!document.expected_credit_amount && !document.expected_credit_reference) return null;

  return (
    <DetailField label="Expected credit (information only)">
      <span data-testid="expected-credit">
        {document.expected_credit_amount ? `${trimQty(document.expected_credit_amount)} ${code ?? ''}`.trim() : '—'}
        {document.expected_credit_reference ? ` · ${document.expected_credit_reference}` : ''}
      </span>
    </DetailField>
  );
}

/** A line of a posted or cancelled document: what was taken, plainly, with no controls. */
function FrozenLine({ line }: { line: ExitLineRecord }) {
  const item = useItemsShow(line.item_id);
  const record = item.data?.status === 200 ? item.data.data.data : undefined;
  const tracking = useTrackingState(line.item_id, !!line.package_portion?.length);
  const portion = (line.package_portion ?? [])
    .map((entry) =>
      entry.kind === 'SEALED'
        ? `${entry.count} sealed ${tracking?.packagings.find((packaging) => packaging.id === entry.packaging_id)?.name ?? 'package'}`
        : `${trimQty(entry.quantity)} loose`,
    )
    .join(' + ');

  return (
    <article className="space-y-1 rounded-md border p-3 text-sm" data-testid="exit-frozen-line">
      <h3 className="font-medium break-words">{record ? `${record.code} — ${record.name}` : `Item #${line.item_id}`}</h3>
      <p className="tabular-nums" data-testid="exit-frozen-quantity">{trimQty(line.quantity)}</p>
      {portion && <p className="text-muted-foreground">{portion}</p>}
      {line.outcome_change_reason && <p className="text-muted-foreground">Different from the quality decision because: {line.outcome_change_reason}</p>}
    </article>
  );
}
