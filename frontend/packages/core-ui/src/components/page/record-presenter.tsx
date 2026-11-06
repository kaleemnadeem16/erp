import type { ReactNode } from 'react';
import { useLocation, useNavigate } from 'react-router';

import type { Presentation } from '../../lib/presentation';
import { PageHeader } from './page-header';
import { RecordSheet, type RecordSheetSize } from './record-sheet';
import { ScreenTemplate } from './screen-template';

export interface RecordPresenterProps {
  presentation: Presentation;
  title: string;
  description?: string;
  /** Header buttons/menus (Print, Edit, workflow actions). Built once; shown in the sheet header or the page header. */
  actions?: ReactNode;
  /** The list this record belongs to. Closing the sheet or following the breadcrumb returns here with the list's own query string. */
  listPath: string;
  listLabel: string;
  size?: RecordSheetSize;
  /** A create/edit form: a stray click behind the sheet must not throw away what was typed. */
  form?: boolean;
  children: ReactNode;
}

/**
 * One record, shown as a right-hand sheet over its list (default) or as a page of its own — chosen per screen in `apps/main/src/presentation.ts`. The screen builds
 * its header content and body once and hands them here, so a record that used to be a bare page (`<div className="space-y-4 p-6">` + `PageHeader`) joins the
 * sheet-by-default standard without copying the `present()` branch into every screen (erp-blue-design-standard.md §Detail / §Create-edit).
 */
export function RecordPresenter({ presentation, title, description, actions, listPath, listLabel, size = 'xl', form = false, children }: RecordPresenterProps) {
  const navigate = useNavigate();
  const { search } = useLocation();

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={title}
        description={description}
        actions={actions}
        size={size}
        className="erp-record"
        dismissOnOutsideClick={!form}
        onClose={() => navigate({ pathname: listPath, search })}
      >
        <div className="space-y-4 px-4 pb-6 pt-4">{children}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind={form ? 'form' : 'detail'} className="erp-screen">
      <PageHeader title={title} description={description} breadcrumbs={[{ label: listLabel, to: `${listPath}${search}` }, { label: title }]} actions={actions} />
      {children}
    </ScreenTemplate>
  );
}
