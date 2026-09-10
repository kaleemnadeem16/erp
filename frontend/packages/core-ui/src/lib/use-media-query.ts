import { useSyncExternalStore } from 'react';

/** The app's one shared responsive breakpoint (Tailwind's default `md`, 768px). */
export const MD_BREAKPOINT_QUERY = '(min-width: 768px)';

/** Reactive `window.matchMedia` subscription, e.g. `useMediaQuery(MD_BREAKPOINT_QUERY)`. */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const mql = window.matchMedia(query);
      mql.addEventListener('change', onChange);
      return () => mql.removeEventListener('change', onChange);
    },
    () => window.matchMedia(query).matches,
    () => false,
  );
}
