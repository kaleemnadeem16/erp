import {
  customerDecisionCasesEvidenceStore,
  customerDecisionCasesIndex,
  customerDecisionCasesShow,
  customerDecisionCasesStore,
  quotationsIndex,
  quotationsIssued,
  type CustomerDecisionCasesIndexParams,
} from "@erp/core-api";
import { readListPage as readQuotationPage } from "../quotations/quotation-list-contract";
import { readIssuedTerms } from "../quotations/quotation-issue-contract";
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
  quotationsFor: async (partyId) => {
    const response = await quotationsIndex({
      page: 1,
      per_page: 100,
      "filter[state]": "ISSUED",
      sort: "-id",
    });
    return readQuotationPage(response.data)
      .data.filter((row) => row.recipient_party_id === partyId)
      .map((row) => ({
        revision_id: row.revision_id,
        family_id: row.family_id,
        official_number: row.official_number,
        availability: row.availability,
      }));
  },
  quotationScopes: async (revisionId) => {
    const issued = readIssuedTerms(body(await quotationsIssued(revisionId)));
    return {
      revision_id: issued.revision_id,
      family_id: issued.family_id,
      official_number: issued.official_number,
      scopes:
        issued.scope_acceptance?.map((s) => ({
          issued_line_id: s.issued_line_id,
          logical_scope_id: s.logical_scope_id,
        })) ?? null,
    };
  },
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
