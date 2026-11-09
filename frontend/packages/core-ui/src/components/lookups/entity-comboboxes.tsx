import { useEffect, useState } from 'react';
import {
  useApplicationRatesIndex,
  useApplicationRatesShow,
  useAssembliesIndex,
  useAssembliesShow,
  useAssemblyGroupsIndex,
  useAssemblyGroupsShow,
  useChartOfAccountsIndex,
  useChartOfAccountsShow,
  useCustomerAccountsIndex,
  useCustomerAccountsShow,
  useFormulationsIndex,
  useFormulationsShow,
  useItemsIndex,
  useItemsShow,
  useLocationsIndex,
  useLocationsShow,
  usePartiesIndex,
  usePartiesShow,
  useSalesOwnersIndex,
  useSupplierAccountsIndex,
  useSupplierAccountsShow,
  useUomsIndex,
  useUomsShow,
  type ApplicationRateResource,
  type AssemblyResource,
  type AssemblyGroupResource,
  type ChartOfAccountResource,
  type CustomerAccountResource,
  type FormulationResource,
  type ItemResource,
  type LocationResource,
  type PartyResource,
  type SupplierAccountResource,
  type UomResource,
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
      searching={search !== delayed || (search !== '' && list.isFetching)}
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
      searching={search !== delayed || (search !== '' && list.isFetching)}
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
      searching={search !== delayed || (search !== '' && list.isFetching)}
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
    searching: search !== delayed || (search !== '' && list.isFetching),
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
    searching: search !== delayed || (search !== '' && list.isFetching),
    selectedLabel: selected ? label(selected) : undefined,
    options: [...(options?.allLabel ? [{ value: 'all', label: options.allLabel }] : []), ...rows.map((supplier) => ({ value: String(supplier.id), label: label(supplier) }))],
  };
}

/** A trading partner (organisation or individual), searched on the server. */
export function PartyCombobox({ onValueChange, ...props }: EntityComboboxProps<PartyResource>) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = usePartiesIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'name' });
  const chosen = usePartiesShow(chosenId(props.value), undefined, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (party: { code: string; name: string }) => `${party.name} (${party.code})`;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'trading partners')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : undefined}
      options={rows.map((party) => ({ value: String(party.id), label: label(party) }))}
    />
  );
}

/** An active member who can be made responsible for a deal or an enquiry (id and name only). */
export function OwnerCombobox({ onValueChange, ...props }: EntityComboboxProps<{ id: number; name: string }>) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useSalesOwnersIndex({ 'filter[search]': delayed || undefined });
  const chosen = useSalesOwnersIndex({ 'filter[id]': chosenId(props.value) }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data[0] : undefined;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'people')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected?.name}
      options={rows.map((person) => ({ value: String(person.id), label: person.name }))}
    />
  );
}

/**
 * A general-ledger account, searched on the server by code or name (so a chart of any size works). `enabled` is false for someone who may not read account
 * mappings: the lookup is then not even requested and the control stays inert rather than showing a failed request as "no results".
 */
export function AccountCombobox({ onValueChange, enabled = true, excludeId, ...props }: EntityComboboxProps<ChartOfAccountResource> & { enabled?: boolean; excludeId?: number }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useChartOfAccountsIndex({ 'filter[search]': delayed || undefined, 'filter[status]': 'ACTIVE', per_page: PAGE, sort: 'code' }, { query: { enabled } });
  const chosen = useChartOfAccountsShow(chosenId(props.value), undefined, { query: { enabled: enabled && chosenId(props.value) > 0 } });
  const rows = (list.data?.status === 200 ? list.data.data.data : []).filter((account) => account.id !== excludeId);
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (account: { code: string; name: string }) => `${account.code} — ${account.name}`;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'accounts')}
      {...props}
      disabled={props.disabled || !enabled}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : undefined}
      options={rows.map((account) => ({ value: String(account.id), label: label(account) }))}
    />
  );
}

/** A customer account, searched on the server by code, partner name or tax number, and named `CODE — Partner name`. */
export function CustomerCombobox({ onValueChange, leadingOptions, ...props }: EntityComboboxProps<CustomerAccountResource> & { leadingOptions?: { value: string; label: string }[] }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useCustomerAccountsIndex({ 'filter[search]': delayed || undefined, include: 'party', per_page: PAGE, sort: 'code' });
  const chosen = useCustomerAccountsShow(chosenId(props.value), { include: 'party' }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (customer: { code: string; party?: { name: string } | null }) => (customer.party?.name ? `${customer.code} — ${customer.party.name}` : customer.code);
  const leading = leadingOptions ?? [];

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'customers')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : leading.find((option) => option.value === props.value)?.label}
      options={[...leading, ...rows.map((customer) => ({ value: String(customer.id), label: label(customer) }))]}
    />
  );
}

