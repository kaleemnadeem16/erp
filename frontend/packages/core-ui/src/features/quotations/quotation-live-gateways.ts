import {
  fetchBlob,
  getQuotationsDocumentDownloadUrl,
  quotationsDeliveriesIndex,
  quotationsDeliveriesStore,
  quotationsDocumentCheck,
  quotationsDocumentGenerate,
  quotationsDocumentHistory,
  quotationsDocumentShow,
  quotationsIndex,
  quotationsIssue,
  quotationsIssueReadiness,
  quotationsIssued,
  type QuotationsIndexParams,
} from "@erp/core-api";
import {
  readListPage,
  type QuotationListGateway,
} from "./quotation-list-contract";
import {
  readIssuedTerms,
  readIssueOutcome,
  readReadiness,
  type QuotationIssueGateway,
} from "./quotation-issue-contract";
import {
  readCheckOutcome,
  readDeliveries,
  readDelivery,
  readDocumentStatus,
  readHistory,
  type QuotationDocumentGateway,
} from "./quotation-document-contract";

/**
 * The real gateways: the generated client (packages/core-api), every response parsed and stripped by the
 * contract schemas before a screen sees it. A non-2xx answer is thrown by the fetch client as an ApiError
 * (status + body), which the screens map to fixed wording; 404 stays non-disclosing. Tests and previews
 * inject scripted gateways instead.
 */
const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;

export const liveListGateway: QuotationListGateway = async (params) => {
  const query: QuotationsIndexParams = {
    page: Math.max(1, params.page),
    per_page: Math.min(100, Math.max(1, params.perPage)),
  };
  if (params.state) query["filter[state]"] = params.state;
  if (params.mine) query["filter[mine]"] = true;
  if (params.sort) query.sort = params.sort;
  const response = await quotationsIndex(query);
  return readListPage(response.data);
};

export const liveIssueGateway: QuotationIssueGateway = {
  readiness: async (revisionId) =>
    readReadiness(body(await quotationsIssueReadiness(revisionId))),
  issue: async ({ revisionId, expectedGeneration, requestKey }) =>
    readIssueOutcome(
      body(
        await quotationsIssue(
          revisionId,
          { expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
  issued: async (revisionId) =>
    readIssuedTerms(body(await quotationsIssued(revisionId))),
};

export const liveDocumentGateway: QuotationDocumentGateway = {
  status: async (revisionId) =>
    readDocumentStatus(body(await quotationsDocumentShow(revisionId))),
  generate: async ({ revisionId, requestKey }) =>
    readDocumentStatus(
      body(
        await quotationsDocumentGenerate(revisionId, {
          "Idempotency-Key": requestKey,
        }),
      ),
    ),
  // The authorised bytes only, fetched with the session's credentials; no link is built for or shown to the person.
  download: ({ revisionId, intent }) =>
    fetchBlob(getQuotationsDocumentDownloadUrl(revisionId, { intent })),
  history: async (revisionId) =>
    readHistory(body(await quotationsDocumentHistory(revisionId))),
  check: async (revisionId) =>
    readCheckOutcome(body(await quotationsDocumentCheck(revisionId))),
  deliveries: async (revisionId) =>
    readDeliveries(body(await quotationsDeliveriesIndex(revisionId))),
  recordDelivery: async ({ revisionId, body: input, requestKey }) =>
    readDelivery(
      body(
        await quotationsDeliveriesStore(revisionId, input, {
          "Idempotency-Key": requestKey,
        }),
      ),
    ),
};
