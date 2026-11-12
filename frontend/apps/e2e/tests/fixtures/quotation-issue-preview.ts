import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter } from "react-router";
import { readIssueReadiness } from "../../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
import {
  QuotationReadinessPresentation,
  QuotationApprovalPresentation,
  QuotationReadinessScreen,
  QuotationApprovalScreen,
} from "../../../../packages/core-ui/src/features/quotations/quotation-issue-preview";
const flags = new URLSearchParams(location.search);
const ready = flags.has("ready");
const assessment = readIssueReadiness({
  revision_id: 12,
  input_generation: 3,
  issue_ready: ready,
  blockers: ready
    ? []
    : [
        {
          code: "TAX_NOT_DETERMINED",
          scope: "TAX",
          line_id: null,
          cost: "SECRET_COST",
        },
        { code: "CONTROL_UNEVALUABLE", scope: "CONTROLS", line_id: null },
        { code: "UNKNOWN_SECRET_CODE", scope: "LINE", line_id: 23 },
      ],
  evidence_fingerprint: "SECRET_HASH",
});
const component = flags.has("approval")
  ? flags.has("gated")
    ? QuotationApprovalScreen
    : QuotationApprovalPresentation
  : flags.has("gated")
    ? QuotationReadinessScreen
    : QuotationReadinessPresentation;
createRoot(document.getElementById("root")!).render(
  createElement(MemoryRouter, null, createElement(component, { assessment })),
);
