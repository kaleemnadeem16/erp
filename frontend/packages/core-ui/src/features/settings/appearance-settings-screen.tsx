import type { ReactNode } from 'react';
import { RotateCcw } from 'lucide-react';

import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { Button } from '../../components/ui/button';
import { OptionCards } from '../../components/ui/option-cards';
import { THEMES, type ColorMode } from '../../lib/appearance';
import { useAppearance, useAppearanceStore } from '../../stores/appearance-store';

// Miniatures of the mode, so the choice is made by looking: a page with a card on it.
function ModePreview({ page, card, text }: { page: string; card: string; text: string }) {
  return (
    <div className="h-14 rounded-md border p-2" style={{ background: page }} aria-hidden="true">
      <div className="h-full rounded-sm p-1.5" style={{ background: card }}>
        <div className="h-1.5 w-2/3 rounded-full" style={{ background: text }} />
        <div className="mt-1 h-1.5 w-1/2 rounded-full opacity-50" style={{ background: text }} />
      </div>
    </div>
  );
}

const MODE_OPTIONS: { value: ColorMode; label: string; description: string; preview: ReactNode }[] = [
  {
    value: 'light',
    label: 'Light',
    description: 'Dark text on a light page.',
    preview: <ModePreview page="oklch(0.985 0 0)" card="oklch(1 0 0)" text="oklch(0.25 0 0)" />,
  },
  {
    value: 'dark',
    label: 'Dark',
    description: 'Light text on a dark page — easier on the eyes at night.',
    preview: <ModePreview page="oklch(0.15 0 0)" card="oklch(0.22 0 0)" text="oklch(0.9 0 0)" />,
  },
  {
    value: 'system',
    label: 'Match my device',
    description: 'Follows your operating system, switching with it.',
    preview: (
      <div className="flex h-14 overflow-hidden rounded-md border" aria-hidden="true">
        <div className="w-1/2" style={{ background: 'oklch(0.985 0 0)' }} />
        <div className="w-1/2" style={{ background: 'oklch(0.15 0 0)' }} />
      </div>
    ),
  },
];

/**
 * Colour mode and accent theme for this browser. Every colour in the app is a
 * token that themes.css redefines, so nothing here recolours a screen — it only
 * picks which set of tokens applies. The choice is browser-local today; the
 * appearance store is layered (app < company < user) so company and per-user
 * defaults from the server slot in later without touching this screen. See
 * docs/architecture/appearance-and-printing.md.
 */
export function AppearanceSettingsScreen() {
  const appearance = useAppearance();
  const setUser = useAppearanceStore((state) => state.setUser);
  const resetUser = useAppearanceStore((state) => state.resetUser);

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Appearance"
        description="Colour mode and theme. These apply to this browser; company and per-person defaults will come from the server later."
        actions={
          <Button variant="outline" size="sm" onClick={resetUser}>
            <RotateCcw className="size-4" />
            Reset to default
          </Button>
        }
      />
      <DetailPageLayout>
        <DetailSection title="Colour mode" description="Turn dark mode on or off, or let your device decide.">
          <div className="sm:col-span-2">
            <OptionCards
              label="Colour mode"
              value={appearance.mode}
              onChange={(mode) => setUser({ mode })}
              options={MODE_OPTIONS}
            />
          </div>
        </DetailSection>
        <DetailSection title="Theme" description="The accent colour used for buttons, the active step and highlights.">
          <div className="sm:col-span-2">
            <OptionCards
              label="Theme"
              value={appearance.theme}
              onChange={(theme) => setUser({ theme })}
              options={THEMES.map((theme) => ({
                value: theme.id,
                label: theme.label,
                description: theme.description,
                preview: (
                  <span
                    className="block h-8 w-full rounded-md"
                    style={{ background: theme.swatch }}
                    aria-hidden="true"
                  />
                ),
              }))}
            />
          </div>
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
