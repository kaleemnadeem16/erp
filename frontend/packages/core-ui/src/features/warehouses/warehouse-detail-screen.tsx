import { ArrowLeft, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { useInventorySettingsShow, useWarehousesShow } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { useAuthStore } from '../../stores/auth-store';
import { LocationsPanel } from './locations-panel';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';

export function WarehouseDetailScreen() {
  const warehouseId = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const warehouseQuery = useWarehousesShow(warehouseId, {
    include: 'locations',
  });
  const warehouse = warehouseQuery.data?.status === 200 ? warehouseQuery.data.data.data : undefined;
  const settingsQuery = useInventorySettingsShow();
  const structured = settingsQuery.data?.status === 200 && settingsQuery.data.data.data.inventory_location_mode === 'STRUCTURED';
  const user = useAuthStore((state) => state.user);
  const permissions = Array.isArray(user?.permissions) ? user.permissions : [];
  const mayManageLocations = permissions.includes('inventory.location.manage');
  const mayManageWarehouse = permissions.includes('inventory.warehouse.manage');
  const mayMarkUsable = permissions.includes('inventory.location-policy.manage');
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: warehouse ? `Warehouse ${warehouse.code}` : 'Warehouse',
  });

  if (warehouseQuery.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  if (warehouseQuery.isError || !warehouse)
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
        <Button variant="outline" onClick={() => navigate('/warehouses')}>
          <ArrowLeft className="h-4 w-4" />
          Back to warehouses
        </Button>
      </div>
    );

  const printSections: DetailPrintSection[] = [
    {
      title: 'General',
      fields: [
        { label: 'Code', value: warehouse.code },
        { label: 'Name', value: warehouse.name },
        { label: 'Status', value: warehouse.active ? 'Active' : 'Inactive' },
        { label: 'Default warehouse', value: warehouse.is_default ? 'Yes' : 'No' },
      ],
    },
    {
      title: 'Locations',
      fields: [
        {
          label: 'Locations',
          value: (warehouse.locations ?? []).map((location) => `${location.code} — ${location.name}`).join(', '),
        },
      ],
    },
  ];

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={warehouse.name}
        description={warehouse.code}
        breadcrumbs={[{ label: 'Warehouses', to: '/warehouses' }, { label: warehouse.code }]}
        actions={
          <>
            <PrintTrigger onPrint={() => print()} />
            {mayManageWarehouse && (
            <Button asChild size="sm">
              <Link to={`/warehouses/${warehouse.id}/edit`}>
                <Pencil className="h-4 w-4" />
                Edit
              </Link>
            </Button>
            )}
          </>
        }
      />
      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{warehouse.code}</DetailField>
          <DetailField label="Name">{warehouse.name}</DetailField>
          <DetailField label="Status">
            <Badge variant={warehouse.active ? 'success' : 'secondary'}>
              {warehouse.active ? 'Active' : 'Inactive'}
            </Badge>
          </DetailField>
          <DetailField label="Default warehouse">{warehouse.is_default ? 'Yes' : 'No'}</DetailField>
        </DetailSection>
        <LocationsPanel warehouseId={warehouse.id} structured={structured} mayManage={mayManageLocations} mayMarkUsable={mayMarkUsable} />
      </DetailPageLayout>
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout title={`WAREHOUSE: ${warehouse.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </div>
  );
}
