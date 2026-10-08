import { Link } from 'react-router';

import { Button } from '../../components/ui/button';
import { usePermission } from '../../hooks/use-permission';
import { trimQty } from '../package-stock/quantity';
import { EXIT_CONFIG, useRejectedStock, type ExitKind } from './rejected-exit-api';

/**
 * "Return to supplier" / "Scrap" for the rejected stock still on one delivery line. Shown only when the person may prepare that document and
 * there is rejected stock left to take out — otherwise it takes no space (a QA decision on its own moves nothing; ADR 0097).
 */
export function RejectedStockActions({ receiptId, lineId, enabled }: { receiptId: number; lineId: number; enabled: boolean }) {
  return (
    <div className="space-y-2" data-testid="rejected-stock-actions">
      <KindAction kind="SUPPLIER_RETURN" receiptId={receiptId} lineId={lineId} enabled={enabled} />
      <KindAction kind="SCRAP" receiptId={receiptId} lineId={lineId} enabled={enabled} />
    </div>
  );
}

function KindAction({ kind, receiptId, lineId, enabled }: { kind: ExitKind; receiptId: number; lineId: number; enabled: boolean }) {
  const config = EXIT_CONFIG[kind];
  const may = usePermission(config.createPermission);
  const { preview } = useRejectedStock(lineId, kind, enabled && may);
  if (!may || !preview || Number(preview.available ?? 0) <= 0) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed p-2 text-sm">
      <span>
        {trimQty(preview.available)} rejected, still to {kind === 'SCRAP' ? 'scrap' : 'return'}
      </span>
      <Button asChild size="sm" variant="outline">
        <Link to={`${config.basePath}/new?receipt=${receiptId}&line=${lineId}`} data-testid={`start-${kind.toLowerCase()}`}>
          {kind === 'SCRAP' ? 'Scrap…' : 'Return to supplier…'}
        </Link>
      </Button>
    </div>
  );
}
