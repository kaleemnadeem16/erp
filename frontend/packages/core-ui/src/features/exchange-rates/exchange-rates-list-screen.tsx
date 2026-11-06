import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useCurrenciesIndex, useExchangeRatesIndex, type ExchangeRateResource, type ExchangeRatesIndexSort } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Combobox } from '../../components/ui/combobox';
import { DateField } from '../../components/ui/date-field';
import {
  ActiveFilterChips,
  BulkActionBar,
  DataTable,
  FilterField,
  FilterSelect,
  ListFiltersSheet,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { lookupState } from '../../lib/api-errors';
import { formatDate } from '../../lib/format-date';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { exchangeRateColumns } from './exchange-rate-columns';
import { RATE_TYPES, rateTypeLabel } from './exchange-rate-rules';

interface ExchangeRateFilters {
  from_currency_id: string;
  to_currency_id: string;
  rate_type: string;
  rate_date: string;
  [key: string]: string;
}

const MANAGE = 'currency.exchange-rate.manage';

/**
 * Manually entered exchange rates (ADR 0031). Sorted newest first by default. Bulk: only *Print selected* — deleting a rate is a deliberate, per-rate action
 * taken in its own sheet with its consequences stated, never a one-click bulk change (screen-delivery-contract.md §Row actions and selection).
 */
export function ExchangeRatesListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayManage = usePermission(MANAGE);
  const query = useListQueryState<ExchangeRateFilters>({ defaultSort: '-rate_date', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('exchange-rates-list', Object.fromEntries(exchangeRateColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('exchange-rates-list');

  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyOptions = [{ value: 'all', label: 'All currencies' }, ...currencies.map((currency) => ({ value: String(currency.id), label: `${currency.code} — ${currency.name}` }))];
  const currencyCode = (id: string) => currencies.find((currency) => String(currency.id) === id)?.code ?? `#${id}`;

  const result = useExchangeRatesIndex({
    include: 'from_currency,to_currency',
    'filter[from_currency_id]': query.filters.from_currency_id ? Number(query.filters.from_currency_id) : undefined,
    'filter[to_currency_id]': query.filters.to_currency_id ? Number(query.filters.to_currency_id) : undefined,
    'filter[rate_type]': (query.filters.rate_type as (typeof RATE_TYPES)[number] | undefined) || undefined,
    'filter[rate_date]': query.filters.rate_date || undefined,
    sort: query.sort as ExchangeRatesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rates = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<ExchangeRateResource>(), ...toTanStackColumns(exchangeRateColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rates.filter((rate) => rowSelection[String(rate.id)]), [rates, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, exchangeRateColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Exchange Rates' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Exchange Rates (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.from_currency_id) chips.push({ id: 'from', label: `From: ${currencyCode(query.filters.from_currency_id)}`, onRemove: () => query.setFilter('from_currency_id', undefined) });
  if (query.filters.to_currency_id) chips.push({ id: 'to', label: `To: ${currencyCode(query.filters.to_currency_id)}`, onRemove: () => query.setFilter('to_currency_id', undefined) });
  if (query.filters.rate_type) chips.push({ id: 'type', label: `Type: ${rateTypeLabel(query.filters.rate_type)}`, onRemove: () => query.setFilter('rate_type', undefined) });
  if (query.filters.rate_date) chips.push({ id: 'date', label: `Date: ${formatDate(query.filters.rate_date) ?? query.filters.rate_date}`, onRemove: () => query.setFilter('rate_date', undefined) });
  const clearAll = () => query.setFilters({ from_currency_id: undefined, to_currency_id: undefined, rate_type: undefined, rate_date: undefined });

  const currencyCombobox = (key: 'from_currency_id' | 'to_currency_id', label: string, full: boolean) => (
    <Combobox
      {...lookupState(currenciesQuery, 'currencies')}
      className={full ? 'h-11 w-full' : 'h-10 w-48'}
      aria-label={`${label}${full ? ' (filters sheet)' : ''}`}
      value={query.filters[key] ?? 'all'}
      onValueChange={(value) => query.setFilter(key, value === 'all' ? undefined : value)}
      placeholder={label}
      searchPlaceholder="Search currencies…"
      emptyText="No currencies found."
      options={currencyOptions}
    />
  );
  const typeSelect = (full: boolean) => (
    <FilterSelect
      label={`Rate type${full ? ' (filters sheet)' : ''}`}
      allLabel="All types"
      value={query.filters.rate_type}
      options={RATE_TYPES.map((type) => ({ value: type, label: rateTypeLabel(type) }))}
      onChange={(value) => query.setFilter('rate_type', value)}
      className={full ? '' : 'md:w-40'}
    />
  );
  const dateField = (full: boolean) => (
    <DateField aria-label={`Rate date${full ? ' (filters sheet)' : ''}`} value={query.filters.rate_date ?? ''} onChange={(value) => query.setFilter('rate_date', value || undefined)} className={full ? 'w-full' : 'h-10 w-40'} />
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Exchange rates"
        description="Rates you enter by hand for converting foreign-currency amounts. A document keeps the rate it was made with; changing a rate here never revalues it."
        primaryAction={mayManage ? { label: 'New rate', onClick: () => navigate({ pathname: '/settings/exchange-rates/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {currencyCombobox('from_currency_id', 'From currency', false)}
          {currencyCombobox('to_currency_id', 'To currency', false)}
          {typeSelect(false)}
          {dateField(false)}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={chips.length} onClear={clearAll}>
          <FilterField label="From currency">{currencyCombobox('from_currency_id', 'From currency', true)}</FilterField>
          <FilterField label="To currency">{currencyCombobox('to_currency_id', 'To currency', true)}</FilterField>
          <FilterField label="Rate type">{typeSelect(true)}</FilterField>
          <FilterField label="Rate date">{dateField(true)}</FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="rate" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rates}
        rowCount={meta?.total ?? 0}
        recordNoun="rates"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, exchangeRateColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(rate) => String(rate.id)}
        onRowClick={(rate: ExchangeRateResource) => navigate({ pathname: `/settings/exchange-rates/${rate.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load exchange rates."
        emptyMessage={chips.length > 0 ? 'No exchange rates match these filters.' : 'No exchange rates entered yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={exchangeRateColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="EXCHANGE RATES"
            printedAt={new Date()}
            recordCount={rates.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, exchangeRateColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={exchangeRateColumns} rows={rates} getRowKey={(rate) => rate.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="EXCHANGE RATES (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected rates` }]}>
            <PrintableTable columns={exchangeRateColumns} rows={selected} getRowKey={(rate) => rate.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
