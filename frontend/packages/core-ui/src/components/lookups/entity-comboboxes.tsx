import { useEffect, useState } from 'react';
import {
  useItemsIndex,
  useItemsShow,
  useLocationsIndex,
  useLocationsShow,
  useSupplierAccountsIndex,
  useSupplierAccountsShow,
  type ItemResource,
  type LocationResource,
  type SupplierAccountResource,
} from '@erp/core-api';

import { lookupState } from '../../lib/api-errors';
import { formatLocationOption } from '../../lib/format-location';
import { RemoteLookupCombobox } from '../ui/remote-lookup-combobox';

/**
 * Pickers for records that grow without bound (items, locations, suppliers). They ask the server for the few that match what is typed and resolve the
 * chosen one by its own id, so they keep working however many records exist — unlike `Combobox` over `per_page: 100`, which silently drops everything past
 * the first page. Use these (not a first-page list) wherever a person picks one of these records.
 */
const PAGE = 25;

/** The text, delayed until typing pauses, so a burst of keystrokes is one request. */
function useDelayed(value: string, delayMs = 250): string {
  const [delayed, setDelayed] = useState(value);
  useEffect(() => {
    const handle = window.setTimeout(() => setDelayed(value), value === '' ? 0 : delayMs);
    return () => window.clearTimeout(handle);
  }, [value, delayMs]);

  return delayed;
}

export interface EntityComboboxProps<R> {
  /** The chosen record's id as text, '' for none (or 'all' when `allLabel` is set). */
  value: string;
  /** Receives the id and, for a real choice, the whole record — so the caller need not look it up in some list it happens to hold. */
  onValueChange: (value: string, record?: R) => void;
  /** The control's accessible name, and the noun in its search placeholder. */
  label: string;
  id?: string;
  placeholder?: string;
  allLabel?: string;
  className?: string;
  disabled?: boolean;
}

const itemLabel = (item: { code: string; name: string }) => `${item.code} — ${item.name}`;
const chosenId = (value: string) => (value && value !== 'all' ? Number(value) : 0);

export function ItemCombobox({
  activeOnly,
  types,
  excludeId,
  leadingOptions,
  onValueChange,
  ...props
}: EntityComboboxProps<ItemResource> & {
  activeOnly?: boolean;
  /** Only these item types. One type is asked of the server; several are narrowed from what it returns. */
  types?: string[];
  excludeId?: number;
  /** Fixed choices shown ahead of the results (for example "None"). */
  leadingOptions?: { value: string; label: string }[];
}) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useItemsIndex({
    'filter[search]': delayed || undefined,
    'filter[status]': activeOnly ? 'ACTIVE' : undefined,
    'filter[type]': types?.length === 1 ? (types[0] as never) : undefined,
    per_page: PAGE,
    sort: 'name',
  });
  const chosen = useItemsShow(chosenId(props.value), undefined, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = (list.data?.status === 200 ? list.data.data.data : []).filter(
    (item) => item.id !== excludeId && (!types || types.length < 2 || types.includes(item.type)),
  );
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const leading = leadingOptions ?? [];

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'items')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      selectedLabel={selected ? itemLabel(selected) : leading.find((option) => option.value === props.value)?.label}
      options={[...leading, ...rows.map((item) => ({ value: String(item.id), label: itemLabel(item) }))]}
    />
  );
}

export function LocationCombobox({ warehouseId, onValueChange, ...props }: EntityComboboxProps<LocationResource> & { warehouseId?: number }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useLocationsIndex({ 'filter[search]': delayed || undefined, 'filter[warehouse_id]': warehouseId, per_page: PAGE, sort: 'code', include: 'warehouse' });
  const chosen = useLocationsShow(chosenId(props.value), { include: 'warehouse' }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'locations')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      selectedLabel={selected ? formatLocationOption(selected) : undefined}
      options={rows.map((location) => ({ value: String(location.id), label: formatLocationOption(location) }))}
    />
  );
}

export function SupplierCombobox({ onValueChange, ...props }: EntityComboboxProps<SupplierAccountResource>) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useSupplierAccountsIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'code', include: 'party' });
  const chosen = useSupplierAccountsShow(chosenId(props.value), { include: 'party' }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (supplier: { code: string; party?: { name?: string } | null }) => `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ''}`;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'suppliers')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      selectedLabel={selected ? label(selected) : undefined}
      options={rows.map((supplier) => ({ value: String(supplier.id), label: label(supplier) }))}
    />
  );
}

/**
 * For a screen that keeps its own styled `Combobox` (a themed filter, say) but must not depend on a first page: spread the result onto it. The options are
 * the server's matches for what is typed (with the chosen record kept visible), so the list is complete however many records exist.
 */
export function useItemLookup(value: string, options?: { activeOnly?: boolean; allLabel?: string }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useItemsIndex({ 'filter[search]': delayed || undefined, 'filter[status]': options?.activeOnly ? 'ACTIVE' : undefined, per_page: PAGE, sort: 'name' });
  const chosen = useItemsShow(chosenId(value), undefined, { query: { enabled: chosenId(value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;

  return {
    ...lookupState(list, 'items'),
    search,
    onSearchChange: setSearch,
    selectedLabel: selected ? itemLabel(selected) : undefined,
    options: [...(options?.allLabel ? [{ value: 'all', label: options.allLabel }] : []), ...rows.map((item) => ({ value: String(item.id), label: itemLabel(item) }))],
  };
}

export function useSupplierLookup(value: string, options?: { allLabel?: string }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useSupplierAccountsIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'code', include: 'party' });
  const chosen = useSupplierAccountsShow(chosenId(value), { include: 'party' }, { query: { enabled: chosenId(value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (supplier: { code: string; party?: { name?: string } | null }) => `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ''}`;

  return {
    ...lookupState(list, 'suppliers'),
    search,
    onSearchChange: setSearch,
    selectedLabel: selected ? label(selected) : undefined,
    options: [...(options?.allLabel ? [{ value: 'all', label: options.allLabel }] : []), ...rows.map((supplier) => ({ value: String(supplier.id), label: label(supplier) }))],
  };
}
