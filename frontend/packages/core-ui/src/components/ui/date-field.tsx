import * as React from 'react';
import { Calendar as CalendarIcon } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Calendar } from './calendar';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

export interface DateFieldProps {
  /** ISO `yyyy-mm-dd`, or `''` for no date. */
  value: string;
  onChange: (value: string) => void;
  onBlur?: () => void;
  disabled?: boolean;
  id?: string;
  className?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: boolean | 'true' | 'false';
  'aria-labelledby'?: string;
  /** For standalone uses with no associated `FormLabel` (e.g. a list-screen filter). */
  'aria-label'?: string;
}

interface Segments {
  month: string;
  day: string;
  year: string;
}

const EMPTY_SEGMENTS: Segments = { month: '', day: '', year: '' };
const SEGMENT_ORDER: (keyof Segments)[] = ['month', 'day', 'year'];
const MAX_LENGTH: Record<keyof Segments, number> = { month: 2, day: 2, year: 4 };
const MAX_VALUE: Record<'month' | 'day', number> = { month: 12, day: 31 };
const SEGMENT_LABEL: Record<keyof Segments, string> = { month: 'Month', day: 'Day', year: 'Year' };
const SEGMENT_PLACEHOLDER: Record<keyof Segments, string> = { month: 'MM', day: 'DD', year: 'YYYY' };

function parseIso(value: string): Segments {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  // Non-null: all 3 groups are always captured when the regex matches.
  return match ? { year: match[1]!, month: match[2]!, day: match[3]! } : EMPTY_SEGMENTS;
}

/** Round-trips through `Date` so an out-of-range combination (e.g. Feb 30) is rejected, not silently rolled over. */
function toIso(segments: Segments): string {
  const { month, day, year } = segments;
  if (month.length !== 2 || day.length !== 2 || year.length !== 4) return '';
  const m = Number(month);
  const d = Number(day);
  const y = Number(year);
  const date = new Date(Date.UTC(y, m - 1, d));
  const isValid = date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
  return isValid ? `${year}-${month}-${day}` : '';
}

/** Zero-pads a single leftover digit (e.g. "3" -> "03") once a segment loses focus, so a value the user considers finished isn't treated as incomplete. */
function padOnBlur(name: keyof Segments, raw: string): string {
  return name !== 'year' && raw.length === 1 ? raw.padStart(2, '0') : raw;
}

function parseUsDate(text: string): Segments | null {
  const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text.trim());
  return match ? { month: match[1]!.padStart(2, '0'), day: match[2]!.padStart(2, '0'), year: match[3]! } : null;
}

/**
 * mm/dd/yyyy date entry as three independently-editable segments, plus a
 * calendar popover — replacing the browser's native `<input type="date">`,
 * whose Tab key walks its own mm/dd/yyyy segments before leaving the field
 * (not something a native control lets JS override). Here only Month is a
 * real tab stop, so Tab always exits to the next form field from any
 * segment; arrow keys move between segments instead. The calendar button
 * restores a click-to-pick affordance for anyone who'd rather not type. Same
 * `value`/`onChange` contract as `<Input type="date" {...field} />` (ISO
 * `yyyy-mm-dd` or `''`), so it drops into the existing `FormControl`
 * wrapping with no zod schema changes — `onChange` only ever fires with a
 * complete, valid date or `''`, matching what the native input already
 * produced.
 */
