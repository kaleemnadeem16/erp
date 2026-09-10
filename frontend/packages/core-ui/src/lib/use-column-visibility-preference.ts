import { useCallback, useState } from 'react';
import type { Updater, VisibilityState } from '@tanstack/react-table';

const STORAGE_PREFIX = 'erp.columns.';

function readStored(screenKey: string): VisibilityState | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_PREFIX + screenKey);
    return raw ? (JSON.parse(raw) as VisibilityState) : null;
  } catch {
    return null;
  }
}

/**
 * Persists a list screen's column-visibility choices (the "Columns" dropdown)
 * in localStorage, the same client-only mechanism as `useListViewPreference`.
 * `defaults` is each column's built-in default (`isColumnVisibleByDefault`) —
 * used on first visit and to backfill any column added to the screen since
 * the visitor's last stored choice, so a new column doesn't silently stay
 * hidden forever because it's absent from an old stored value.
 */
export function useColumnVisibilityPreference(screenKey: string, defaults: VisibilityState) {
  const [visibility, setVisibilityState] = useState<VisibilityState>(() => {
    const stored = readStored(screenKey);
    return stored ? { ...defaults, ...stored } : defaults;
  });

  const setVisibility = useCallback(
    (updater: Updater<VisibilityState>) => {
      setVisibilityState((previous) => {
        const next = typeof updater === 'function' ? updater(previous) : updater;
        try {
          window.localStorage.setItem(STORAGE_PREFIX + screenKey, JSON.stringify(next));
        } catch {
          // Storage unavailable — in-memory state above still drives this render.
        }
        return next;
      });
    },
    [screenKey]
  );

  return [visibility, setVisibility] as const;
}
