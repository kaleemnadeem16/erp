import {
  currenciesIndex,
  membersIndex,
  organisationUnitsIndex,
  quotationsAuthorityReferences,
  quotationsDiscountAuthorityHistory,
  quotationsDiscountAuthorityShow,
  rolesIndex,
  salesDiscountAuthorityApproveReference,
  salesDiscountAuthorityProposeReference,
  salesDiscountAuthorityActivatePolicy,
  salesDiscountAuthorityAssign,
  salesDiscountAuthorityIndex,
  salesDiscountAuthorityProposePolicy,
  salesDiscountAuthorityRevoke,
} from "@erp/core-api";
import { z } from "zod";
import {
  readActivationPage,
  readAuthorityHistory,
  readBindResult,
  readReferenceApprovalPage,
  readReferencePage,
  readAssignmentPage,
  readBandPage,
  readChange,
  readPolicyPage,
  readQuotationAuthority,
  readRevocationPage,
  type DiscountAuthorityGateway,
  type QuotationAuthorityGateway,
  type ReferenceGateway,
} from "./discount-authority-contract";

const body = (response: { data: unknown }): unknown =>
  (response.data as { data?: unknown } | undefined)?.data;
const PER_PAGE = 100;
const named = z.array(z.object({ id: z.number().int().positive(), name: z.string() }));
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
    return named.parse(body(await membersIndex({ per_page: 100 })));
  },
  roles: async () => named.parse(body(await rolesIndex())),
  units: async () => named.parse(body(await organisationUnitsIndex())),
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

/** Price references: proposing needs the configuration duty, approving needs the independent approval duty; the server checks both. */
export const liveReferenceGateway: ReferenceGateway = {
  read: async () => {
    const [references, approvals] = await Promise.all([
      list("references").then(readReferencePage),
      list("reference-approvals").then(readReferenceApprovalPage),
    ]);
    return {
      generation: Math.max(references.generation, approvals.generation),
      mode: references.mode,
      references: references.records,
      approvals: approvals.records,
      truncated: [references, approvals].some((p) => p.total > p.records.length),
    };
  },
  people: liveDiscountAuthorityGateway.people,
  currencies: async () =>
    z
      .array(z.object({ id: z.number().int().positive(), code: z.string(), name: z.string() }))
      .parse(body(await currenciesIndex({ per_page: 250, sort: "code" } as never))),
  propose: async ({ reference, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityProposeReference(
          {
            ...reference,
            family_id: reference.family_id ?? undefined,
            customer_id: reference.customer_id ?? undefined,
            item_id: reference.item_id ?? undefined,
            manual_measure: reference.manual_measure ?? undefined,
            uom_id: reference.uom_id ?? undefined,
            effective_to: reference.effective_to ?? undefined,
            reason,
            expected_generation: expectedGeneration,
          } as never,
          { "Idempotency-Key": requestKey },
        ),
      ),
    ),
  approve: async ({ referenceId, reason, expectedGeneration, requestKey }) =>
    readChange(
      body(
        await salesDiscountAuthorityApproveReference(
          referenceId,
          { reason, expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey },
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
  },  history: async (revisionId, page) => {
    const result = readAuthorityHistory(
      body(await quotationsDiscountAuthorityHistory(revisionId, { page, per_page: 25 })),
    );
    if (result.revision_id !== revisionId)
      throw new Error("Unexpected discount authority response.");
    return result;
  },
  bind: async ({ revisionId, expectedGeneration, requestKey }) => {
    const result = readBindResult(
      body(
        await quotationsAuthorityReferences(
          revisionId,
          { expected_generation: expectedGeneration },
          { "Idempotency-Key": requestKey },
        ),
      ),
    );
    if (result.revision_id !== revisionId)
      throw new Error("Unexpected discount authority response.");
    return result;
  },
};
