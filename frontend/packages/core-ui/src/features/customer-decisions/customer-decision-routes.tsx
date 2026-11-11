import { useParams } from "react-router";
import { CustomerDecisionCaseScreen } from "./customer-decision-case-screen";
import { CustomerDecisionListScreen } from "./customer-decision-list-screen";

/** A malformed case id never reaches a screen or the network. */
function caseFrom(raw: string | undefined): number | null {
  return raw && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw))
    ? Number(raw)
    : null;
}

export function CustomerDecisionListRoute() {
  return <CustomerDecisionListScreen />;
}

export function CustomerDecisionCaseRoute() {
  const id = caseFrom(useParams().case);
  return id === null ? (
    <div className="p-4 md:p-6" role="status">
      <h1 className="text-lg font-semibold">Customer message unavailable</h1>
      <p className="text-sm">This customer message is not available.</p>
    </div>
  ) : (
    <CustomerDecisionCaseScreen caseId={id} />
  );
}
