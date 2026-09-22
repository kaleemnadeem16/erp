import { useParams, useNavigate } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { useStockMovesShow } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { formatDateTime } from '../../lib/format-date';

export function StockMoveDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const moveId = Number(params.id);

  const moveQuery = useStockMovesShow(moveId, { include: 'item,from_location,to_location,batch,valuation_entries' });
  const move = moveQuery.data?.status === 200 ? moveQuery.data.data.data : undefined;

  if (moveQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (moveQuery.isError || !move) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this stock movement.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/stock-moves')}>
          <ArrowLeft className="h-4 w-4" />
          Back to the stock ledger
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Stock move #${move.id}`}
        description={move.item ? `${move.item.code} — ${move.item.name}` : `Item #${move.item_id}`}
        breadcrumbs={[{ label: 'Stock Ledger', to: '/stock-moves' }, { label: `#${move.id}` }]}
      />

      <DetailPageLayout>
        <DetailSection title="Movement">
          <DetailField label="Moved at">{formatDateTime(move.moved_at)}</DetailField>
          <DetailField label="Source">{`${move.source_type} #${move.source_id}`}</DetailField>
          <DetailField label="From location">{move.from_location?.code}</DetailField>
          <DetailField label="To location">{move.to_location?.code}</DetailField>
          <DetailField label="Quantity">{move.qty}</DetailField>
          <DetailField label="Unit cost">{`${move.unit_cost} (fx ${move.fx_rate})`}</DetailField>
          <DetailField label="Batch">{move.batch?.code}</DetailField>
          <DetailField label="Serial">{move.serial_no}</DetailField>
        </DetailSection>

        <DetailSection title="Valuation entries" description="The cost impact this movement posted to the ledger." fullWidth>
          <div className="sm:col-span-2 overflow-x-auto rounded-md border border-border">
            <Table>
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
                      <TableCell>{entry.pool_effect ?? '—'}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.qty}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.unit_cost}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.amount}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.base_amount}</TableCell>
                      <TableCell>#{entry.account_id}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
