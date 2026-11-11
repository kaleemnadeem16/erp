import {
  customerDecisionCasesEvidenceStore,
  customerDecisionCasesIndex,
  customerDecisionCasesShow,
  customerDecisionCasesStore,
  type CustomerDecisionCasesIndexParams,
} from "@erp/core-api";
import {
  readCaseDetail,
  readListPage,
  readOutcome,
  type CustomerDecisionGateway,
} from "./customer-decision-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;

/** The real gateway: the generated client, every response parsed by the contract. A non-2xx answer is thrown by the fetch client as an ApiError. */
export const liveCustomerDecisionGateway: CustomerDecisionGateway = {
  list: async (params) => {
    const query: CustomerDecisionCasesIndexParams = {
      page: Math.max(1, params.page),
      per_page: Math.min(100, Math.max(1, params.perPage)),
      sort: params.sort,
    };
    if (params.search) query["filter[search]"] = params.search;
    const response = await customerDecisionCasesIndex(query);
    return readListPage(response.data);
  },
  detail: async (caseId) =>
    readCaseDetail(body(await customerDecisionCasesShow(caseId))),
  open: async ({ body: input, requestKey }) =>
    readOutcome(
      body(
        await customerDecisionCasesStore(input, {
          "Idempotency-Key": requestKey,
        }),
      ),
    ),
  addObservation: async ({
    caseId,
    expectedGeneration,
    body: input,
    requestKey,
  }) =>
    readOutcome(
      body(
        await customerDecisionCasesEvidenceStore(
          caseId,
          { ...input, expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
};
