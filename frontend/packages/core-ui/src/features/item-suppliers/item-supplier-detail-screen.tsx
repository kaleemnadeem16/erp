import { ArrowLeft, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { useItemSuppliersShow } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
export function ItemSupplierDetailScreen() {
  const mayManage = usePermission('purchasing.item-supplier.manage');
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useItemSuppliersShow(id, { include: 'item,supplierAccount,purchaseUom' });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Item Supplier' });
  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  if (!row || query.isError)
    return (
      <div className="p-6">
        <Button variant="outline" onClick={() => navigate('/item-suppliers')}>
          <ArrowLeft className="h-4 w-4" />
          Back to item suppliers
        </Button>
      </div>
    );
  const itemLabel = row.item ? `${row.item.code} — ${row.item.name}` : String(row.item_id);
  const supplierLabel = row.supplier_account?.code ?? String(row.supplier_account_id);
  const purchaseUomLabel = row.purchase_uom ? `${row.purchase_uom.name} (${row.purchase_uom.code})` : 'Stock unit';
  const printSections: DetailPrintSection[] = [
    {
      title: 'Relationship',
      fields: [
        { label: 'Item', value: itemLabel },
        { label: 'Supplier', value: supplierLabel },
        { label: 'Supplier item code', value: row.supplier_item_code ?? '' },
        { label: 'Priority', value: row.priority == null ? '' : String(row.priority) },
        { label: 'Status', value: row.active ? 'Active' : 'Inactive' },
      ],
    },
    {
      title: 'Sourcing terms',
      fields: [
        {
          label: 'Lead time override',
          value: row.lead_time_days == null ? 'Supplier default' : `${row.lead_time_days} days`,
        },
        { label: 'Resolved lead time', value: `${row.resolved_lead_time_days} days` },
        { label: 'Minimum order quantity', value: row.minimum_order_qty ?? '' },
        { label: 'Purchase unit', value: purchaseUomLabel },
      ],
    },
  ];
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={row.item?.name ?? `Item ${row.item_id}`}
        description={`Supplier ${row.supplier_account?.code ?? row.supplier_account_id}`}
        breadcrumbs={[{ label: 'Item suppliers', to: '/item-suppliers' }, { label: String(row.id) }]}
        actions={
          <>
            <PrintTrigger onPrint={print} />
            {mayManage && <Button asChild size="sm">
              <Link to={`/item-suppliers/${id}/edit`}>
                <Pencil className="h-4 w-4" />
                Edit
              </Link>
            </Button>}
          </>
        }
      />
      <DetailPageLayout>
        <DetailSection title="Relationship">
          <DetailField label="Item">{itemLabel}</DetailField>
          <DetailField label="Supplier">{supplierLabel}</DetailField>
          <DetailField label="Supplier item code">{row.supplier_item_code}</DetailField>
          <DetailField label="Priority">{row.priority}</DetailField>
          <DetailField label="Status">
            <Badge variant={row.active ? 'success' : 'secondary'}>{row.active ? 'Active' : 'Inactive'}</Badge>
          </DetailField>
        </DetailSection>
        <DetailSection title="Sourcing terms">
          <DetailField label="Lead time override">
            {row.lead_time_days == null ? 'Supplier default' : `${row.lead_time_days} days`}
          </DetailField>
          <DetailField label="Resolved lead time">{row.resolved_lead_time_days} days</DetailField>
          <DetailField label="Minimum order quantity">{row.minimum_order_qty}</DetailField>
          <DetailField label="Purchase unit">{purchaseUomLabel}</DetailField>
        </DetailSection>
      </DetailPageLayout>
      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title="ITEM SUPPLIER" printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </div>
  );
}
