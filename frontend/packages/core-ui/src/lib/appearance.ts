/**
 * Colour mode + accent theme, and how the two are chosen.
 *
 * What a theme *is* lives in `apps/main/src/palette-erp-blue.css` and
 * `themes.css` (paired base colours and accent CSS variables); this file is the registry of which ones exist, and the rule for
 * which one applies. That rule is layered so a company and a person can each
 * have a say without any screen knowing:
 *
 *   app default  <  company default  <  this user's own choice
 *
 * Today the user layer is browser-local (`localStorage`, like every other
 * client-side preference here) and the company layer is empty. When the backend
 * grows a company-settings and a user-preferences endpoint, they feed
 * `setCompany` / `setUser` in the appearance store — nothing that reads the
 * result changes. See docs/architecture/appearance-and-printing.md.
 */

export type ColorMode = "light" | "dark" | "system";
export type ThemeId = "slate" | "ocean" | "amber";

export interface ThemeDefinition {
  id: ThemeId;
  label: string;
  description: string;
  /** A CSS colour for the preview chip — the theme's light-mode primary. */
  swatch: string;
}

/** Adding a theme: add it here, then add its light + dark blocks to themes.css. */
export const THEMES: ThemeDefinition[] = [
  {
    id: "slate",
    label: "ERP Blue",
    description:
      "The default: light surfaces or black/charcoal dark surfaces, with blue interaction accents.",
    swatch: "#2563eb",
  },
  {
    id: "ocean",
    label: "Ocean",
    description: "A calm blue accent.",
    swatch: "oklch(0.5 0.19 258)",
  },
  {
    id: "amber",
    label: "Amber",
    description: "A warm orange accent.",
    swatch: "oklch(0.53 0.17 45)",
  },
];

export interface Appearance {
  mode: ColorMode;
  theme: ThemeId;
}

export const DEFAULT_APPEARANCE: Appearance = { mode: "light", theme: "slate" };

const isColorMode = (value: unknown): value is ColorMode =>
  value === "light" || value === "dark" || value === "system";
const isThemeId = (value: unknown): value is ThemeId =>
  THEMES.some((theme) => theme.id === value);

/** Keeps only the values that are valid — a stale or hand-edited entry can't break the app. */
export function sanitizeAppearance(input: unknown): Partial<Appearance> {
  if (typeof input !== "object" || input === null) return {};
  const { mode, theme } = input as Record<string, unknown>;
  return {
    ...(isColorMode(mode) ? { mode } : {}),
    ...(isThemeId(theme) ? { theme } : {}),
  };
}

/** Each layer may set either or both fields; a later layer wins field by field. */
export function resolveAppearance(
  company: Partial<Appearance>,
  user: Partial<Appearance>,
): Appearance {
  return {
    ...DEFAULT_APPEARANCE,
    ...sanitizeAppearance(company),
    ...sanitizeAppearance(user),
  };
}

/**
 * The key and shape are also read by the tiny inline script in index.html, which
 * applies the saved appearance before the first paint so a dark-mode user
 * doesn't get a white flash. Keep the two in step.
 */
export const APPEARANCE_STORAGE_KEY = "erp.appearance";

export function readStoredAppearance(): Partial<Appearance> {
  try {
    const raw = window.localStorage.getItem(APPEARANCE_STORAGE_KEY);
    return raw ? sanitizeAppearance(JSON.parse(raw)) : {};
  } catch {
    return {};
  }
}

export function storeAppearance(user: Partial<Appearance>): void {
  try {
    if (Object.keys(user).length === 0)
      window.localStorage.removeItem(APPEARANCE_STORAGE_KEY);
    else
      window.localStorage.setItem(APPEARANCE_STORAGE_KEY, JSON.stringify(user));
  } catch {
    // Storage unavailable (private mode, quota) — the in-memory state still applies for this session.
  }
}

/** Puts the resolved appearance on <html>, where themes.css reads it. */
export function applyAppearance(
  appearance: Appearance,
  systemPrefersDark: boolean,
): void {
  const root = document.documentElement;
  const dark =
    appearance.mode === "dark" ||
    (appearance.mode === "system" && systemPrefersDark);
  root.classList.toggle("dark", dark);
  // The default theme carries no attribute, so it is exactly the base tokens.
  if (appearance.theme === "slate") root.removeAttribute("data-theme");
  else root.setAttribute("data-theme", appearance.theme);
}
