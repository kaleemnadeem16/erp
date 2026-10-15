import type { ReactNode } from 'react';
import { ArrowLeft, ChevronRight, Plus } from 'lucide-react';
import { Link } from 'react-router';

import { Button } from '../ui/button';
import { MobileFab } from './mobile-fab';

export interface PageHeaderBreadcrumb {
  label: string;
  to?: string;
}

export interface PageHeaderPrimaryAction {
  label: string;
  icon?: ReactNode;
  onClick?: () => void;
  to?: string;
}

export interface PageHeaderProps {
  title: string;
  description?: string;
  breadcrumbs?: PageHeaderBreadcrumb[];
  /**
   * A path to return to one level up (e.g. a create/edit screen back to its
   * list or detail parent). Renders as a leading back-arrow button before
   * the title — the breadcrumb trail alone is easy to miss as a "go back"
   * affordance, especially below `md` where there's no persistent sidebar
   * offering an incidental way out.
   */
  backTo?: string;
  /** Buttons/links — e.g. "New item", a PrintTrigger, an edit/delete menu. Renders inline, top-right, every breakpoint — unaffected by `primaryAction`. */
  actions?: ReactNode;
  /**
   * A screen's single primary action (e.g. "New item"). Renders inline
   * alongside `actions` on desktop; below `md` it renders instead as a fixed
   * `MobileFab` and is omitted from the header, per the mobile action-
   * placement standard (docs/architecture/user-interface.md). Screens with
   * more than one action — status-gated workflow buttons, for example —
   * should keep using `actions` instead; this prop is for the "new record"
   * case specifically.
   */
  primaryAction?: PageHeaderPrimaryAction;
}

/** The shared title/description/actions block every screen (list or detail) opens with. */
export function PageHeader({ title, description, breadcrumbs, backTo, actions, primaryAction }: PageHeaderProps) {
  return (
    <div className="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        {(backTo || (breadcrumbs && breadcrumbs.length > 0)) && (
          <nav className="-ml-1 mb-1 flex items-center gap-1 text-sm text-muted-foreground">
            {backTo && (
              <Link to={backTo} aria-label="Back" className="rounded p-1 hover:bg-accent hover:text-foreground">
                <ArrowLeft className="h-4 w-4" />
              </Link>
            )}
            {breadcrumbs?.map((crumb, index) => (
              <span key={`${crumb.label}-${index}`} className="flex items-center gap-1">
                {index > 0 && <ChevronRight className="h-3.5 w-3.5" />}
                {crumb.to ? (
                  <Link to={crumb.to} className="hover:text-foreground hover:underline">
                    {crumb.label}
                  </Link>
                ) : (
                  <span>{crumb.label}</span>
                )}
              </span>
            ))}
          </nav>
        )}
        <h1 className="page-header-title text-xl font-semibold">{title}</h1>
        {description && <p className="page-header-description mt-2 text-sm text-muted-foreground">{description}</p>}
      </div>
      {(actions || primaryAction) && (
        <div className="flex shrink-0 self-end items-center gap-2 sm:self-auto">
          {actions}
          {primaryAction &&
            (primaryAction.to ? (
              <Button asChild className="hidden md:inline-flex">
                <Link to={primaryAction.to}>
                  {primaryAction.icon ?? <Plus className="h-4 w-4" />}
                  {primaryAction.label}
                </Link>
              </Button>
            ) : (
              <Button onClick={primaryAction.onClick} className="hidden md:inline-flex">
                {primaryAction.icon ?? <Plus className="h-4 w-4" />}
                {primaryAction.label}
              </Button>
            ))}
        </div>
      )}
      {primaryAction && <MobileFab {...primaryAction} />}
    </div>
  );
}
