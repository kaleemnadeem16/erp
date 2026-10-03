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
 * Resolves a list screen's table/card rendering mode. Desktop (`md` and up)
 * is always 'table' — cards are the small-screen fallback per ADR 0015, not
 * a desktop option. Below `md`, the visitor's own choice (persisted in
 * localStorage) wins; absent a stored choice, 'cards' is the default the
 * first time a screen is opened on a small screen, per the same ADR.
 *
 * `screenKey` must be unique per screen (e.g. 'items-list') so two screens'
 * choices never collide in storage. Client-only preference — no cookies, no
 * backend — swappable for a DB-backed user-settings table later without
 * changing call sites.
 */
export function useListViewPreference(screenKey: string) {
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

  return { view: isDesktop ? ('table' as const) : (stored ?? 'cards'), setView, isDesktop };
}

