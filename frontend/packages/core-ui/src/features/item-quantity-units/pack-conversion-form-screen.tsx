import type { ReactNode } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useItemQuantityUnitsShow } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import type { Presentation } from '../../lib/presentation';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { PackConversionEditor } from './pack-conversion-common';

/** Add a pack conversion, or change the factor and rounding of one (`/item-quantity-units/:id/edit`). */
export function PackConversionFormScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const editingId = Number(useParams<{ id: string }>().id) || undefined;
  const editing = editingId !== undefined;
  const mayManage = usePermission('inventory.item.manage');
  const existing = useItemQuantityUnitsShow(editingId ?? 0, { include: 'item,uom' }, { query: { enabled: editing } });
  const row = existing.data?.status === 200 ? existing.data.data.data : undefined;
  const backToList = () => navigate({ pathname: '/item-quantity-units', search });

  function present(content: ReactNode) {
    const title = editing ? 'Edit pack conversion' : 'New pack conversion';
    const description = editing ? 'Only the factor and rounding change; the item and pack unit are fixed.' : 'How many of an item’s own stock unit one pack unit holds.';
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={title} description={description} size="md" className="erp-record" dismissOnOutsideClick={false} onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="form" className="erp-screen pb-24">
        <div className="space-y-4">
          <PageHeader title={title} description={description} breadcrumbs={[{ label: 'Pack conversions', to: `/item-quantity-units${search}` }, { label: editing ? 'Edit' : 'New' }]} />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (!mayManage) {
    return present(
      <p className="text-sm text-muted-foreground" data-testid="pack-denied">
        You don't have permission to maintain pack conversions.
      </p>,
    );
  }
  if (editing && existing.isLoading) return present(<Skeleton className="h-40 w-full" />);
  if (editing && !row) return present(<p className="text-sm text-muted-foreground">Couldn't load this pack conversion.</p>);

  return present(
    <DetailSection title="The conversion">
      <div className="sm:col-span-2">
        <PackConversionEditor editing={row ?? null} onCancel={backToList} onSaved={(saved) => navigate({ pathname: `/item-quantity-units/${saved.id}`, search })} />
      </div>
    </DetailSection>,
  );
}
