import {
  jobsAssign,
  jobsStart,
  jobsHold,
  jobsResolveHold,
  jobsComplete,
  jobsAssessCloseout,
  jobsClose,
  type JobCommandResource,
  type JobCloseoutAssessmentResource,
} from "@erp/core-api";
import type { JobOperation, JobWriteBody } from "./job-operation-contract";

function required<T>(value: T | undefined, label: string): T {
  if (value === undefined) throw new Error(`${label} is required.`);
  return value;
}

/** Transport adapter only. The existing Actions remain the sole transition/readiness/idempotency authority. */
export async function sendJobCommand(
  jobId: number,
  operation: JobOperation,
  body: JobWriteBody,
): Promise<JobCommandResource | JobCloseoutAssessmentResource> {
  if (operation === "ASSESS_CLOSEOUT") {
    const result = await jobsAssessCloseout(jobId, {
      expected_generation: body.expected_generation,
      request_key: body.request_key,
    });
    if (result.status !== 200)
      throw new Error("The assessment was not confirmed.");
    return result.data.data;
  }
  const common = {
    expected_generation: body.expected_generation,
    request_key: body.request_key,
    command_identity: required(body.command_identity, "Command identity"),
    occurred_at: body.occurred_at,
    occurrence_precision: body.occurrence_precision,
  };
  const result = await (() => {
    switch (operation) {
      case "ASSIGN":
        return jobsAssign(jobId, {
          ...common,
          assignee_id: required(body.assignee_id, "Employee"),
          reason: body.reason,
          instructions: body.instructions,
          intended_from: body.intended_from,
          intended_until: body.intended_until,
        });
      case "START":
        return jobsStart(jobId, { ...common, reason: body.reason });
      case "HOLD":
        return jobsHold(jobId, {
          ...common,
          reason: required(body.reason, "Reason"),
        });
      case "RESOLVE_HOLD":
        return jobsResolveHold(jobId, {
          ...common,
          hold_id: required(body.hold_id, "Hold"),
          reason: required(body.reason, "Reason"),
        });
      case "COMPLETE":
        return jobsComplete(jobId, {
          ...common,
          statement: required(body.statement, "Completion statement"),
        });
      case "CLOSE":
        return jobsClose(jobId, {
          ...common,
          assessment_id: required(body.assessment_id, "Readiness assessment"),
          statement: body.statement,
        });
    }
  })();
  if (result.status !== 200)
    throw new Error("The operation was not confirmed.");
  return result.data.data;
}
