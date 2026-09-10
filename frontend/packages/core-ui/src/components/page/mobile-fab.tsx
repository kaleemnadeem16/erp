import type { ReactNode } from 'react';
import { Plus } from 'lucide-react';
import { Link } from 'react-router';

export interface MobileFabProps {
  label: string;
  icon?: ReactNode;
  onClick?: () => void;
  to?: string;
}

/**
 * The mobile placement for a screen's single primary action (e.g. "New
 * item") — a fixed circular button, bottom-right, above the bottom tab nav.
 * Desktop keeps the equivalent button inline in `PageHeader`; this is the
 * `md:hidden` counterpart. See `PageHeader`'s `primaryAction` prop.
 */
export function MobileFab({ label, icon, onClick, to }: MobileFabProps) {
  const className =
    'md:hidden fixed right-4 z-40 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform active:scale-95';
  const style = { bottom: 'calc(var(--bottom-nav-h) + env(safe-area-inset-bottom) + 1rem)' };
  const content = (
    <>
      {icon ?? <Plus className="size-5" />}
      <span className="sr-only">{label}</span>
    </>
  );

  return to ? (
    <Link to={to} className={className} style={style} aria-label={label}>
      {content}
    </Link>
  ) : (
    <button type="button" onClick={onClick} className={className} style={style} aria-label={label}>
      {content}
    </button>
  );
}
