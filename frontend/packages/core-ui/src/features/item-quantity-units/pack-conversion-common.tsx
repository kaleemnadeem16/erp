import { useState } from 'react';
import { useItemQuantityUnitsDestroy, useItemQuantityUnitsStore, useItemQuantityUnitsUpdate, useUomsIndex, type ItemQuantityUnitResource, type UomResource } from '@erp/core-api';

import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { useUomsById } from '../../lib/use-records-by-id';
import { trimQty } from '../package-stock/quantity';

export type PackRow = ItemQuantityUnitResource;

const DECIMAL = /^\d{1,10}(\.\d{1,9})?$/;

/** "1 box = 12 each": what a pack conversion means in the words a buyer uses, from the pack unit and the item's own stock unit. */
export function packMeaning(row: PackRow, uoms: UomResource[]): string | null {
  const pack = row.uom ?? uoms.find((candidate) => candidate.id === row.uom_id);
  const stock = row.item ? uoms.find((candidate) => candidate.id === row.item?.stock_uom_id) : undefined;
  if (!pack || !stock) return null;

  return `1 ${pack.code} = ${trimQty(row.factor)} ${stock.code}`;
}

/** The units a set of conversions refers to, resolved by id so none is missing past a page of units. */
export function usePackUoms(rows: PackRow[]): UomResource[] {
  const ids = rows.flatMap((row) => [row.uom_id, ...(row.item ? [row.item.stock_uom_id] : [])]);

  return useUomsById<UomResource>(
    ids,
    rows.flatMap((row) => (row.uom ? [row.uom] : [])),
  );
}

/**
 * Add a pack conversion, or change the factor and rounding of one. The item and pack unit are its identity — fixed once created (delete and re-add to change either) —
 * so editing shows them as plain text. Shown in a side panel on the Pack conversions screen and in a dialog on an item.
 */
export function PackConversionEditor({
  itemId,
  stockUnit,
  editing,
  onSaved,
  onCancel,
}: {
  itemId?: number;
  stockUnit?: string;
  editing?: PackRow | null;
  onSaved: (row: PackRow) => void;
  onCancel: () => void;
}) {
  const store = useItemQuantityUnitsStore();
  const update = useItemQuantityUnitsUpdate();
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const [item, setItem] = useState(editing ? String(editing.item_id) : itemId ? String(itemId) : '');
  const [uom, setUom] = useState(editing ? String(editing.uom_id) : '');
  const [factor, setFactor] = useState(editing?.factor ?? '');
  const [precision, setPrecision] = useState(editing?.quantity_precision ?? '');
  const [error, setError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const pending = store.isPending || update.isPending;

  const factorProblem = !DECIMAL.test(factor.trim()) || Number(factor) <= 0 ? 'Enter a factor greater than 0 (up to 9 decimal places).' : null;
  const precisionProblem = precision.trim() !== '' && (!DECIMAL.test(precision.trim()) || Number(precision) <= 0) ? 'Enter a positive precision, or leave it empty.' : null;
  const itemMissing = item === '';
  const uomMissing = uom === '';

  async function save() {
    setShowErrors(true);
    setError(null);
    if (factorProblem || precisionProblem || (!editing && (itemMissing || uomMissing))) return;
    const data = { factor: factor.trim(), quantity_precision: precision.trim() || null };
    try {
      if (editing) {
        const saved = await update.mutateAsync({ itemQuantityUnit: editing.id, data });
        if (saved.status === 200) onSaved(saved.data.data);
      } else {
        const created = await store.mutateAsync({ data: { item_id: Number(item), uom_id: Number(uom), ...data } });
        if (created.status === 201) onSaved(created.data.data);
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  return (
    <div className="space-y-4" data-testid="pack-conversion-form">
      {error && (
        <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        {!itemId && (
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="pack-item">Item</Label>
            {editing ? (
              <p className="text-sm">{editing.item ? `${editing.item.code} — ${editing.item.name}` : `Item #${editing.item_id}`}</p>
            ) : (
              <ItemCombobox id="pack-item" label="Item" placeholder="Select item" className="w-full" value={item} onValueChange={setItem} />
            )}
            {showErrors && itemMissing && !editing && <p className="text-xs text-destructive">Choose the item.</p>}
          </div>
        )}
        <div className="space-y-1.5">
          <Label htmlFor="pack-uom">Pack unit</Label>
          {editing ? (
            <p className="text-sm">{editing.uom ? `${editing.uom.name} (${editing.uom.code})` : `Unit #${editing.uom_id}`}</p>
          ) : (
            <Combobox
              {...lookupState(uomsResult, 'units')}
              id="pack-uom"
              value={uom}
              onValueChange={setUom}
              options={uoms.map((unit) => ({ value: String(unit.id), label: `${unit.name} (${unit.code})` }))}
              placeholder="Select unit"
              searchPlaceholder="Search units…"
              emptyText="No units found."
            />
          )}
          {showErrors && uomMissing && !editing && <p className="text-xs text-destructive">Choose the pack unit.</p>}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="pack-factor">Factor</Label>
          <Input id="pack-factor" inputMode="decimal" placeholder="e.g. 12" value={factor} onChange={(event) => setFactor(event.target.value)} aria-invalid={showErrors && Boolean(factorProblem)} />
          {showErrors && factorProblem && <p className="text-xs text-destructive">{factorProblem}</p>}
          {stockUnit && <p className="text-xs text-muted-foreground">1 pack unit = factor × {stockUnit}.</p>}
        </div>
        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="pack-precision">Rounding precision (optional)</Label>
          <Input
            id="pack-precision"
            inputMode="decimal"
            placeholder="Unrestricted"
            value={precision}
            onChange={(event) => setPrecision(event.target.value)}
            aria-invalid={showErrors && Boolean(precisionProblem)}
          />
          {showErrors && precisionProblem && <p className="text-xs text-destructive">{precisionProblem}</p>}
          <p className="text-xs text-muted-foreground">The smallest step a quantity in this pack unit can take. Empty means any fraction is allowed.</p>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button onClick={() => void save()} disabled={pending} data-testid="pack-save">
          {pending ? 'Saving…' : editing ? 'Save changes' : 'Add conversion'}
        </Button>
      </div>
    </div>
  );
}

/** Deleting is final for the conversion but not for history: a line snapshots the factor it used when it was created. */
export function PackConversionDeleteDialog({ row, open, onClose, onDeleted }: { row: PackRow | null; open: boolean; onClose: () => void; onDeleted: () => void }) {
  const destroy = useItemQuantityUnitsDestroy();
  const [error, setError] = useState<string | null>(null);

  async function remove() {
    if (!row) return;
    setError(null);
    try {
      await destroy.mutateAsync({ itemQuantityUnit: row.id });
      onDeleted();
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="pack-delete-dialog">
        <DialogHeader>
          <DialogTitle>Delete this pack conversion?</DialogTitle>
          <DialogDescription>
            {row?.item ? `${row.item.code} — ${row.item.name}` : 'This item'}, {row?.uom ? row.uom.name : 'this pack unit'}. Orders and receipts already made keep the factor they used; new lines for
            this unit will no longer convert until it is added again.
          </DialogDescription>
        </DialogHeader>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Keep it
          </Button>
          <Button variant="destructive" onClick={() => void remove()} disabled={destroy.isPending} data-testid="pack-delete-confirm">
            Delete conversion
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
