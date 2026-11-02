import { z } from "zod";

// Agent A's internal AssessQuotationIssueReadiness Action at 0c9a774a, not an HTTP contract.
// Reject inconsistent readiness and discard all non-public fields, including any evidence.
const id = z.number().int().positive().safe();
const blocker = z.object({
  code: z.string().min(1).max(100),
  scope: z.enum(["HEADER", "LINE", "TAX", "CONTROLS", "AUTHORITY"]),
  line_id: id.nullable(),
});
export const readinessSchema = z
  .object({
    revision_id: id,
    input_generation: z.number().int().nonnegative().safe(),
    issue_ready: z.boolean(),
    blockers: z.array(blocker),
  })
  .superRefine((value, ctx) => {
    if (value.issue_ready !== (value.blockers.length === 0))
      ctx.addIssue({ code: "custom", message: "Inconsistent readiness" });
    if (
      value.blockers.some(
        (item) => item.scope === "LINE" && item.line_id === null,
      )
    )
      ctx.addIssue({ code: "custom", message: "Missing line reference" });
  });
export type IssueReadiness = z.infer<typeof readinessSchema>;
export function readIssueReadiness(value: unknown): IssueReadiness {
  return readinessSchema.parse(value);
}
