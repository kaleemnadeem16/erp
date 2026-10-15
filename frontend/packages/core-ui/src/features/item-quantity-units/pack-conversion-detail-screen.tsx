import { useState, type ReactNode } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useItemQuantityUnitsShow } from '@erp/core-api';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DetailPrintLayout, PrintTrigger, useQuickPrint } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import { isApiError } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import type { Presentation } from '../../lib/presentation';
import { trimQty } from '../package-stock/quantity';
import { PackConversionDeleteDialog, packMeaning, usePackUoms } from './pack-conversion-common';

/** One pack conversion: what a pack unit of an item holds, how it rounds, and when it was set. */
export function PackConversionDetailScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const backToList = () => navigate({ pathname: '/item-quantity-units', search });
  const mayManage = usePermission('inventory.item.manage');
  const query = useItemQuantityUnitsShow(id, { include: 'item,uom' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const uoms = usePackUoms(row ? [row] : []);
  const [deleting, setDeleting] = useState(false);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Pack conversion' });

  function present(content: ReactNode, header?: { title: string; description?: string; actions?: ReactNode }) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={header?.title ?? 'Pack conversion'} description={header?.description} actions={header?.actions} size="md" className="erp-record" onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen pb-24">
        <div className="space-y-4">
          {header && (
            <PageHeader
              title={header.title}
              description={header.description}
              breadcrumbs={[{ label: 'Pack conversions', to: `/item-quantity-units${search}` }, { label: header.title }]}
              actions={header.actions}
            />
          )}
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return present(
      <div className="space-y-4 p-4">
        <Skeleton className="h-9 w-56" />
        <Skeleton className="h-40 w-full" />
      </div>,
    );
  }
  if (query.isError || !row) {
    const denied = isApiError(query.error) && query.error.status === 403;

    return present(
      <div className="space-y-3 p-4" data-testid={denied ? 'pack-denied' : 'pack-error'}>
        <p className={denied ? 'text-sm text-muted-foreground' : 'text-sm text-destructive'}>
          {denied ? "You don't have permission to view this pack conversion." : "Couldn't load this pack conversion."}
        </p>
        <Button variant="outline" size="sm" onClick={backToList}>
          Back to pack conversions
        </Button>
      </div>,
    );
  }

  const itemLabel = row.item ? `${row.item.code} — ${row.item.name}` : `Item #${row.item_id}`;
  const packLabel = row.uom ? `${row.uom.name} (${row.uom.code})` : `Unit #${row.uom_id}`;
  const meaning = packMeaning(row, uoms) ?? '—';
  const actions = (
    <>
      {mayManage && (
        <>
          <Button variant="outline" onClick={() => navigate({ pathname: `/item-quantity-units/${id}/edit`, search })} data-testid="pack-edit">
            Edit
          </Button>
          <Button variant="outline" onClick={() => setDeleting(true)} data-testid="pack-delete">
            Delete
          </Button>
        </>
      )}
      <PrintTrigger onPrint={() => print()} data-testid="pack-print" />
    </>
  );

  return present(
    <div className="space-y-4" data-testid="pack-conversion-detail">
      <DetailPageLayout>
        <DetailSection title="Conversion" fullWidth>
          <DetailField label="Item">{itemLabel}</DetailField>
          <DetailField label="Pack unit">{packLabel}</DetailField>
          <DetailField label="What it means">{meaning}</DetailField>
          <DetailField label="Factor">{trimQty(row.factor)}</DetailField>
          <DetailField label="Rounding precision">{row.quantity_precision ? trimQty(row.quantity_precision) : <span className="text-muted-foreground">Unrestricted</span>}</DetailField>
          <DetailField label="Added">{formatDateTime(row.created_at)}</DetailField>
          <DetailField label="Last changed">{formatDateTime(row.updated_at)}</DetailField>
        </DetailSection>
      </DetailPageLayout>
      <p className="text-sm text-muted-foreground">
        Before the general unit conversion, an item's own pack factor is used. It can cross unit types (a roll of tape is a count, its length is metres). Lines already posted keep the factor they
        used.
      </p>
      <PackConversionDeleteDialog row={row} open={deleting} onClose={() => setDeleting(false)} onDeleted={backToList} />
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout
            title="PACK CONVERSION"
            subtitle={`${itemLabel} — ${packLabel}`}
            printedAt={new Date()}
            sections={[
              {
                title: 'Conversion',
                fields: [
                  { label: 'Item', value: itemLabel },
                  { label: 'Pack unit', value: packLabel },
                  { label: 'What it means', value: meaning },
                  { label: 'Factor', value: trimQty(row.factor) },
                  { label: 'Rounding precision', value: row.quantity_precision ? trimQty(row.quantity_precision) : 'Unrestricted' },
                  { label: 'Added', value: formatDateTime(row.created_at) ?? '—' },
                  { label: 'Last changed', value: formatDateTime(row.updated_at) ?? '—' },
                ],
              },
            ]}
          />
        </div>
      </div>
    </div>,
    { title: row.item ? row.item.name : `Item #${row.item_id}`, description: `${packLabel} — ${meaning}`, actions },
  );
}
