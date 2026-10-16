import * as React from 'react';

import { checkDecimal, describeDecimalRule, type DecimalRule } from '../../lib/decimal/check';
import { cn } from '../../lib/utils';
import { Input } from './input';

type NativeInput = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type' | 'inputMode' | 'min' | 'max' | 'step'>;

export interface DecimalInputProps extends NativeInput {
  /** The exact string being edited. Whatever is typed is kept as typed; nothing is reformatted, padded or rounded while typing, pasting or leaving the field. */
  value: string | null | undefined;
  /** Receives the raw text. It is never a `number`. */
  onChange: (value: string) => void;
  /** What the field accepts: a context from `DECIMAL_SPECS` (or a custom spec), the sign, and an optional increment. */
  rule: Pick<DecimalRule, 'spec' | 'sign' | 'increment'>;
  /** Right-align the digits (the default for quantity and money columns). */
  align?: 'left' | 'right';
}

/**
 * A text input for an exact decimal (ADR 0141/0142). It exists to stop a business quantity, price or rate being altered by the control:
 *  - it is `type="text"` with a decimal keypad (`inputMode="decimal"`), because `type="number"` reformats, accepts exponent notation ("1e3") and rounds
 *    through a float in some browsers;
 *  - it reports and emits the string untouched; invalid text is shown as invalid (`aria-invalid`), never corrected;
 *  - a paste of a well-formed grouped number ("1,234.50") is cleaned *once, visibly*, because the separator is presentation, not part of the value.
 * The form's schema (`decimalField`) supplies the visible message; this component supplies the keypad, the accessible state and a precise title/placeholder.
 */
export const DecimalInput = React.forwardRef<HTMLInputElement, DecimalInputProps>(
  ({ value: provided, onChange, rule, align = 'right', className, placeholder, title, onPaste, ...props }, ref) => {
    const value = provided ?? '';
    const invalid = value.trim() !== '' && !checkDecimal(value, { ...rule, label: 'Value' }).ok;
    const description = describeDecimalRule(rule);

    return (
      <Input
        {...props}
        ref={ref}
        type="text"
        inputMode="decimal"
        autoComplete="off"
        autoCorrect="off"
        spellCheck={false}
        value={value}
        placeholder={placeholder}
        title={title ?? description}
        aria-invalid={props['aria-invalid'] ?? (invalid ? true : undefined)}
        className={cn(align === 'right' && 'text-right tabular-nums', className)}
        onChange={(event) => onChange(event.target.value)}
        onPaste={(event) => {
          onPaste?.(event);
          if (event.defaultPrevented) return;
          const pasted = event.clipboardData.getData('text').trim();
          if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(pasted)) {
            event.preventDefault();
            onChange(pasted.replace(/,/g, ''));
          }
        }}
      />
    );
  }
);
DecimalInput.displayName = 'DecimalInput';
