import { useState } from "react";
import { useSearchParams } from "react-router";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { AccessDenied } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { JobCommandDialog } from "./job-command-dialog";
import {
  jobOperations,
  isJobOperation,
  parseJobReference,
  type JobOperation,
} from "./job-operation-contract";

/** A minimal task entry for known Jobs. It never mounts or requests a general Jobs list/detail/history. */
export function JobOperationScreen() {
  const [params, setParams] = useSearchParams();
  const permissions = useAuthStore((s) => s.user?.permissions ?? []);
  const options = (Object.keys(jobOperations) as JobOperation[]).filter((op) =>
    permissions.includes(jobOperations[op].duty),
  );
  const initial = params.get("operation");
  const [operation, setOperation] = useState<JobOperation | "">(
    isJobOperation(initial) && options.includes(initial)
      ? initial
      : (options[0] ?? ""),
  );
  const [reference, setReference] = useState(params.get("job") ?? "");
  const [active, setActive] = useState<{
    id: number;
    operation: JobOperation;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  if (!options.length) return <AccessDenied />;
  return (
    <ScreenTemplate kind="form">
      <PageHeader
        title="Job operations"
        description="Carry out an authorised operation for a known Job reference."
      />
      <form
        className="max-w-xl space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          const id = parseJobReference(reference);
          setError(null);
          setNotice(null);
          if (!id) {
            setError(
              "Enter a valid numeric Job reference or paste a Job link.",
            );
            return;
          }
          if (!operation || !options.includes(operation)) {
            setError("Select an operation you are permitted to perform.");
            return;
          }
          setParams({ job: String(id), operation }, { replace: true });
          setActive({ id, operation });
        }}
      >
        <div className="space-y-2">
          <Label htmlFor="known-job-reference">Job reference</Label>
          <Input
            id="known-job-reference"
            value={reference}
            required
            aria-describedby="known-job-help"
            onChange={(event) => setReference(event.target.value)}
          />
          <p id="known-job-help" className="text-sm text-muted-foreground">
            Enter the numeric reference from your work instruction or paste a
            Job link, for example 7 or /jobs/7. The Job number will be confirmed
            before submission.
          </p>
        </div>
        <div className="space-y-2">
          <Label htmlFor="known-job-operation">Operation</Label>
          <select
            id="known-job-operation"
            className="w-full rounded-md border border-input bg-background p-2 text-foreground"
            value={operation}
            onChange={(event) => {
              if (isJobOperation(event.target.value))
                setOperation(event.target.value);
            }}
          >
            {options.map((op) => (
              <option key={op} value={op}>
                {jobOperations[op].label}
              </option>
            ))}
          </select>
        </div>
        {operation === "COMPLETE" && (
          <p role="status">
            Review the exact agreed work and completion conditions before
            attesting completion. Contact the responsible reviewer if they are
            unavailable.
          </p>
        )}
        {error && (
          <p role="alert" className="text-destructive">
            {error}
          </p>
        )}
        <Button type="submit" className="h-11 md:h-9" disabled={!operation}>
          Review Job operation
        </Button>
      </form>
      {notice && <p role="status">{notice}</p>}
      {active && (
        <JobCommandDialog
          key={`${active.id}:${active.operation}`}
          jobId={active.id}
          operation={active.operation}
          onClose={() => setActive(null)}
          onSuccess={setNotice}
        />
      )}
    </ScreenTemplate>
  );
}
