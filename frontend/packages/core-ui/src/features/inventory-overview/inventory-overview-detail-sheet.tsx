import { ArrowUpRight, History, Layers, Package, ShieldCheck } from 'lucide-react';
import { Link } from 'react-router';
import type { InventoryOverviewResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { RecordSheet } from '../../components/page/record-sheet';
import { formatDecimal } from '../../lib/decimal';

export interface InventoryOverviewDetailSheetProps {
  item: InventoryOverviewResource | null;
  open: boolean;
  onClose: () => void;
  uomLabel?: string;
  mayViewValuation?: boolean;
}

function formatMoney(value: string | undefined): string {
  if (!value) return '—';
  return formatDecimal(value, { minFraction: 2, maxFraction: 4 });
}

export function InventoryOverviewDetailSheet({
  item,
  open,
  onClose,
  uomLabel,
  mayViewValuation = false,
}: InventoryOverviewDetailSheetProps) {
  if (!open || !item) return null;

  const uom = uomLabel ?? `#${item.stock_uom_id}`;
  const tracking = (item as { tracking?: string }).tracking ?? 'NONE';
  const description = (item as { description?: string | null }).description;

  const hasShortage = Number(item.shortage_qty) > 0;
  const hasQaHeld = Number(item.qa_held_qty) > 0;
  const hasOnOrder = Number(item.on_order_qty) > 0;

  return (
    <RecordSheet
      title={item.code}
      description={item.name}
      onClose={onClose}
      size="lg"
      dismissOnOutsideClick
    >
      <div className="space-y-6 py-2">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline" className="capitalize text-xs">
            {item.type.toLowerCase().replace('_', ' ')}
          </Badge>
          {item.category && (
            <Badge variant="secondary" className="text-xs">
              {item.category.name}
            </Badge>
          )}
        </div>
        {/* Quantity and Value Metric Grid */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">On hand</div>
            <div className="mt-1 text-lg font-semibold tabular-nums">
              {item.on_hand_qty}{' '}
              <span className="text-xs font-normal text-muted-foreground">{uom}</span>
            </div>
            {mayViewValuation && item.on_hand_value != null && (
              <div className="mt-0.5 text-xs text-status-info tabular-nums">
                Val: {formatMoney(item.on_hand_value)}
              </div>
            )}
          </div>

          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">Available</div>
            <div className="mt-1 text-lg font-semibold tabular-nums text-status-success">
              {item.available_qty}{' '}
              <span className="text-xs font-normal text-muted-foreground">{uom}</span>
            </div>
            {mayViewValuation && item.available_value != null && (
              <div className="mt-0.5 text-xs text-status-success tabular-nums">
                Val: {formatMoney(item.available_value)}
              </div>
            )}
          </div>

          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">QA held</div>
            <div className="mt-1 flex items-baseline gap-1">
              {hasQaHeld ? (
                <Badge variant="warning" className="text-sm font-semibold">
                  {item.qa_held_qty}
                </Badge>
              ) : (
                <span className="text-lg font-semibold tabular-nums text-muted-foreground">0.0000</span>
              )}
              <span className="text-xs text-muted-foreground">{uom}</span>
            </div>
            <div className="mt-0.5 text-[11px] text-muted-foreground">
              {hasQaHeld ? 'Pending inspection' : 'Clear'}
            </div>
          </div>

          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">On order</div>
            <div className="mt-1 flex items-baseline gap-1">
              {hasOnOrder ? (
                <Badge variant="info" className="text-sm font-semibold">
                  {item.on_order_qty}
                </Badge>
              ) : (
                <span className="text-lg font-semibold tabular-nums text-muted-foreground">0.0000</span>
              )}
              <span className="text-xs text-muted-foreground">{uom}</span>
            </div>
            <div className="mt-0.5 text-[11px] text-muted-foreground">
              {hasOnOrder ? 'Issued PO balance' : 'None open'}
            </div>
          </div>

          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">Shortage</div>
            <div className="mt-1 flex items-baseline gap-1">
              {hasShortage ? (
                <Badge variant="destructive" className="text-sm font-semibold">
                  {item.shortage_qty}
                </Badge>
              ) : (
                <span className="text-lg font-semibold tabular-nums text-muted-foreground">0.0000</span>
              )}
              <span className="text-xs text-muted-foreground">{uom}</span>
            </div>
            <div className="mt-0.5 text-[11px] text-muted-foreground">
              {hasShortage ? 'Unsettled negative' : 'No shortage'}
            </div>
          </div>

          <div className="rounded-lg border border-border bg-card p-3">
            <div className="text-xs text-muted-foreground">Valuation method</div>
            <div className="mt-1 text-base font-medium">{item.valuation_method}</div>
            <div className="mt-0.5 text-[11px] text-muted-foreground">
              Tracking: {tracking.toLowerCase()}
            </div>
          </div>
        </div>

        {/* Item Specifications */}
        <div className="rounded-lg border border-border p-4">
          <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Item Specifications
          </h4>
          <dl className="grid grid-cols-1 gap-x-4 gap-y-2.5 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs text-muted-foreground">Code</dt>
              <dd className="font-mono font-medium">{item.code}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Name</dt>
              <dd className="font-medium">{item.name}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Category</dt>
              <dd>{item.category?.name ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Stock UoM</dt>
              <dd>{uom}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Valuation</dt>
              <dd>{item.valuation_method}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Tracking</dt>
              <dd className="capitalize">{tracking.toLowerCase()}</dd>
            </div>
            {description && (
              <div className="sm:col-span-2">
                <dt className="text-xs text-muted-foreground">Description</dt>
                <dd className="text-muted-foreground">{description}</dd>
              </div>
            )}
          </dl>
        </div>

        {/* Operational Context note */}
        <div className="rounded-lg border border-border/70 bg-muted/30 p-3.5 text-xs text-muted-foreground">
          <p className="font-medium text-foreground">Operational derivation note</p>
          <p className="mt-1">
            Available stock is computed as on-hand physical inventory minus quantities held in QA awaiting disposition.
            On-order figures reflect outstanding balances on issued purchase orders. Shortages represent active, unsettled negative inventory.
          </p>
        </div>

        {/* Navigation / Actions */}
        <div className="space-y-2">
          <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Related Operational Records
          </h4>

          <div className="flex flex-col gap-2">
            <Button variant="outline" className="w-full justify-between" asChild>
              <Link to={`/stock-moves?filter.item_id=${item.item_id}`}>
                <span className="flex items-center gap-2">
                  <History className="h-4 w-4 text-primary" />
                  View Stock Ledger
                </span>
                <ArrowUpRight className="h-4 w-4 text-muted-foreground" />
              </Link>
            </Button>

            <Button variant="outline" className="w-full justify-between" asChild>
              <Link to={`/items/${item.item_id}`}>
                <span className="flex items-center gap-2">
                  <Package className="h-4 w-4 text-primary" />
                  View Item Master Record
                </span>
                <ArrowUpRight className="h-4 w-4 text-muted-foreground" />
              </Link>
            </Button>

            <Button variant="outline" className="w-full justify-between" asChild>
              <Link to={`/physical-counts?filter[item_id]=${item.item_id}`}>
                <span className="flex items-center gap-2">
                  <ShieldCheck className="h-4 w-4 text-primary" />
                  View Physical Counts
                </span>
                <ArrowUpRight className="h-4 w-4 text-muted-foreground" />
              </Link>
            </Button>
          </div>
        </div>
      </div>
    </RecordSheet>
  );
}
