import { useEffect, useState } from 'react';

/**
 * Tracks nav usage entirely in localStorage — no backend endpoint exists for
 * this yet. Swap the read/write functions here for real API calls once one
 * does; every consumer only sees the hook's return shape, not the storage
 * mechanism, so that swap won't touch app-shell.tsx.
 */
const VISITS_KEY = 'erp.nav-visits';
const PINNED_KEY = 'erp.nav-pinned';

function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    // Private browsing, disabled storage, or corrupt JSON — treat as empty.
    return fallback;
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage full or disabled — the feature just doesn't persist this session.
  }
}

/**
 * Maps a browser pathname to the nav item that "owns" it, so a detail/edit
 * route (e.g. `/items/5/edit`) counts as a visit to its list item (`/items`)
 * rather than going untracked. `/` only ever matches itself — otherwise it
 * would prefix-match every route. Longest match wins when more than one nav
 * path could apply.
 */
export function resolveNavPath(pathname: string, navPaths: string[]): string | null {
  const matches = navPaths.filter((to) => (to === '/' ? pathname === '/' : pathname === to || pathname.startsWith(`${to}/`)));
  if (matches.length === 0) return null;
  return matches.reduce((longest, current) => (current.length > longest.length ? current : longest));
}

export function recordNavVisit(path: string): void {
  const visits = readJson<Record<string, number>>(VISITS_KEY, {});
  visits[path] = (visits[path] ?? 0) + 1;
  writeJson(VISITS_KEY, visits);
}

export interface NavUsagePrefs {
  isPinned: (path: string) => boolean;
  togglePin: (path: string) => void;
  /** Pinned paths first (pin order), then the remaining slots filled by visit frequency. */
  quickLinks: (excludePaths: string[], max: number) => string[];
}

/**
 * Re-reads localStorage whenever the route changes — cheap synchronous
 * reads, and simpler than a cross-tab storage-event subscription for a
 * single-tab, single-user feature like this.
 */
export function useNavUsagePrefs(pathname: string): NavUsagePrefs {
  const [pinned, setPinned] = useState<string[]>(() => readJson(PINNED_KEY, []));
  const [visits, setVisits] = useState<Record<string, number>>(() => readJson(VISITS_KEY, {}));

  useEffect(() => {
    setVisits(readJson(VISITS_KEY, {}));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  function togglePin(path: string) {
    setPinned((prev) => {
      const next = prev.includes(path) ? prev.filter((p) => p !== path) : [...prev, path];
      writeJson(PINNED_KEY, next);
      return next;
    });
  }

  function quickLinks(excludePaths: string[], max: number): string[] {
    const excluded = new Set(excludePaths);
    const pinnedVisible = pinned.filter((path) => !excluded.has(path));
    const remainingSlots = max - pinnedVisible.length;
    if (remainingSlots <= 0) return pinnedVisible.slice(0, max);

    const byFrequency = Object.entries(visits)
      .filter(([path]) => !excluded.has(path) && !pinned.includes(path))
      .sort(([, a], [, b]) => b - a)
      .map(([path]) => path);

    return [...pinnedVisible, ...byFrequency.slice(0, remainingSlots)];
  }

  return { isPinned: (path) => pinned.includes(path), togglePin, quickLinks };
}
