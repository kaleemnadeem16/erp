import { useParams } from "react-router";
import { QuotationListScreen } from "./quotation-list-screen";
import { QuotationIssueScreen } from "./quotation-issue-screen";
import { QuotationDocumentScreen } from "./quotation-document-screen";

/**
 * Route entries for the list, issue and document screens. Every screen denies before any query when the production
 * gate is off (QUOTATION_UI_ENABLED is false; only the local development override can open it), so registering these
 * routes exposes nothing in production. A malformed revision never reaches a screen or the network.
 */
function revisionFrom(raw: string | undefined): number | null {
  return raw && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw))
    ? Number(raw)
    : null;
}

function Unavailable() {
  return (
    <div className="p-4 md:p-6" role="status">
      <h1 className="text-lg font-semibold">Quotation unavailable</h1>
      <p className="text-sm">This quotation is not available.</p>
    </div>
  );
}

export function QuotationListRoute() {
  return <QuotationListScreen />;
}

export function QuotationIssueRoute() {
  const revision = revisionFrom(useParams().revision);
  return revision === null ? (
    <Unavailable />
  ) : (
    <QuotationIssueScreen revisionId={revision} />
  );
}

export function QuotationDocumentRoute() {
  const revision = revisionFrom(useParams().revision);
  return revision === null ? (
    <Unavailable />
  ) : (
    <QuotationDocumentScreen revisionId={revision} />
  );
}
