import { useMemo } from 'react';
import { create } from 'zustand';

import { useMediaQuery } from '../lib/use-media-query';
import {
  readStoredAppearance,
  resolveAppearance,
  sanitizeAppearance,
  storeAppearance,
  type Appearance,
} from '../lib/appearance';

interface AppearanceState {
  /** This person's own choice. Browser-local today; a user-preferences endpoint later. */
  user: Partial<Appearance>;
  /** The company's default. Empty until a company-settings endpoint exists. */
  company: Partial<Appearance>;
  setUser: (patch: Partial<Appearance>) => void;
  resetUser: () => void;
  /** For the server to call once it has a company default to give; not persisted here. */
  setCompany: (company: Partial<Appearance>) => void;
}

export const useAppearanceStore = create<AppearanceState>((set, get) => ({
  user: readStoredAppearance(),
  company: {},
  setUser: (patch) => {
    const user = { ...get().user, ...sanitizeAppearance(patch) };
    storeAppearance(user);
    set({ user });
  },
  resetUser: () => {
    storeAppearance({});
    set({ user: {} });
  },
  setCompany: (company) => set({ company: sanitizeAppearance(company) }),
}));

/** Whether dark colours are actually showing right now — for "system" that depends on the device. */
export function useIsDarkMode(): boolean {
  const { mode } = useAppearance();
  const systemPrefersDark = useMediaQuery('(prefers-color-scheme: dark)');
  return mode === 'dark' || (mode === 'system' && systemPrefersDark);
}

/** The appearance in effect: app default, then company default, then the user's own choice. */
export function useAppearance(): Appearance {
  const user = useAppearanceStore((state) => state.user);
  const company = useAppearanceStore((state) => state.company);
  return useMemo(() => resolveAppearance(company, user), [company, user]);
}
