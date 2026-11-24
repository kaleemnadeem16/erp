import { useState } from "react";
import { Button } from "../../components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "../../components/ui/dropdown-menu";
import { useAuthStore } from "../../stores/auth-store";
import { JobCommandDialog } from "./job-command-dialog";
import { jobOperations, type JobOperation } from "./job-operation-contract";

export function JobOperationActions({
  jobId,
  state,
  held = false,
  compact = false,
}: {
  jobId: number;
  state: string;
  held?: boolean;
  compact?: boolean;
}) {
  const permissions = useAuthStore((s) => s.user?.permissions ?? []);
  const [operation, setOperation] = useState<JobOperation | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const options = (Object.keys(jobOperations) as JobOperation[]).filter((op) =>
    permissions.includes(jobOperations[op].duty),
  );
  if (!options.length) return null;
  function unavailable(op: JobOperation): string | undefined {
    if (state === "CLOSED") return "This Job is closed.";
    if (op === "COMPLETE" && (state !== "ACTIVE" || held))
      return "Completion requires active work with every hold resolved.";
    if (op === "ASSESS_CLOSEOUT") return undefined;
    if (op === "CLOSE")
      return state !== "COMPLETED"
        ? "Complete the Job before closing it."
        : undefined;
    if (state === "COMPLETED") return "The Job is completed.";
    if (op === "START" && state !== "RELEASED")
      return "Work has already started.";
    if (held && ["START", "ASSIGN"].includes(op))
      return "Resolve all open holds first.";
    if (op === "RESOLVE_HOLD" && !held) return "There are no open holds.";
    return undefined;
  }
  return (
    <div className="space-y-2" onClick={(event) => event.stopPropagation()}>
      {compact ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" aria-label={`Actions for Job ${jobId}`}>
              Job actions
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {options.map((op) => (
              <DropdownMenuItem
                key={op}
                disabled={Boolean(unavailable(op))}
                title={unavailable(op)}
                onSelect={() => setOperation(op)}
              >
                {jobOperations[op].label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      ) : (
        <div className="flex flex-wrap gap-2" aria-label="Job operations">
          {options.map((op) => (
            <Button
              key={op}
              type="button"
              variant="outline"
              className="h-11 md:h-9"
              disabled={Boolean(unavailable(op))}
              title={unavailable(op)}
              onClick={() => setOperation(op)}
            >
              {jobOperations[op].label}
            </Button>
          ))}
        </div>
      )}
      {notice && (
        <p role="status" className="text-sm break-words">
          {notice}
        </p>
      )}
      {operation && (
        <JobCommandDialog
          key={`${jobId}:${operation}`}
          jobId={jobId}
          operation={operation}
          onClose={() => setOperation(null)}
          onSuccess={setNotice}
        />
      )}
    </div>
  );
}
