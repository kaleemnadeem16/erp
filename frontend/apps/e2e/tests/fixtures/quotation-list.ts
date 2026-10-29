import { createElement, useState } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, useLocation } from "react-router";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import {
  QuotationListPresentation,
  QuotationListScreen,
} from "../../../../packages/core-ui/src/features/quotations/quotation-list-preview";
import { readQuotationListSummaries } from "../../../../packages/core-ui/src/features/quotations/quotation-list-contract";
const row = {
  revision_id: 12,
  family_id: 8,
  revision_ordinal: 1,
  state: "DRAFT",
  input_generation: 2,
  recipient_party_id: 3,
  currency_id: 4,
  pricing_date: "2026-10-28",
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  line_count: 1,
  created_by: 5,
  cost: "SECRET_COST",
  margin: "SECRET_MARGIN",
};
function PathProbe() {
  return createElement(
    "output",
    { "data-testid": "route" },
    useLocation().pathname,
  );
}
function Fixture() {
  const [pagination, onPaginationChange] = useState({
    pageIndex: 0,
    pageSize: 25,
  });
  const [sorting, onSortingChange] = useState([]);
  const [stateFilter, onStateFilterChange] = useState<
    "" | "DRAFT" | "CANCELLED_DRAFT"
  >("");
  const props = {
    rows: readQuotationListSummaries([row]),
    total: 28,
    pagination,
    onPaginationChange,
    sorting,
    onSortingChange,
    mayReview: true,
    stateFilter,
    onStateFilterChange,
  };
  return createElement(
    TooltipProvider,
    null,
    createElement(
      MemoryRouter,
      null,
      new URLSearchParams(location.search).has("gated")
        ? createElement(QuotationListScreen, props)
        : createElement(QuotationListPresentation, props),
      createElement(PathProbe),
    ),
  );
}
createRoot(document.getElementById("root")!).render(createElement(Fixture));
