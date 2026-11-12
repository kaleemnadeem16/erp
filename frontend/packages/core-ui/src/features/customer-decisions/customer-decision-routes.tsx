import { RecordPresenter } from "../../components/page/record-presenter";
import type { Presentation } from "../../lib/presentation";
import { useParams } from "react-router";
import type { CustomerDecisionGateway } from "./customer-decision-contract";
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

export function CustomerDecisionCaseRoute({
  presentation = "sheet",
  gateway,
}: {
  presentation?: Presentation;
  gateway?: CustomerDecisionGateway;
}) {
  const id = caseFrom(useParams().case);
  return id === null ? (
    <RecordPresenter
      presentation={presentation}
      title="Customer message unavailable"
      listPath="/customer-decisions"
      listLabel="Customer messages"
    >
      <p className="text-sm" role="status">
        This customer message is not available.
      </p>
    </RecordPresenter>
  ) : (
    <CustomerDecisionCaseScreen
      caseId={id}
      presentation={presentation}
      gateway={gateway}
    />
  );
}