/** An assembly with its work unit and versions, searched on the server by code or name, named `CODE — Name`. The whole record comes back with the choice. */
export function AssemblyCombobox({ onValueChange, ...props }: EntityComboboxProps<AssemblyResource>) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useAssembliesIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'code', include: 'workUom,versions' });
  const chosen = useAssembliesShow(chosenId(props.value), { include: 'workUom,versions' }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (assembly: { code: string; name: string }) => `${assembly.code} — ${assembly.name}`;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'assemblies')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : undefined}
      options={rows.map((assembly) => ({ value: String(assembly.id), label: label(assembly) }))}
    />
  );
}

/** A unit of measure, searched on the server by code or name. */
export function UomCombobox({ onValueChange, ...props }: EntityComboboxProps<UomResource>) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useUomsIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'name' });
  const chosen = useUomsShow(chosenId(props.value), { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (uom: { code: string; name: string }) => `${uom.name} (${uom.code})`;

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'units')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : undefined}
      options={rows.map((uom) => ({ value: String(uom.id), label: label(uom) }))}
    />
  );
}

/** An assembly group, searched on the server by code or name. */
export function AssemblyGroupCombobox({ onValueChange, leadingOptions, ...props }: EntityComboboxProps<AssemblyGroupResource> & { leadingOptions?: { value: string; label: string }[] }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useAssemblyGroupsIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'code' });
  const chosen = useAssemblyGroupsShow(chosenId(props.value), undefined, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (group: { code: string; name: string }) => `${group.code} — ${group.name}`;
  const leading = leadingOptions ?? [];

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'assembly groups')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : leading.find((option) => option.value === props.value)?.label}
      options={[...leading, ...rows.map((group) => ({ value: String(group.id), label: label(group) }))]}
    />
  );
}

/** A formulation (recipe), searched on the server by code or name, named `CODE — Name`. */
export function FormulationCombobox({ onValueChange, leadingOptions, ...props }: EntityComboboxProps<FormulationResource> & { leadingOptions?: { value: string; label: string }[] }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useFormulationsIndex({ 'filter[search]': delayed || undefined, per_page: PAGE, sort: 'code' });
  const chosen = useFormulationsShow(chosenId(props.value), undefined, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (formulation: { code: string; name: string }) => `${formulation.code} — ${formulation.name}`;
  const leading = leadingOptions ?? [];

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'formulations')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : leading.find((option) => option.value === props.value)?.label}
      options={[...leading, ...rows.map((formulation) => ({ value: String(formulation.id), label: label(formulation) }))]}
    />
  );
}

/** An application rate, searched on the server by the code or name of its item, named `ITEM — qty unit per work unit`. */
export function ApplicationRateCombobox({ onValueChange, leadingOptions, ...props }: EntityComboboxProps<ApplicationRateResource> & { leadingOptions?: { value: string; label: string }[] }) {
  const [search, setSearch] = useState('');
  const delayed = useDelayed(search);
  const list = useApplicationRatesIndex({ 'filter[search]': delayed || undefined, include: 'item,workUom,uom', per_page: PAGE });
  const chosen = useApplicationRatesShow(chosenId(props.value), { include: 'item,workUom,uom' }, { query: { enabled: chosenId(props.value) > 0 } });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const selected = chosen.data?.status === 200 ? chosen.data.data.data : undefined;
  const label = (rate: ApplicationRateResource) =>
    `${rate.item ? `${rate.item.code} — ${rate.item.name}` : `Item #${rate.item_id}`}: ${rate.qty_per_work_unit} ${rate.uom?.code ?? ''} per ${rate.work_uom?.code ?? 'work unit'}${rate.is_default ? ' (default)' : ''}`.replace(/\s+/g, ' ');
  const leading = leadingOptions ?? [];

  return (
    <RemoteLookupCombobox
      {...lookupState(list, 'application rates')}
      {...props}
      onValueChange={(value) => onValueChange(value, rows.find((row) => String(row.id) === value))}
      search={search}
      onSearchChange={setSearch}
      searching={search !== delayed || (search !== '' && list.isFetching)}
      selectedLabel={selected ? label(selected) : leading.find((option) => option.value === props.value)?.label}
      options={[...leading, ...rows.map((rate) => ({ value: String(rate.id), label: label(rate) }))]}
    />
  );
}
