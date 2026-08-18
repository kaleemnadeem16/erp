import { create } from 'zustand';
import type { UserResource } from '@erp/core-api';

type AuthState = {
  user: UserResource | null;
  setUser: (user: UserResource | null) => void;
};

// Mirrors the identity TanStack Query resolves from `/user` so any part of the
// app can read "who's logged in" without subscribing to that query directly.
export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  setUser: (user) => set({ user }),
}));
