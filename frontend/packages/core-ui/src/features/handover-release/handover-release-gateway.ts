import {
  customerAcceptancesCompletionBasesStore,
  customerAcceptancesShow,
  customerDecisionCasesShow,
  handoverPlansShow,
  handoverStagesRelease,
  handoverStagesReleasePreview,
} from "@erp/core-api";
import { readAcceptance } from "../customer-acceptances/customer-acceptance-contract";
import { readCaseDetail } from "../customer-decisions/customer-decision-contract";
import {
  readBasisOutcome,
  readPlan,
  readReleaseOutcome,
  readReleasePreview,
  type HandoverReleaseGateway,
} from "./handover-release-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;

/** The real gateway: the generated client, every response parsed by the contract. A non-2xx answer is thrown by the fetch client as an ApiError. */
export const liveHandoverReleaseGateway: HandoverReleaseGateway = {
  plan: async (planId) => readPlan(body(await handoverPlansShow(planId))),
  acceptance: async (acceptanceId) => {
    const a = readAcceptance(body(await customerAcceptancesShow(acceptanceId)));
    return {
      caseId: a.case_id,
      consentEvidenceId: a.consent_evidence_id,
      issuedDescription: a.scope.description,
    };
  },
  evidence: async (caseId) =>
    readCaseDetail(body(await customerDecisionCasesShow(caseId))).observations,
  preview: async (stageId) =>
    readReleasePreview(body(await handoverStagesReleasePreview(stageId))),
  recordBasis: async ({ acceptanceId, agreedText, evidenceId, requestKey }) =>
    readBasisOutcome(
      body(
        await customerAcceptancesCompletionBasesStore(
          acceptanceId,
          { agreed_text: agreedText, evidence_id: evidenceId },
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
  release: async ({ stageId, basisId, fingerprint, requestKey }) =>
    readReleaseOutcome(
      body(
        await handoverStagesRelease(
          stageId,
          { basis_id: basisId, readiness_fingerprint: fingerprint },
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
};
