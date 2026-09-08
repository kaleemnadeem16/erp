import { useParams, useNavigate, Link } from 'react-router';
import { Pencil, ArrowLeft } from 'lucide-react';
import { useItemsShow, useUomsShow } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintField } from '../../components/print';

export function ItemDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const itemId = Number(params.id);

  const itemQuery = useItemsShow(itemId, { include: 'category' });
  const item = itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;

  const uomQuery = useUomsShow(item?.stock_uom_id ?? 0, { query: { enabled: !!item } });
  const uom = uomQuery.data?.status === 200 ? uomQuery.data.data.data : undefined;

  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: item ? `Item ${item.code}` : 'Item' });

  if (itemQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (itemQuery.isError || !item) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this item.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/items')}>
          <ArrowLeft className="h-4 w-4" />
          Back to items
        </Button>
      </div>
    );
  }

  const printFields: DetailPrintField[] = [
    { label: 'Code', value: item.code },
    { label: 'Name', value: item.name },
    { label: 'Type', value: item.type },
    { label: 'Category', value: item.category?.name ?? '' },
    { label: 'Status', value: item.status },
    { label: 'Stock unit of measure', value: uom ? `${uom.name} (${uom.code})` : '' },
    { label: 'Tracking', value: item.tracking },
    { label: 'Valuation method', value: item.valuation_method },
    { label: 'AVCO pool scope', value: item.avco_pool_scope ?? '' },
    { label: 'Stocked', value: item.is_stocked ? 'Yes' : 'No' },
    { label: 'Purchasable', value: item.is_purchasable ? 'Yes' : 'No' },
    { label: 'Sellable', value: item.is_sellable ? 'Yes' : 'No' },
    { label: 'Description', value: item.description ?? '' },
  ];

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={item.name}
        description={item.code}
        breadcrumbs={[{ label: 'Items', to: '/items' }, { label: item.code }]}
        actions={
          <>
            <PrintTrigger onPrint={() => print()} />
            <Button asChild size="sm">
              <Link to={`/items/${item.id}/edit`}>
                <Pencil className="h-4 w-4" />
                Edit
              </Link>
            </Button>
          </>
        }
      />

      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{item.code}</DetailField>
          <DetailField label="Name">{item.name}</DetailField>
          <DetailField label="Type">
            <span className="capitalize">{item.type.toLowerCase().replace('_', ' ')}</span>
          </DetailField>
          <DetailField label="Category">{item.category?.name}</DetailField>
          <DetailField label="Status">
            <Badge variant={item.status === 'ACTIVE' ? 'success' : 'secondary'}>{item.status}</Badge>
          </DetailField>
          <DetailField label="Description" fullWidth>
            {item.description}
          </DetailField>
        </DetailSection>

        <DetailSection title="Stock &amp; valuation">
          <DetailField label="Stock unit of measure">{uom ? `${uom.name} (${uom.code})` : undefined}</DetailField>
          <DetailField label="Tracking">{item.tracking}</DetailField>
          <DetailField label="Valuation method">{item.valuation_method}</DetailField>
          <DetailField label="AVCO pool scope">{item.avco_pool_scope}</DetailField>
          <DetailField label="Stocked">{item.is_stocked ? 'Yes' : 'No'}</DetailField>
          <DetailField label="Purchasable">{item.is_purchasable ? 'Yes' : 'No'}</DetailField>
          <DetailField label="Sellable">{item.is_sellable ? 'Yes' : 'No'}</DetailField>
        </DetailSection>
      </DetailPageLayout>

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout title={`ITEM: ${item.code}`} printedAt={new Date()} fields={printFields} />
        </div>
      </div>
    </div>
  );
}
