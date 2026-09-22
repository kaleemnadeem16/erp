import { useAuthStore } from '../stores/auth-store';

interface StoredDraft<T> {
  value: T;
  savedAt: number;
}

// sessionStorage, not localStorage: a draft is for "I stepped away to check
// something", not a document to come back to next week — it lives as long as
// the tab and can't go stale or leak to whoever uses the browser next. Keyed
// by user so a second login in the same tab never inherits the first's work.
function storageKey(name: string): string | null {
  const user = useAuthStore.getState().user;
  return user ? `erp:draft:${name}:${user.id}` : null;
}

export function readDraft<T>(name: string): StoredDraft<T> | null {
  const key = storageKey(name);
  if (!key) return null;
  try {
    const raw = window.sessionStorage.getItem(key);
    return raw ? (JSON.parse(raw) as StoredDraft<T>) : null;
  } catch {
    return null;
  }
}

export function writeDraft<T>(name: string, value: T): void {
  const key = storageKey(name);
  if (!key) return;
  try {
    window.sessionStorage.setItem(key, JSON.stringify({ value, savedAt: Date.now() } satisfies StoredDraft<T>));
  } catch {
    // Storage full or unavailable — losing the draft is acceptable, breaking the form isn't.
  }
}

export function clearDraft(name: string): void {
  const key = storageKey(name);
  if (!key) return;
  try {
    window.sessionStorage.removeItem(key);
  } catch {
    // See writeDraft.
  }
}
