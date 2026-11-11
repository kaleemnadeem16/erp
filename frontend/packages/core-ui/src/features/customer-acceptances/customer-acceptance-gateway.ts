import {
  customerAcceptancesReadiness,
  customerAcceptancesShow,
  quotationsAcceptancePreview,
  quotationsAcceptancesStore,
} from "@erp/core-api";
import {
  readAcceptance,
  readOutcome,
  readPreview,
  readReadiness,
  type CustomerAcceptanceGateway,
} from "./customer-acceptance-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;

/** The real gateway: the generated client, every response parsed by the contract. A non-2xx answer is thrown by the fetch client as an ApiError. */
export const liveCustomerAcceptanceGateway: CustomerAcceptanceGateway = {
  preview: async ({ revisionId, intent }) =>
    readPreview(body(await quotationsAcceptancePreview(revisionId, intent))),
  record: async ({ intent, previewToken, requestKey }) =>
    readOutcome(
      body(
        await quotationsAcceptancesStore(
          intent.revision_id,
          {
            component_id: intent.component_id,
            evidence_id: intent.evidence_id,
            issued_line_id: intent.issued_line_id,
            expected_case_generation: intent.expected_case_generation,
            expected_scope_generation: intent.expected_scope_generation,
            expected_choice_generation: intent.expected_choice_generation,
            preview_token: previewToken,
            unchanged_terms: true,
          },
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
  show: async (acceptanceId) =>
    readAcceptance(body(await customerAcceptancesShow(acceptanceId))),
  readiness: async (acceptanceId, path) =>
    readReadiness(
      body(await customerAcceptancesReadiness(acceptanceId, { path })),
    ),
};
