import type {
  AssembliesMaterialPreview200DataInventory,
  AssembliesMaterialPreview200DataInventoryItemsItem,
  AssembliesMaterialPreview200DataInventoryItemsItemBalancesItem,
  AssembliesMaterialPreview200DataInventoryItemsItemRecommendation,
} from '@erp/core-api';
import { AlertTriangle, Check, Minus } from 'lucide-react';
import { Badge } from '../../components/ui/badge';
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../../components/ui/table';
import { formatDateTime } from '../../lib/format-date';

/** A drawn plan for one item, whichever strategy produced it — the recommendation or an alternative. */
type Plan = NonNullable<AssembliesMaterialPreview200DataInventoryItemsItemRecommendation>;

interface InventoryProposalPanelProps {
  inventory: AssembliesMaterialPreview200DataInventory;
  /** Whether the caller holds `estimating.internal_cost.view`; without it every cost field arrives null. */
  mayViewCost: boolean;
}

const STRATEGY_SOURCE_TEXT: Record<string, string> = {
  REQUEST_OVERRIDE: 'chosen for this preview',
  ITEM: 'set for this item',
  ITEM_CATEGORY: "set for this item's category",
  COMPANY: 'the company setting',
  BUILT_IN_DEFAULT: 'the built-in default',
};

/**
 * The "what could we use from our own stock" half of the material preview
 * (ADR 0058). Everything here is advisory — the panel says so and never
 * offers to reserve. Cost columns appear only for a caller who may see cost;
 * for anyone else a null cost is hidden evidence, not a zero.
 */
export function InventoryProposalPanel({ inventory, mayViewCost }: InventoryProposalPanelProps) {
  return (
    <section className="space-y-4 rounded-lg border p-4" aria-labelledby="available-stock-heading">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="available-stock-heading" className="font-semibold">Available stock</h2>
          <p className="text-sm text-muted-foreground">
            {inventory.strategy.source === 'REQUEST_OVERRIDE'
              ? `Proposed by ${inventory.strategy.label.toLowerCase()} (chosen for this preview)`
              : 'Each material is proposed by its own selection preference (shown under it)'}
            {' · '}
            {inventory.scope.narrowed ? 'limited to the stock scope you selected' : 'all warehouses and locations'}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge variant="secondary">Quantity only — approximate</Badge>
          <Badge variant="outline">As of {formatDateTime(inventory.as_of)}</Badge>
        </div>
      </div>

      {inventory.warnings.length > 0 && (
        <div className="space-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm" role="status">
          {inventory.warnings.map((warning) => (
            <p key={warning}><AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />{warning}</p>
          ))}
        </div>
      )}

      {inventory.items.length === 0 && <p className="text-sm text-muted-foreground">This Assembly has no material requirement.</p>}
      {inventory.items.map((item) => (
        <ItemStock key={item.item.id} item={item} mayViewCost={mayViewCost} />
      ))}

      <div className="space-y-1 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
        {inventory.limitations.map((limitation) => <p key={limitation}>{limitation}</p>)}
      </div>
    </section>
  );
}

