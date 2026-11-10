import type { ReactNode } from "react";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";

/**
 * The shared page shell for the quotation issue, approval and document screens: the same template and header (title, one-line
 * purpose, back arrow to the quotation) as every other detail screen, so the page has a heading, a way back and the standard
 * spacing in light and dark mode. It carries no data and grants nothing.
 */
export function QuotationScreenShell({
  title,
  description,
  revisionId,
  children,
}: {
  title: string;
  description: string;
  revisionId: number;
  children: ReactNode;
}) {
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={title}
        description={`Quotation #${revisionId}. ${description}`}
        backTo={`/quotations/${revisionId}`}
      />
      {children}
    </ScreenTemplate>
  );
}