export const DateField = React.forwardRef<HTMLInputElement, DateFieldProps>(function DateField(
  {
    value,
    onChange,
    onBlur,
    disabled,
    id,
    className,
    'aria-describedby': ariaDescribedBy,
    'aria-invalid': ariaInvalid,
    'aria-labelledby': ariaLabelledBy,
    'aria-label': ariaLabel,
  },
  forwardedRef
) {
  const [segments, setSegments] = React.useState<Segments>(() => parseIso(value));
  const [calendarOpen, setCalendarOpen] = React.useState(false);
  const focusedWithinRef = React.useRef(false);
  const inputRefs = {
    month: React.useRef<HTMLInputElement>(null),
    day: React.useRef<HTMLInputElement>(null),
    year: React.useRef<HTMLInputElement>(null),
  };

  React.useImperativeHandle(forwardedRef, () => inputRefs.month.current as HTMLInputElement);

  // Re-sync from the controlled `value` (e.g. form.reset()) unless the user
  // is actively editing a segment, where a resync would fight their typing.
  React.useEffect(() => {
    if (focusedWithinRef.current) return;
    setSegments(parseIso(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  function commit(next: Segments) {
    setSegments(next);
    onChange(toIso(next));
  }

  function focusSegment(name: keyof Segments, selectAll = true) {
    const el = inputRefs[name].current;
    el?.focus();
    if (selectAll) el?.select();
  }

  function handleSegmentChange(name: keyof Segments, rawInput: string) {
    const digits = rawInput.replace(/\D/g, '').slice(0, MAX_LENGTH[name]);
    commit({ ...segments, [name]: digits });

    const index = SEGMENT_ORDER.indexOf(name);
    const isLast = index === SEGMENT_ORDER.length - 1;
    const reachedMax = digits.length === MAX_LENGTH[name];
    // Advance early once a single leading digit can't take a second one
    // (e.g. day "4" can only mean 4 — no valid day starts with 4X).
    const firstDigitUnambiguous =
      digits.length === 1 &&
      ((name === 'month' && Number(digits) > 1) || (name === 'day' && Number(digits) > 3));
    if (!isLast && (reachedMax || firstDigitUnambiguous)) {
      focusSegment(SEGMENT_ORDER[index + 1]!);
    }
  }

  function handleSegmentBlur(name: keyof Segments) {
    const padded = padOnBlur(name, segments[name]);
    if (padded !== segments[name]) commit({ ...segments, [name]: padded });
  }

  function handleKeyDown(name: keyof Segments, event: React.KeyboardEvent<HTMLInputElement>) {
    const index = SEGMENT_ORDER.indexOf(name);
    if (event.key === 'ArrowLeft' && index > 0) {
      event.preventDefault();
      focusSegment(SEGMENT_ORDER[index - 1]!);
    } else if (event.key === 'ArrowRight' && index < SEGMENT_ORDER.length - 1) {
      event.preventDefault();
      focusSegment(SEGMENT_ORDER[index + 1]!);
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      const delta = event.key === 'ArrowUp' ? 1 : -1;
      if (name === 'year') {
        const current = segments.year ? Number(segments.year) : new Date().getFullYear();
        commit({ ...segments, year: String(Math.max(1, current + delta)).padStart(4, '0') });
      } else {
        const max = MAX_VALUE[name];
        const current = segments[name] ? Number(segments[name]) : delta > 0 ? 0 : 1;
        const next = ((current - 1 + delta + max) % max) + 1;
        commit({ ...segments, [name]: String(next).padStart(2, '0') });
      }
    } else if (event.key === 'Backspace' && segments[name] === '' && index > 0) {
      event.preventDefault();
      const previous = SEGMENT_ORDER[index - 1]!;
      commit({ ...segments, [previous]: segments[previous].slice(0, -1) });
      focusSegment(previous, false);
    }
  }

  function handlePaste(event: React.ClipboardEvent<HTMLInputElement>) {
    const text = event.clipboardData.getData('text');
    const iso = parseIso(text.trim());
    const us = parseUsDate(text);
    const parsed = iso.month ? iso : us;
    if (!parsed) return; // not a recognisable full date — let it paste into just the focused segment
    event.preventDefault();
    commit(parsed);
    focusSegment('year', false);
  }

  return (
    <div
      id={id}
      role="group"
      aria-label={ariaLabel}
      aria-labelledby={ariaLabelledBy}
      aria-describedby={ariaDescribedBy}
      aria-invalid={ariaInvalid}
      className={cn(
        'flex h-9 w-full items-center justify-between rounded-md border border-input bg-transparent pl-3 pr-1 text-sm shadow-sm ring-offset-background focus-within:ring-1 focus-within:ring-ring',
        disabled && 'cursor-not-allowed opacity-50',
        className
      )}
      onFocus={() => {
        focusedWithinRef.current = true;
      }}
      onBlur={(event) => {
        if (event.currentTarget.contains(event.relatedTarget as Node | null)) return;
        focusedWithinRef.current = false;
        onBlur?.();
      }}
    >
      <div className="flex items-center gap-0.5">
        {SEGMENT_ORDER.map((name, index) => (
          <React.Fragment key={name}>
            {index > 0 && (
              <span aria-hidden="true" className="text-muted-foreground">
                /
              </span>
            )}
            <input
              ref={inputRefs[name]}
              type="text"
              inputMode="numeric"
              autoComplete="off"
              role="spinbutton"
              aria-label={SEGMENT_LABEL[name]}
              aria-valuemin={1}
              aria-valuemax={name === 'year' ? undefined : MAX_VALUE[name]}
              aria-valuenow={segments[name] ? Number(segments[name]) : undefined}
              aria-valuetext={segments[name] || undefined}
              tabIndex={name === 'month' ? 0 : -1}
              disabled={disabled}
              maxLength={MAX_LENGTH[name]}
              placeholder={SEGMENT_PLACEHOLDER[name]}
              value={segments[name]}
              onChange={(event) => handleSegmentChange(name, event.target.value)}
              onKeyDown={(event) => handleKeyDown(name, event)}
              onBlur={() => handleSegmentBlur(name)}
              onPaste={handlePaste}
              onFocus={(event) => event.currentTarget.select()}
              className={cn(
                'border-0 bg-transparent p-0 text-center tabular-nums text-foreground focus:outline-none disabled:cursor-not-allowed',
                name === 'year' ? 'w-[4ch]' : 'w-[2ch]'
              )}
            />
          </React.Fragment>
        ))}
      </div>
      <Popover open={calendarOpen} onOpenChange={setCalendarOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            disabled={disabled}
            aria-label="Choose date"
            className="flex size-7 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50"
          >
            <CalendarIcon className="size-4" />
          </button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="end">
          <Calendar
            value={toIso(segments)}
            onSelect={(iso) => {
              commit(parseIso(iso));
              setCalendarOpen(false);
            }}
          />
        </PopoverContent>
      </Popover>
    </div>
  );
});
DateField.displayName = 'DateField';
