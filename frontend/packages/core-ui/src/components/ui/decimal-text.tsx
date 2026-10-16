import { formatDecimalDetailed, formatMoneyDetailed, type CurrencyPlaces, type FormatOptions } from '../../lib/decimal/format';
import { cn } from '../../lib/utils';

/**
 * A decimal shown as text. When the chosen display drops digits that were not zero, the figure is marked (≈) and its title carries the exact value, so a
 * shortened display can never conceal a difference. A value shown in full has no marker.
 */
export function DecimalText({ value, className, ...options }: { value: string | null | undefined; className?: string } & FormatOptions) {
  const shown = formatDecimalDetailed(value, options);
  if (shown.text === '') return <span className={className}>—</span>;

  return (
    <span className={cn('tabular-nums', className)} title={shown.rounded ? `Exact value: ${shown.exact}` : undefined}>
      {shown.rounded ? '≈ ' : ''}
      {shown.text}
    </span>
  );
}

/** Money for display using the currency's real minor unit (see `formatMoneyDetailed`), with its code when given. */
export function MoneyText({
  value,
  currency,
  settled,
  showCode = true,
  className,
}: {
  value: string | null | undefined;
  currency: (CurrencyPlaces & { code?: string }) | null | undefined;
  settled?: boolean;
  showCode?: boolean;
  className?: string;
}) {
  const shown = formatMoneyDetailed(value, currency, { settled });
  if (shown.text === '') return <span className={className}>—</span>;

  return (
    <span className={cn('tabular-nums', className)} title={shown.rounded ? `Exact value: ${shown.exact}` : undefined}>
      {shown.rounded ? '≈ ' : ''}
      {showCode && currency?.code ? `${currency.code} ` : ''}
      {shown.text}
    </span>
  );
}