function ItemStock({ item, mayViewCost }: { item: AssembliesMaterialPreview200DataInventoryItemsItem; mayViewCost: boolean }) {
  const unit = item.uom.code;
  const heading = `${item.item.code} — ${item.item.name}`;

  if (!item.is_stocked || item.recommendation === null) {
    return (
      <div className="rounded-md border p-3" role="group" aria-label={heading}>
        <div className="flex flex-wrap justify-between gap-2">
          <div className="font-medium">{heading}</div>
          <div className="font-semibold">Needs {qty(item.required_quantity)} {unit}</div>
        </div>
        <p className="mt-2 text-sm text-muted-foreground">Not a stocked item, so stock availability does not apply.</p>
      </div>
    );
  }

  const short = isPositive(item.shortage_quantity);
  const heldForQa = isPositive(item.held_for_qa_quantity);
  const inUnusableLocations = isPositive(item.unusable_location_quantity);
  const recommendation = item.recommendation;
  // With a what-if plan the stock is drawn for the planned amount, so say so beside the standard.
  const planned = Number(item.required_quantity) !== Number(item.standard_quantity);

  return (
    <div className="space-y-3 rounded-md border p-3" role="group" aria-label={heading}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="font-medium">{heading}</div>
          <div className="text-sm text-muted-foreground">
            Needs {qty(item.required_quantity)} {unit}
            {planned && ` (planned; the standard is ${qty(item.standard_quantity)} ${unit})`}
          </div>
          <div className="text-xs text-muted-foreground">
            Selection: {String(item.strategy.label).toLowerCase()} — {STRATEGY_SOURCE_TEXT[item.strategy.source] ?? item.strategy.source}
          </div>
        </div>
        {short ? (
          <Badge variant="warning">Short by {qty(item.shortage_quantity)} {unit}</Badge>
        ) : (
          <Badge variant="success">Fully covered</Badge>
        )}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Figure label="Usable stock" value={`${qty(item.usable_quantity)} ${unit}`} />
        <Figure label="Covered" value={`${qty(item.covered_quantity)} ${unit}`} />
        <Figure label="Short" value={`${qty(item.shortage_quantity)} ${unit}`} />
        <Figure label="Left after use" value={`${qty(item.remaining_after_use_quantity)} ${unit}`} />
      </div>

      {(heldForQa || inUnusableLocations) && (
        <p className="text-sm text-muted-foreground">
          Not counted:
          {heldForQa ? ` ${qty(item.held_for_qa_quantity)} ${unit} held for QA release` : ''}
          {heldForQa && inUnusableLocations ? ' and' : ''}
          {inUnusableLocations ? ` ${qty(item.unusable_location_quantity)} ${unit} in locations that cannot supply work` : ''}.
        </p>
      )}

      <div>
        <h3 className="mb-1 text-sm font-medium">Recommended draw</h3>
        <PlanTable plan={recommendation} itemCode={item.item.code} unit={unit} mayViewCost={mayViewCost} caption={`Recommended draw of ${item.item.code}`} />
        <CostLine plan={recommendation} mayViewCost={mayViewCost} />
      </div>

      <details className="text-sm">
        <summary className="cursor-pointer font-medium">Why this draw</summary>
        <ul className="mt-2 space-y-1">
          {recommendation.criteria.map((criterion) => (
            <li key={criterion.code} className="flex items-start gap-2">
              {criterion.applied
                ? <Check className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                : <Minus className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />}
              <span>
                <span className="sr-only">{criterion.applied ? 'Applied: ' : 'Not applied: '}</span>
                <span className="font-medium">{criterion.label}</span>
                <span className="text-muted-foreground"> — {criterion.note}</span>
              </span>
            </li>
          ))}
        </ul>
        {recommendation.limitations.map((limitation) => <p key={limitation} className="mt-2 text-muted-foreground">{limitation}</p>)}
      </details>

      <details className="text-sm">
        <summary className="cursor-pointer font-medium">Other strategies</summary>
        <div className="mt-2 space-y-3">
          {item.alternatives.map((alternative) => (
            <div key={alternative.strategy.code}>
              <div className="font-medium">{alternative.strategy.label}</div>
              {alternative.same_as_recommendation ? (
                <p className="text-muted-foreground">Draws the same stock as the recommendation.</p>
              ) : (
                <>
                  <PlanTable plan={alternative.plan} itemCode={item.item.code} unit={unit} mayViewCost={mayViewCost} caption={`${alternative.strategy.label} draw of ${item.item.code}`} />
                  <CostLine plan={alternative.plan} mayViewCost={mayViewCost} />
                </>
              )}
            </div>
          ))}
        </div>
      </details>

      <details className="text-sm">
        <summary className="cursor-pointer font-medium">Stock by location</summary>
        {item.balances.length === 0 ? (
          <p className="mt-2 text-muted-foreground">No stock of this item in the selected scope.</p>
        ) : (
          <Table className="mt-2" scrollRegionLabel={`Stock of ${item.item.code} by location`}>
            <TableCaption className="sr-only">Stock of {item.item.code} by location</TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead>Warehouse</TableHead>
                <TableHead>Location</TableHead>
                <TableHead>Batch</TableHead>
                <TableHead className="text-right">On hand</TableHead>
                <TableHead className="text-right">Held for QA</TableHead>
                <TableHead className="text-right">Usable</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {item.balances.map((balance) => (
                <TableRow key={`${balance.location.id}-${balance.batch?.id ?? 'none'}`}>
                  <TableCell>{balance.warehouse.code}</TableCell>
                  <TableCell>
                    {balance.location.code}
                    {locationNote(balance) && (
                      <span className="ml-2 text-xs text-muted-foreground">({locationNote(balance)})</span>
                    )}
                  </TableCell>
                  <TableCell>{balance.batch?.code ?? '—'}</TableCell>
                  <TableCell className="text-right">{qty(balance.on_hand_quantity)}</TableCell>
                  <TableCell className="text-right">{qty(balance.held_for_qa_quantity)}</TableCell>
                  <TableCell className="text-right">{qty(balance.usable_quantity)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </details>
    </div>
  );
}

function PlanTable({ plan, itemCode, unit, mayViewCost, caption }: { plan: Plan; itemCode: string; unit: string; mayViewCost: boolean; caption: string }) {
  if (plan.lines.length === 0) {
    return <p className="text-sm text-muted-foreground">No usable stock of {itemCode} to draw from.</p>;
  }
  return (
    <Table scrollRegionLabel={caption}>
      <TableCaption className="sr-only">{caption}</TableCaption>
      <TableHeader>
        <TableRow>
          <TableHead>Warehouse</TableHead>
          <TableHead>Location</TableHead>
          <TableHead>Batch</TableHead>
          <TableHead className="text-right">Draw ({unit})</TableHead>
          {mayViewCost && <TableHead className="text-right">Unit cost</TableHead>}
          {mayViewCost && <TableHead className="text-right">Line cost</TableHead>}
        </TableRow>
      </TableHeader>
      <TableBody>
        {plan.lines.map((line) => (
          <TableRow key={`${line.location.id}-${line.batch?.id ?? 'none'}`}>
            <TableCell>{line.warehouse.code}</TableCell>
            <TableCell>{line.location.code}</TableCell>
            <TableCell>{line.batch?.code ?? '—'}</TableCell>
            <TableCell className="text-right font-medium">{qty(line.quantity)}</TableCell>
            {mayViewCost && <TableCell className="text-right">{line.unit_cost ?? 'No cost evidence'}</TableCell>}
            {mayViewCost && <TableCell className="text-right">{line.line_cost ?? '—'}</TableCell>}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function CostLine({ plan, mayViewCost }: { plan: Plan; mayViewCost: boolean }) {
  if (!mayViewCost || plan.lines.length === 0) return null;
  return (
    <p className="mt-1 text-sm text-muted-foreground">
      {plan.estimated_cost !== null
        ? `Estimated internal cost of this draw: ${plan.estimated_cost}`
        : 'Estimated internal cost is unavailable: some of this stock has no defensible cost evidence.'}
    </p>
  );
}

/**
 * Why a location's stock is or is not counted, when that is not the plain default. A location the company
 * marked says so — the mark, not the type, decided — so a planner is never left guessing why a returns bay
 * counts, or an overflow shelf does not.
 */
function locationNote(balance: AssembliesMaterialPreview200DataInventoryItemsItemBalancesItem): string | null {
  if (balance.location_usable_source === 'MARKED') {
    return balance.location_usable ? 'marked by your company as counting' : 'marked by your company as not counting';
  }
  return balance.location_usable ? null : `${balance.location.type.toLowerCase()} — not counted`;
}

function Figure({ label, value }: { label: string; value: string }) {
  return <div><div className="text-xs text-muted-foreground">{label}</div><div className="font-medium">{value}</div></div>;
}

/** Trailing-zero-free quantity text: the API keeps ledger/calculation precision, people read "4", not "4.000000000". */
function qty(value: string | null): string {
  if (value === null) return '—';
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

function isPositive(value: string | null): boolean {
  return value !== null && Number(value) > 0;
}
