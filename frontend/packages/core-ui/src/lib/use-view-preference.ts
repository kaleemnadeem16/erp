import { useCallback, useState } from 'react';

import { MD_BREAKPOINT_QUERY, useMediaQuery } from './use-media-query';

export type ListView = 'table' | 'cards';

const STORAGE_PREFIX = 'erp.list-view.';

function readStored(screenKey: string): ListView | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_PREFIX + screenKey);
    return raw === 'table' || raw === 'cards' ? raw : null;
  } catch {
    return null;
  }
}

/**
 * Resolves a saved per-screen list layout. Ordinary screens keep desktop tables
 * and default mobile cards (ADR 0015). Explicit `allowDesktopCards` opts a screen
 * into the same saved choice on desktop, with table as its desktop default.
 * Preferences stay browser-local and can later move to user settings without
 * changing screen call sites. Each screen uses its own stable storage key.
 */
export function useListViewPreference(screenKey: string, options?: { allowDesktopCards?: boolean }) {
  const isDesktop = useMediaQuery(MD_BREAKPOINT_QUERY);
  const [stored, setStored] = useState<ListView | null>(() => readStored(screenKey));

  const setView = useCallback(
    (next: ListView) => {
      setStored(next);
      try {
        window.localStorage.setItem(STORAGE_PREFIX + screenKey, next);
      } catch {
        // Storage unavailable (private mode, quota, etc.) — the in-memory
        // state above still drives this render, it just won't persist.
      }
    },
    [screenKey],
  );

  const view: ListView = isDesktop && !options?.allowDesktopCards ? 'table' : (stored ?? (isDesktop ? 'table' : 'cards'));
  return { view, setView, isDesktop };
}

