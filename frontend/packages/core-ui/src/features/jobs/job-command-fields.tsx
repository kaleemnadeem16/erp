import { JobCommandOptions } from "./job-command-options";
import { Label } from "../../components/ui/label";
import { Textarea } from "../../components/ui/textarea";
import { JobAssigneeCombobox } from "./job-assignee-combobox";
import { formatDateTime } from "../../lib/format-date";
import type {
  JobContext,
  JobOperation,
  JobWriteBody,
} from "./job-operation-contract";

export type JobDraft = {
  employee: { id: number; name: string } | null;
  reason: string;
  statement: string;
  hold: string;
  instructions: string;
  intendedFrom: string;
  intendedUntil: string;
  occurredAt: string;
  precision: string;
};
export const emptyJobDraft: JobDraft = {
  employee: null,
  reason: "",
  statement: "",
  hold: "",
  instructions: "",
  intendedFrom: "",
  intendedUntil: "",
  occurredAt: "",
  precision: "",
};

/** Keep caller-claimed timestamps explicit about offset; never manufacture a claimed occurrence from server time. */
function timestamp(value: string): string | undefined {
  if (!value.trim()) return undefined;
  if (
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(
      value.trim(),
    ) ||
    Number.isNaN(Date.parse(value))
  ) {
    throw new Error(
      "Use an ISO timestamp with an explicit offset, for example 2026-11-24T09:00:00+05:00.",
    );
  }
  return new Date(value).toISOString();
}
export function jobDraftFields(
  operation: JobOperation,
  draft: JobDraft,
  generation: number,
  assessmentId?: number,
): Omit<JobWriteBody, "request_key" | "command_identity"> {
  const fields: Omit<JobWriteBody, "request_key" | "command_identity"> = {
    expected_generation: generation,
  };
  if (operation === "ASSESS_CLOSEOUT") return fields;
  if (operation === "ASSIGN") {
    if (!draft.employee) throw new Error("Choose a responsible employee.");
    fields.assignee_id = draft.employee.id;
    fields.instructions = draft.instructions.trim() || undefined;
    fields.intended_from = timestamp(draft.intendedFrom);
    fields.intended_until = timestamp(draft.intendedUntil);
    if (
      fields.intended_until &&
      (!fields.intended_from ||
        Date.parse(fields.intended_until) < Date.parse(fields.intended_from))
    )
      throw new Error(
        "An intended end requires a start and cannot precede it.",
      );
  }
  if (["ASSIGN", "START", "HOLD", "RESOLVE_HOLD"].includes(operation)) {
    fields.reason = draft.reason.trim() || undefined;
    if (["HOLD", "RESOLVE_HOLD"].includes(operation) && !fields.reason)
      throw new Error("Enter a reason.");
  }
  if (operation === "RESOLVE_HOLD") {
    if (!draft.hold) throw new Error("Choose the open hold to resolve.");
    fields.hold_id = Number(draft.hold);
  }
  if (operation === "CLOSE") {
    if (!assessmentId)
      throw new Error("Record a current READY closeout assessment first.");
    fields.assessment_id = assessmentId;
    fields.statement = draft.statement.trim() || undefined;
  }
  if (operation === "COMPLETE")
    fields.statement = draft.statement.trim() || undefined;
  fields.occurred_at = timestamp(draft.occurredAt);
  if (draft.precision.trim())
    fields.occurrence_precision = draft.precision.trim();
  return fields;
}

export function JobCommandFields({
  operation,
  context,
  draft,
  setDraft,
  disabled,
  actorId,
  errors,
}: {
  operation: JobOperation;
  context: JobContext;
  draft: JobDraft;
  setDraft: (draft: JobDraft) => void;
  disabled: boolean;
  actorId: number;
  errors: Record<string, string[]>;
}) {
  const field = (key: keyof JobDraft, value: string) =>
    setDraft({ ...draft, [key]: value });
  const error = (name: string) =>
    errors[name]?.length ? (
      <p
        id={`job-error-${name}`}
        role="alert"
        className="text-sm text-destructive"
      >
        {errors[name]?.join(" ")}
      </p>
    ) : null;
  return (
    <fieldset
      disabled={disabled}
      aria-label="Operation fields"
      className="space-y-4 min-w-0"
    >
      {operation === "ASSIGN" && (
        <div className="space-y-2">
          <Label htmlFor="job-assignee">Responsible employee</Label>
          <JobAssigneeCombobox
            jobId={context.job_id}
            actorId={actorId}
            value={draft.employee}
            onChange={(employee) => setDraft({ ...draft, employee })}
            disabled={disabled}
          />
          {error("assignee_id")}
        </div>
      )}
      {operation === "RESOLVE_HOLD" && (
        <div className="space-y-2">
          <Label htmlFor="job-hold">Open hold</Label>
          <select
            id="job-hold"
            className="w-full min-w-0 rounded-md border border-input bg-background p-2 text-foreground"
            value={draft.hold}
            disabled={disabled}
            onChange={(e) => field("hold", e.target.value)}
            required
          >
            <option value="">Choose one hold</option>
            {context.open_holds?.map((hold) => (
              <option key={hold.id} value={hold.id}>
                Hold {hold.id} · {hold.reason} ·{" "}
                {formatDateTime(hold.recorded_at)}
              </option>
            ))}
          </select>
          {error("hold_id")}
        </div>
      )}
      {["ASSIGN", "START", "HOLD", "RESOLVE_HOLD"].includes(operation) && (
        <div className="space-y-2">
          <Label htmlFor="job-reason">
            Reason{["ASSIGN", "START"].includes(operation) ? " (optional)" : ""}
          </Label>
          <Textarea
            id="job-reason"
            maxLength={1000}
            value={draft.reason}
            disabled={disabled}
            required={["HOLD", "RESOLVE_HOLD"].includes(operation)}
            aria-invalid={Boolean(errors.reason)}
            aria-describedby={errors.reason ? "job-error-reason" : undefined}
            onChange={(e) => field("reason", e.target.value)}
          />
          {error("reason")}
        </div>
      )}
      {["COMPLETE", "CLOSE"].includes(operation) && (
        <div className="space-y-2">
          <Label htmlFor="job-statement">
            {operation === "COMPLETE"
              ? "Whole-deliverable completion statement"
              : "Closeout statement (optional)"}
          </Label>
          <Textarea
            id="job-statement"
            maxLength={1000}
            value={draft.statement}
            disabled={disabled}
            required={operation === "COMPLETE"}
            onChange={(e) => field("statement", e.target.value)}
          />
          {error("statement")}
        </div>
      )}
      <JobCommandOptions
        operation={operation}
        draft={draft}
        disabled={disabled}
        field={field}
        error={error}
      />
    </fieldset>
  );
}
