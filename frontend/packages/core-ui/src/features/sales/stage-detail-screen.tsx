import type { ReactNode } from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useLocation, useNavigate, useParams } from 'react-router';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DetailPrintLayout, PrintTrigger, useQuickPrint } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { StatusBadge } from '../../components/ui/status-badge';
import { usePermission } from '../../hooks/use-permission';
import type { Presentation } from '../../lib/presentation';
import { SALES, useSalesStages } from './sales-common';
import { STAGE_STATUS_LABEL, useStageActions } from './stage-common';

/** One pipeline stage: its place in the order, its code and whether deals can still be moved into it. */
export function StageDetailScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const backToList = () => navigate({ pathname: '/sales-stages', search });
  const mayManage = usePermission(SALES.stage);
  const { stages, query } = useSalesStages();
  const stage = stages.find((candidate) => candidate.id === id);
  const actions = useStageActions(stages, async () => {
    await query.refetch();
  });
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Pipeline stage' });

  function present(content: ReactNode, header?: { title: string; description?: string; actions?: ReactNode }) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={header?.title ?? 'Stage'} description={header?.description} actions={header?.actions} size="md" className="erp-record" onClose={backToList}>
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
              breadcrumbs={[{ label: 'Pipeline stages', to: `/sales-stages${search}` }, { label: header.title }]}
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
        <Skeleton className="h-9 w-48" />
        <Skeleton className="h-32 w-full" />
      </div>,
    );
  }
  if (query.isError || !stage) {
    return present(
      <div className="space-y-3 p-4" data-testid="sales-error">
        <p className="text-sm text-destructive">{query.isError ? "Couldn't load this stage." : "Couldn't find this stage."}</p>
        <Button variant="outline" size="sm" onClick={backToList}>
          Back to pipeline stages
        </Button>
      </div>,
    );
  }

  const position = actions.indexOf(stage) + 1;
  const headerActions = (
    <>
      {mayManage && (
        <>
          <Button variant="outline" onClick={() => navigate({ pathname: `/sales-stages/${id}/edit`, search })} data-testid="stage-edit">
            Rename
          </Button>
          <Button variant="outline" onClick={() => void actions.setStatus(stage, stage.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE')} data-testid={`stage-toggle-${stage.code}`}>
            {stage.status === 'ACTIVE' ? 'Archive' : 'Restore'}
          </Button>
        </>
      )}
      <PrintTrigger onPrint={() => print()} data-testid="stage-print" />
    </>
  );

  return present(
    <div className="space-y-4" data-testid="stage-detail">
      {actions.error && (
        <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="stage-error">
          {actions.error}
        </div>
      )}
      <DetailPageLayout>
        <DetailSection title="Stage" fullWidth>
          <DetailField label="Name">{stage.name}</DetailField>
          <DetailField label="Code">{stage.code}</DetailField>
          <DetailField label="Status">
            <StatusBadge tone={stage.status === 'ACTIVE' ? 'success' : 'draft'}>{STAGE_STATUS_LABEL[stage.status] ?? stage.status}</StatusBadge>
          </DetailField>
          <DetailField label="Place in the order">
            <span className="inline-flex flex-wrap items-center gap-2">
              {position} of {stages.length}
              {mayManage && (
                <>
                  <Button variant="outline" size="sm" className="h-8" disabled={!actions.canMoveUp(stage)} onClick={() => void actions.moveUp(stage)} aria-label={`Move ${stage.name} up`}>
                    <ArrowUp className="h-4 w-4" aria-hidden="true" />
                    Up
                  </Button>
                  <Button variant="outline" size="sm" className="h-8" disabled={!actions.canMoveDown(stage)} onClick={() => void actions.moveDown(stage)} aria-label={`Move ${stage.name} down`}>
                    <ArrowDown className="h-4 w-4" aria-hidden="true" />
                    Down
                  </Button>
                </>
              )}
            </span>
          </DetailField>
          <DetailField label="Sort value">{stage.sort_order}</DetailField>
        </DetailSection>
      </DetailPageLayout>
      <p className="text-sm text-muted-foreground">An archived stage cannot be chosen for a deal, but everything recorded about it keeps the name it had. At least one stage always stays active.</p>
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout
            title="PIPELINE STAGE"
            subtitle={`${stage.name} (${stage.code})`}
            printedAt={new Date()}
            sections={[
              {
                title: 'Stage',
                fields: [
                  { label: 'Name', value: stage.name },
                  { label: 'Code', value: stage.code },
                  { label: 'Status', value: STAGE_STATUS_LABEL[stage.status] ?? stage.status },
                  { label: 'Place in the order', value: `${position} of ${stages.length}` },
                  { label: 'Sort value', value: String(stage.sort_order) },
                ],
              },
            ]}
          />
        </div>
      </div>
    </div>,
    { title: stage.name, description: stage.code, actions: headerActions },
  );
}
