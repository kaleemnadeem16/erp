import { useParams, useNavigate, Link, useLocation } from 'react-router';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useStockMovesShow } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { formatDateTime } from '../../lib/format-date';
import { formatLocation } from '../../lib/format-location';
import { getApiErrorMessage } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';
import type { Presentation } from '../../lib/presentation';

export interface StockMoveDetailScreenProps {
  moveId?: number;
  presentation?: Presentation;
  onClose?: () => void;
}

export function StockMoveDetailScreen({
  moveId,
  presentation = 'page',
  onClose,
}: StockMoveDetailScreenProps = {}) {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const mayViewValuation = usePermission('inventory.valuation.view');
  const effectiveMoveId = moveId ?? Number(params.id);

  const moveQuery = useStockMovesShow(effectiveMoveId, {
    include: mayViewValuation
      ? 'item,from_location,to_location,batch,valuation_entries'
      : 'item,from_location,to_location,batch',
  }, { query: { enabled: !Number.isNaN(effectiveMoveId) && effectiveMoveId > 0 } });
  const move = moveQuery.data?.status === 200 ? moveQuery.data.data.data : undefined;

  const handleClose = () => {
    if (onClose) {
      onClose();
    } else {
      navigate(`/stock-moves${location.search}`);
    }
  };

  if (moveQuery.isLoading) {
    const loadingBody = (
      <DetailPageLayout>
        <DetailSection title="Movement">
          <div className="space-y-1.5">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-4 w-40" />
          </div>
          <div className="space-y-1.5">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-4 w-32" />
          </div>
          <div className="space-y-1.5">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-4 w-32" />
          </div>
          <div className="space-y-1.5">
            <Skeleton className="h-3 w-16" />
            <Skeleton className="h-4 w-24" />
          </div>
        </DetailSection>
      </DetailPageLayout>
    );

    if (presentation === 'sheet') {
      return (
        <RecordSheet
          title="Stock movement"
          description="Loading movement details…"
          size="xl"
          className="erp-record"
          onClose={handleClose}
        >
          <div className="space-y-4 pb-6">{loadingBody}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen space-y-4 p-4 pb-24 md:p-6">
        <div className="space-y-2">
          <Skeleton className="h-8 w-64" />
          <Skeleton className="h-4 w-48" />
        </div>
        {loadingBody}
      </ScreenTemplate>
    );
  }

  if (moveQuery.isError || !move) {
    const errorBody = (
      <div className="space-y-4 rounded-lg border border-destructive/50 bg-destructive/5 p-6 text-card-foreground">
        <p className="text-sm font-medium text-destructive">
          {getApiErrorMessage(moveQuery.error) ?? "Couldn't load this stock movement."}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => void moveQuery.refetch()}>
            Try again
          </Button>
          <Button variant="ghost" size="sm" onClick={handleClose}>
            <ArrowLeft className="mr-1.5 h-4 w-4" />
            Back to stock ledger
          </Button>
        </div>
      </div>
    );

    if (presentation === 'sheet') {
      return (
        <RecordSheet
          title="Stock movement"
          description="Error loading record"
          size="xl"
          className="erp-record"
          onClose={handleClose}
        >
          <div className="space-y-4 pb-6">{errorBody}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen space-y-4 p-4 pb-24 md:p-6">
        <PageHeader
          title="Stock movement"
          description="Error loading record"
          breadcrumbs={[{ label: 'Stock Ledger', to: `/stock-moves${location.search}` }, { label: 'Error' }]}
        />
        {errorBody}
      </ScreenTemplate>
    );
  }

  const detailBody = (
    <DetailPageLayout>
      <DetailSection title="Movement">
        <DetailField label="Item">
          {move.item ? `${move.item.code} — ${move.item.name}` : `Item #${move.item_id}`}
        </DetailField>
        <DetailField label="Moved at">{formatDateTime(move.moved_at)}</DetailField>
        <DetailField label="Source">{`${move.source_type} #${move.source_id}`}</DetailField>
        <DetailField label="From location">{formatLocation(move.from_location)}</DetailField>
        <DetailField label="To location">{formatLocation(move.to_location)}</DetailField>
        <DetailField label="Quantity">
          <span className="tabular-nums font-medium">{move.qty}</span>
        </DetailField>
        {mayViewValuation && move.unit_cost != null && (
          <DetailField label="Unit cost">
            <span className="tabular-nums font-medium">{move.unit_cost}</span>
            {move.fx_rate != null ? ` (fx ${move.fx_rate})` : ''}
          </DetailField>
        )}
        <DetailField label="Batch">{move.batch?.code ?? (move.batch_id ? `#${move.batch_id}` : undefined)}</DetailField>
        <DetailField label="Serial">{move.serial_no || undefined}</DetailField>
      </DetailSection>

      {mayViewValuation && (
        <DetailSection title="Valuation entries" description="The cost impact this movement posted to the ledger." fullWidth>
          <div className="sm:col-span-2 min-w-0 overflow-x-auto rounded-md border border-border">
            <Table className="min-w-[500px]">
              <TableHeader>
                <TableRow>
                  <TableHead>Pool effect</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Unit cost</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">Base amount</TableHead>
                  <TableHead>Account</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(move.valuation_entries ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-16 text-center text-muted-foreground">
                      No valuation entries.
                    </TableCell>
                  </TableRow>
                ) : (
                  (move.valuation_entries ?? []).map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className="font-medium">{entry.pool_effect ?? '—'}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.qty}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.unit_cost}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.amount}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.base_amount}</TableCell>
                      <TableCell className="text-muted-foreground">#{entry.account_id}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </DetailSection>
      )}
    </DetailPageLayout>
  );

  const title = `Stock move #${move.id}`;
  const description = move.item ? `${move.item.code} — ${move.item.name}` : `Item #${move.item_id}`;

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={title}
        description={description}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" asChild>
              <Link to={`/stock-moves/${move.id}`}>
                Open full page
                <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
              </Link>
            </Button>
          </div>
        }
        size="xl"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-6">{detailBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="detail" className="erp-screen space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title={title}
        description={description}
        breadcrumbs={[{ label: 'Stock Ledger', to: `/stock-moves${location.search}` }, { label: `#${move.id}` }]}
        actions={
          <Button variant="outline" size="sm" asChild>
            <Link to={`/stock-moves${location.search}`}>
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Back to stock ledger
            </Link>
          </Button>
        }
      />
      {detailBody}
    </ScreenTemplate>
  );
}
