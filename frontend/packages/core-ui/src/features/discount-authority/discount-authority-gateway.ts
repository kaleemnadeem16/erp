import {
  membersIndex,
  quotationsDiscountAuthorityShow,
  salesDiscountAuthorityActivatePolicy,
  salesDiscountAuthorityAssign,
  salesDiscountAuthorityIndex,
  salesDiscountAuthorityProposePolicy,
  salesDiscountAuthorityRevoke,
} from "@erp/core-api";
import { z } from "zod";
import {
  readActivationPage,
  readAssignmentPage,
  readBandPage,
  readChange,
  readPolicyPage,
  readQuotationAuthority,
  readRevocationPage,
  type DiscountAuthorityGateway,
  type QuotationAuthorityGateway,
} from "./discount-authority-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;
const PER_PAGE = 100;
const list = async (collection: string) =>
  body(await salesDiscountAuthorityIndex(collection, { per_page: PER_PAGE }));

/** The real configuration gateway: the generated client, every response parsed by the contract. */
export const liveDiscountAuthorityGateway: DiscountAuthorityGateway = {
  read: async () => {
    const [policies, bands, assignments, activations, revocations] =
      await Promise.all([
        list("policies").then(readPolicyPage),
        list("bands").then(readBandPage),
        list("assignments").then(readAssignmentPage),
        list("policy-activations").then(readActivationPage),
        list("revocations").then(readRevocationPage),
      ]);
    const all = [policies, bands, assignments, activations, revocations];
    return {
      // The newest generation seen is the one a write must quote.
      generation: Math.max(...all.map((p) => p.generation)),
      mode: policies.mode,
      policies: policies.records,
      bands: bands.records,
      assignments: assignments.records,
      activations: activations.records,
      revocations: revocations.records,
      truncated: all.some((p) => p.total > p.records.length),
    };
  },
  people: async () => {
    const response = body(await membersIndex({ per_page: 100 }));
    return z
      .array(z.object({ id: z.number().int().positive(), name: z.string() }))
      .parse(response);
  },
  createPolicy: async ({ policy, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityProposePolicy(
          {
            ...policy,
            effective_to: policy.effective_to ?? undefined,
            reason,
            expected_generation: expectedGeneration,
          } as never,
          { "Idempotency-Key": requestKey } as never,
        ),
      ),
    ),
  activatePolicy: async ({ policyId, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityActivatePolicy(
          policyId,
          { reason, expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey } as never,
        ),
      ),
    ),
  assign: async ({ assignment, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityAssign(
          {
            ...assignment,
            role_id: assignment.role_id ?? undefined,
            unit_id: assignment.unit_id ?? undefined,
            effective_to: assignment.effective_to ?? undefined,
            reason,
            expected_generation: expectedGeneration,
          } as never,
          { "Idempotency-Key": requestKey } as never,
        ),
      ),
    ),
  revoke: async ({ assignmentId, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityRevoke(
          assignmentId,
          { reason, expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey } as never,
        ),
      ),
    ),
};

export const liveQuotationAuthorityGateway: QuotationAuthorityGateway = {
  read: async (revisionId) => {
    const result = readQuotationAuthority(
      body(await quotationsDiscountAuthorityShow(revisionId)),
    );
    if (result.revision_id !== revisionId)
      throw new Error("Unexpected discount authority response.");
    return result;
  },
};
