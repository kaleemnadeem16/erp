import { z } from "zod";
import {
  quotationFamiliesAccessShow,
  quotationFamiliesAccessCandidates,
  quotationFamiliesReassignOwner,
  quotationFamiliesChangeAccessUnit,
  quotationFamiliesGrantAccess,
  quotationFamiliesRevokeAccess,
  type QuotationFamiliesAccessCandidatesParams,
} from "@erp/core-api";
import {
  familyAccessSchema,
  accessCandidatePageSchema,
  familyAccessOutcomeSchema,
  type FamilyAccessGateway,
} from "./quotation-family-access-contract";

const envelope = z.object({ data: z.unknown() });
export const liveFamilyAccessGateway: FamilyAccessGateway = {
  read: async (familyId, query) => {
    const result = familyAccessSchema.parse(
      envelope.parse((await quotationFamiliesAccessShow(familyId, query)).data)
        .data,
    );
    if (result.family_id !== familyId)
      throw new Error("Unexpected quotation family response.");
    return result;
  },
  candidates: async (familyId, query) => {
    const params: QuotationFamiliesAccessCandidatesParams = {
      kind: query.kind,
      per_page: 25,
    };
    if (query.level) params.level = query.level;
    if (query.search.trim()) params["filter[search]"] = query.search.trim();
    if (query.cursor) params.cursor = query.cursor;
    return accessCandidatePageSchema.parse(
      (await quotationFamiliesAccessCandidates(familyId, params)).data,
    );
  },
  write: async (familyId, intent, key) => {
    const body = {
      reason: intent.reason,
      expected_ownership_generation: intent.expected_ownership_generation,
      expected_access_generation: intent.expected_access_generation,
    };
    const headers = { "Idempotency-Key": key };
    const response = await (async () => {
      switch (intent.operation) {
        case "owner":
          return quotationFamiliesReassignOwner(
            familyId,
            { ...body, owner_id: intent.owner_id },
            headers,
          );
        case "unit":
          return quotationFamiliesChangeAccessUnit(
            familyId,
            { ...body, access_unit_id: intent.access_unit_id },
            headers,
          );
        case "grant":
          return quotationFamiliesGrantAccess(
            familyId,
            {
              ...body,
              recipient_id: intent.recipient_id,
              level: intent.level,
              starts_at: intent.starts_at,
              ends_at: intent.ends_at,
            },
            headers,
          );
        case "revoke":
          return quotationFamiliesRevokeAccess(
            familyId,
            { ...body, grant_id: intent.grant_id },
            headers,
          );
      }
    })();
    const result = familyAccessOutcomeSchema.parse(
      envelope.parse(response.data).data,
    );
    if (result.family_id !== familyId)
      throw new Error("Unexpected quotation family response.");
    return result;
  },
};
