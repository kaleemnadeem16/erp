import { useLayoutEffect, type ReactNode } from 'react';

import { applyAppearance } from '../../lib/appearance';
import { useMediaQuery } from '../../lib/use-media-query';
import { useAppearance } from '../../stores/appearance-store';

const DARK_SCHEME_QUERY = '(prefers-color-scheme: dark)';

/**
 * Keeps <html> in step with the resolved appearance — including following the
 * operating system's light/dark switch live while the mode is "system". Mount
 * once, around the whole app.
 */
export function AppearanceProvider({ children }: { children: ReactNode }) {
  const appearance = useAppearance();
  const systemPrefersDark = useMediaQuery(DARK_SCHEME_QUERY);

  useLayoutEffect(() => {
    applyAppearance(appearance, systemPrefersDark);
  }, [appearance, systemPrefersDark]);

  return <>{children}</>;
}
