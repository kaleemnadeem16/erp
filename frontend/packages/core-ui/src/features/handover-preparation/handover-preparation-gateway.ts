import {
  customerAcceptancesHandoverOptions,
  customerAcceptancesHandoverPlansStore,
  customerAcceptancesHandoverPreview,
} from "@erp/core-api";
import {
  readOptions,
  readPrepared,
  readPreparationPreview,
  wireInputs,
  type HandoverPreparationGateway,
} from "./handover-preparation-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;

/** The real gateway: the generated client, every response parsed by the contract. A non-2xx answer is thrown by the fetch client as an ApiError. */
export const liveHandoverPreparationGateway: HandoverPreparationGateway = {
  options: async (acceptanceId) =>
    readOptions(body(await customerAcceptancesHandoverOptions(acceptanceId))),
  preview: async (acceptanceId, inputs) =>
    readPreparationPreview(
      body(
        await customerAcceptancesHandoverPreview(
          acceptanceId,
          wireInputs(inputs) as Parameters<
            typeof customerAcceptancesHandoverPreview
          >[1],
        ),
      ),
    ),
  prepare: async ({ acceptanceId, inputs, previewToken, requestKey }) =>
    readPrepared(
      body(
        await customerAcceptancesHandoverPlansStore(
          acceptanceId,
          { ...wireInputs(inputs), preview_token: previewToken } as Parameters<
            typeof customerAcceptancesHandoverPlansStore
          >[1],
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
};
