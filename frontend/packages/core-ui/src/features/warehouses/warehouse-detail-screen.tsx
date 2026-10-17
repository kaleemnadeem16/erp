import { useState } from 'react';
import { Archive, ArrowLeft, Pencil } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import {
  useInventorySettingsShow,
  useWarehousesDestroy,
  useWarehousesShow,
} from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { useAuthStore } from '../../stores/auth-store';
import { LocationsPanel } from './locations-panel';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { formatDate } from '../../lib/format-date';
import { getApiErrorMessage } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';

export interface WarehouseDetailScreenProps {
  presentation?: Presentation;
}

export function WarehouseDetailScreen({ presentation = 'page' }: WarehouseDetailScreenProps) {
  const warehouseId = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const { search } = useLocation();

  const [archiving, setArchiving] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  const warehouseQuery = useWarehousesShow(warehouseId, {
    include: 'locations',
  });
  const warehouse = warehouseQuery.data?.status === 200 ? warehouseQuery.data.data.data : undefined;
  const destroyMutation = useWarehousesDestroy();

  const settingsQuery = useInventorySettingsShow();
  const structured =
    settingsQuery.data?.status === 200 &&
    settingsQuery.data.data.data.inventory_location_mode === 'STRUCTURED';

  const user = useAuthStore((state) => state.user);
  const permissions = Array.isArray(user?.permissions) ? user.permissions : [];
  const mayManageLocations = permissions.includes('inventory.location.manage');
  const mayManageWarehouse = permissions.includes('inventory.warehouse.manage');
  const mayMarkUsable = permissions.includes('inventory.location-policy.manage');

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: warehouse ? `Warehouse ${warehouse.code}` : 'Warehouse',
  });

  const handleClose = () => {
    navigate({ pathname: '/warehouses', search });
  };

  const handleArchive = async () => {
    if (!warehouse) return;
    try {
      await destroyMutation.mutateAsync({ warehouse: warehouse.id });
      setArchiving(false);
      handleClose();
    } catch (err) {
      setArchiveError(getApiErrorMessage(err));
    }
  };

  if (warehouseQuery.isLoading) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Warehouse" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 p-4">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-48 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4 p-6">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-48 w-full" />
        </div>
      </ScreenTemplate>
    );
  }

  if (warehouseQuery.isError || !warehouse) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Warehouse not found" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 p-4">
            <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to warehouses
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4 p-6">
          <p className="text-sm text-destructive">Couldn't load this warehouse.</p>
          <Button variant="outline" onClick={handleClose}>
            <ArrowLeft className="h-4 w-4 mr-1.5" />
            Back to warehouses
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const locationsCount = Number(warehouse.locations_count ?? warehouse.locations?.length ?? 0);

  const printSections: DetailPrintSection[] = [
    {
      title: 'General',
      fields: [
        { label: 'Code', value: warehouse.code },
        { label: 'Name', value: warehouse.name },
        { label: 'Status', value: warehouse.active ? 'Active' : 'Inactive' },
        { label: 'Default warehouse', value: warehouse.is_default ? 'Yes' : 'No' },
        { label: 'Locations count', value: String(locationsCount) },
      ],
    },
    {
      title: 'Locations',
      fields: [
        {
          label: 'Locations',
          value: (warehouse.locations ?? []).map((loc) => `${loc.code} — ${loc.name}`).join(', '),
        },
      ],
    },
  ];

  const headerActions = (
    <>
      <PrintTrigger onPrint={() => print()} />
      {mayManageWarehouse && (
        <>
          <Button asChild size="sm">
            <Link to={{ pathname: `/warehouses/${warehouse.id}/edit`, search }}>
              <Pencil className="h-4 w-4 mr-1.5" />
              Edit
            </Link>
          </Button>
          {warehouse.active && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setArchiveError(null);
                setArchiving(true);
              }}
            >
              <Archive className="h-4 w-4 mr-1.5" />
              Archive
            </Button>
          )}
        </>
      )}
    </>
  );

  const detailBody = (
    <div className="space-y-4">
      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{warehouse.code}</DetailField>
          <DetailField label="Name">{warehouse.name}</DetailField>
          <DetailField label="Status">
            <Badge variant={warehouse.active ? 'success' : 'secondary'}>
              {warehouse.active ? 'Active' : 'Inactive'}
            </Badge>
          </DetailField>
          <DetailField label="Default warehouse">
            {warehouse.is_default ? 'Yes (Primary warehouse)' : 'No'}
          </DetailField>
          <DetailField label="Locations count">
            {locationsCount > 0 ? (
              <Badge variant="secondary" className="font-normal text-xs">
                {locationsCount} {locationsCount === 1 ? 'location' : 'locations'}
              </Badge>
            ) : (
              <span className="text-muted-foreground text-xs">0 locations</span>
            )}
          </DetailField>
          <DetailField label="Created">{formatDate(warehouse.created_at)}</DetailField>
          <DetailField label="Last updated">{formatDate(warehouse.updated_at)}</DetailField>
        </DetailSection>

        <LocationsPanel
          warehouseId={warehouse.id}
          structured={structured}
          mayManage={mayManageLocations}
          mayMarkUsable={mayMarkUsable}
        />

        <DetailSection title="Inventory operations" fullWidth>
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm">
            <p className="text-muted-foreground">
              Stock levels, movements and physical counts can be filtered specifically for <strong>{warehouse.name}</strong>.
            </p>
            <Button variant="outline" size="sm" asChild>
              <Link to={{ pathname: '/physical-counts', search: `?filter[warehouse_id]=${warehouse.id}` }}>
                View physical counts in this warehouse →
              </Link>
            </Button>
          </div>
        </DetailSection>
      </DetailPageLayout>

      <Dialog open={archiving} onOpenChange={setArchiving}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Archive warehouse</DialogTitle>
            <DialogDescription>
              Are you sure you want to archive warehouse <strong>{warehouse.name}</strong> ({warehouse.code})?
              All of its locations will be deactivated, and it cannot receive or move stock while inactive.
            </DialogDescription>
          </DialogHeader>
          {archiveError && (
            <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive font-medium">
              {archiveError}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setArchiving(false)} disabled={destroyMutation.isPending}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleArchive} disabled={destroyMutation.isPending}>
              {destroyMutation.isPending ? 'Archiving…' : 'Archive warehouse'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout title={`WAREHOUSE: ${warehouse.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </div>
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={warehouse.name}
        description={warehouse.code}
        actions={headerActions}
        size="lg"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{detailBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="detail" className="erp-screen">
      <div className="space-y-4 p-6">
        <PageHeader
          title={warehouse.name}
          description={warehouse.code}
          breadcrumbs={[{ label: 'Warehouses', to: `/warehouses${search}` }, { label: warehouse.code }]}
          backTo={`/warehouses${search}`}
          actions={headerActions}
        />
        {detailBody}
      </div>
    </ScreenTemplate>
  );
}
