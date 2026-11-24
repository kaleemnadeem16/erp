import type {
  JobOperationContextResource,
  JobCloseoutAssessmentResource,
} from "@erp/core-api";
import { isApiError, isTransientFailure } from "../../lib/api-errors";

export const jobOperations = {
  ASSIGN: {
    duty: "job.assign",
    label: "Assign responsibility",
    consequence:
      "Record who is responsible for this Job. This does not start work or grant access.",
  },
  START: {
    duty: "job.start",
    label: "Start work",
    consequence:
      "Record that work has started. This advances the Job to Active.",
  },
  HOLD: {
    duty: "job.hold",
    label: "Place on hold",
    consequence:
      "Record a new independent hold. Ordinary work stays blocked until every open hold is resolved.",
  },
  RESOLVE_HOLD: {
    duty: "job.hold.resolve",
    label: "Resolve a hold",
    consequence:
      "Resolve only the selected hold. Other holds remain open; work does not start automatically.",
  },
  COMPLETE: {
    duty: "job.complete",
    label: "Complete deliverable",
    consequence:
      "Attest that the whole original agreed deliverable and its conditions have been fulfilled.",
  },
  ASSESS_CLOSEOUT: {
    duty: "job.close",
    label: "Assess closeout readiness",
    consequence:
      "Record an immutable readiness assessment. This does not close the Job.",
  },
  CLOSE: {
    duty: "job.close",
    label: "Close Job",
    consequence:
      "Permanently close the completed Job operationally. This does not settle an invoice or balance.",
  },
} as const;
export type JobOperation = keyof typeof jobOperations;
export type JobContext = JobOperationContextResource & {
  baseline_version?: number;
  deliverable?: string;
  agreed_conditions?: string;
};
export type JobAssessment = JobCloseoutAssessmentResource;
export const jobCommandDuties = [
  ...new Set(Object.values(jobOperations).map((o) => o.duty)),
];
export const isJobOperation = (value: string | null): value is JobOperation =>
  value !== null && Object.hasOwn(jobOperations, value);

export function operationProblem(
  operation: JobOperation,
  context: JobContext,
): string | null {
  if (context.state === "CLOSED")
    return "This Job is closed. Its recorded history cannot be changed.";
  if (operation === "ASSESS_CLOSEOUT") return null;
  if (operation === "CLOSE")
    return context.state !== "COMPLETED"
      ? "Only a completed Job can be closed."
      : null;
  if (operation === "COMPLETE")
    return context.state !== "ACTIVE"
      ? "Only active Jobs can be completed."
      : context.baseline_version === undefined
        ? "The agreed work is unavailable."
        : null;
  if (!["RELEASED", "ACTIVE"].includes(context.state))
    return "This operation needs a released or active Job.";
  if (operation === "START") {
    if (context.state !== "RELEASED") return "Work has already started.";
    if (!context.has_responsibility)
      return "Assign responsibility before starting work.";
    if (context.open_hold_count)
      return "Resolve every open hold before starting work.";
  }
  if (operation === "RESOLVE_HOLD" && !context.open_holds?.length)
    return "There are no open holds to resolve.";
  return null;
}

export const blockerAdvice: Record<string, string> = {
  JOB_NOT_COMPLETED: "Complete the agreed deliverable before closeout.",
  COMPLETION_MISSING:
    "Completion evidence is unavailable. Ask the responsible reviewer to investigate.",
  OPEN_HOLDS: "Resolve every open hold, then assess again.",
  SOURCE_NOT_READY:
    "The source prerequisites are not ready. Ask the handover owner to review them, then assess again.",
  SOURCE_CONDITIONS_CHANGED:
    "The agreed conditions need review by the handover owner before closeout.",
  FULFILMENT_MISSING:
    "Fulfilment evidence is unavailable. Ask the responsible reviewer to investigate.",
  FULFILMENT_MISMATCH:
    "Fulfilment evidence needs review. Do not close this Job until it is resolved.",
};

export const jobRefusalAdvice: Record<string, string> = {
  JOB_HELD: "Resolve every open hold before continuing.",
  RESPONSIBILITY_REQUIRED: "Assign responsibility before starting work.",
  ASSIGNEE_NOT_ELIGIBLE: "Choose a currently eligible employee again.",
  HOLD_NOT_FOUND: "Choose a current open hold after refreshing.",
  HOLD_ALREADY_RESOLVED:
    "That hold has already been resolved. Choose a current open hold.",
  NOT_READY_TO_START:
    "Ask the handover owner to review the source prerequisites.",
  NOT_READY_TO_COMPLETE:
    "Ask the handover owner to review the source prerequisites.",
  STALE_GENERATION:
    "Review the refreshed Job context and confirm a new attempt.",
  CLOSEOUT_BLOCKED:
    "Assess closeout readiness again and resolve the reported blockers.",
  CLOSEOUT_ASSESSMENT_REQUIRED:
    "Record a current READY closeout assessment before closing.",
  REQUEST_KEY_REUSED:
    "Review the current Job before starting a different command.",
  COMMAND_CONFLICT:
    "Review the current Job before starting a different command.",
};

export function retainJobAttempt(error: unknown): boolean {
  return (
    isTransientFailure(error) ||
    (isApiError(error) && error.body?.reason_code === "CONCURRENT_CHANGE")
  );
}

function uuid(): string {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6]! & 15) | 64;
  bytes[8] = (bytes[8]! & 63) | 128;
  const h = [...bytes].map((n) => n.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export type JobWriteBody = {
  expected_generation: number;
  request_key: string;
  command_identity?: string;
  assignee_id?: number;
  hold_id?: number;
  assessment_id?: number;
  reason?: string;
  statement?: string;
  instructions?: string;
  intended_from?: string;
  intended_until?: string;
  occurred_at?: string;
  occurrence_precision?: string;
};

/** Both retry identities are bound to exactly one Job/operation/generation/body, and retained after uncertain failures. */
export class JobCommandAttempt {
  private current: { intent: string; body: JobWriteBody } | null = null;
  constructor(private readonly mint: () => string = uuid) {}
  for(
    jobId: number,
    operation: JobOperation,
    fields: Omit<JobWriteBody, "request_key" | "command_identity">,
  ): JobWriteBody {
    const intent = JSON.stringify({ jobId, operation, fields });
    if (!this.current || this.current.intent !== intent) {
      this.current = {
        intent,
        body: {
          ...fields,
          request_key: this.mint(),
          ...(operation === "ASSESS_CLOSEOUT"
            ? {}
            : { command_identity: this.mint() }),
        },
      };
    }
    return this.current.body;
  }
  settle(error?: unknown) {
    if (error === undefined || !retainJobAttempt(error)) this.current = null;
  }
}

/** The API locates Jobs by numeric reference. Accept a known reference or existing Job link, never enumerate the general Jobs list. */
export function parseJobReference(value: string): number | null {
  const text = value.trim();
  const match = /^\d+$/.test(text)
    ? text
    : /(?:^|\/)jobs\/(\d+)(?:[/?#]|$)/.exec(text)?.[1];
  const id = Number(match);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}
