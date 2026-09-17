import * as React from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Button } from './button';

export interface CalendarProps {
  /** ISO `yyyy-mm-dd`, or `''` for no selection. */
  value: string;
  onSelect: (value: string) => void;
  className?: string;
}

const WEEKDAY_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

interface Cell {
  day: number;
  year: number;
  /** 0-indexed. */
  month: number;
  outsideMonth: boolean;
}

function pad(value: number, length: number): string {
  return String(value).padStart(length, '0');
}

function toIso(year: number, month: number, day: number): string {
  return `${pad(year, 4)}-${pad(month + 1, 2)}-${pad(day, 2)}`;
}

function parseIso(value: string): { year: number; month: number; day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return match ? { year: Number(match[1]), month: Number(match[2]) - 1, day: Number(match[3]) } : null;
}

function buildGrid(year: number, month: number): Cell[] {
  const firstWeekday = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();
  const cells: Cell[] = [];
  for (let i = 0; i < 42; i += 1) {
    const dayNumber = i - firstWeekday + 1;
    if (dayNumber < 1) {
      const prevMonth = month === 0 ? 11 : month - 1;
      cells.push({ day: daysInPrevMonth + dayNumber, year: month === 0 ? year - 1 : year, month: prevMonth, outsideMonth: true });
    } else if (dayNumber > daysInMonth) {
      const nextMonth = month === 11 ? 0 : month + 1;
      cells.push({ day: dayNumber - daysInMonth, year: month === 11 ? year + 1 : year, month: nextMonth, outsideMonth: true });
    } else {
      cells.push({ day: dayNumber, year, month, outsideMonth: false });
    }
  }
  return cells;
}

/** A month-grid date picker — click a day to select it. Used inside DateField's calendar popover. */
export function Calendar({ value, onSelect, className }: CalendarProps) {
  const selected = parseIso(value);
  const today = new Date();
  const [viewYear, setViewYear] = React.useState(selected?.year ?? today.getFullYear());
  const [viewMonth, setViewMonth] = React.useState(selected?.month ?? today.getMonth());

  function goToPreviousMonth() {
    if (viewMonth === 0) {
      setViewMonth(11);
      setViewYear((year) => year - 1);
    } else {
      setViewMonth((month) => month - 1);
    }
  }
  function goToNextMonth() {
    if (viewMonth === 11) {
      setViewMonth(0);
      setViewYear((year) => year + 1);
    } else {
      setViewMonth((month) => month + 1);
    }
  }

  const cells = buildGrid(viewYear, viewMonth);

  return (
    <div className={cn('w-64 p-3', className)}>
      <div className="mb-2 flex items-center justify-between">
        <Button type="button" variant="ghost" size="icon" className="size-7" onClick={goToPreviousMonth} aria-label="Previous month">
          <ChevronLeft className="size-4" />
        </Button>
        <div className="text-sm font-medium">
          {MONTH_NAMES[viewMonth]} {viewYear}
        </div>
        <Button type="button" variant="ghost" size="icon" className="size-7" onClick={goToNextMonth} aria-label="Next month">
          <ChevronRight className="size-4" />
        </Button>
      </div>
      <div className="grid grid-cols-7 gap-1">
        {WEEKDAY_LABELS.map((label) => (
          <div key={label} className="flex h-7 items-center justify-center text-xs font-medium text-muted-foreground">
            {label}
          </div>
        ))}
        {cells.map((cell) => {
          const isSelected = !!selected && !cell.outsideMonth && cell.year === selected.year && cell.month === selected.month && cell.day === selected.day;
          const isToday = cell.year === today.getFullYear() && cell.month === today.getMonth() && cell.day === today.getDate();
          return (
            <button
              key={`${cell.year}-${cell.month}-${cell.day}-${cell.outsideMonth}`}
              type="button"
              disabled={cell.outsideMonth}
              onClick={() => onSelect(toIso(cell.year, cell.month, cell.day))}
              className={cn(
                'flex h-7 w-7 items-center justify-center rounded-md text-sm text-foreground hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:text-muted-foreground/40',
                isSelected && 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground',
                isToday && !isSelected && 'border border-primary'
              )}
            >
              {cell.day}
            </button>
          );
        })}
      </div>
    </div>
  );
}
