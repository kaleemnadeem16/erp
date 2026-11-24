import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { Button } from "../../components/ui/button";
import { Label } from "../../components/ui/label";
import { Skeleton } from "../../components/ui/skeleton";
import { ListError } from "../../components/data-table/list-error";
import { StatusBadge } from "../../components/ui/status-badge";
import { formatDateTime } from "../../lib/format-date";
import { AccessDenied } from "../../routes/permission-route";
import { JobStatus } from "./job-columns";
import { JobCommandFields } from "./job-command-fields";
import { jobOperations, blockerAdvice } from "./job-operation-contract";
import { useJobCommand, type JobCommandDialogProps } from "./use-job-command";

export function JobCommandDialog(props: JobCommandDialogProps) {
  const { operation, onClose } = props;
  const {
    dirty,
    actorId,
    allowed,
    uncertain,
    draft,
    setDraft,
    confirmed,
    setConfirmed,
    assessmentConfirmed,
    setAssessmentConfirmed,
    pending,
    error,
    errors,
    assessment,
    discard,
    setDiscard,
    notice,
    terminal,
    context,
    profile,
    close,
    submit,
    problem,
    blocked,
  } = useJobCommand(props);
  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) close();
      }}
    >
      <DialogContent
        className="max-w-xl"
        onEscapeKeyDown={(event) => {
          if (pending || uncertain || dirty) {
            event.preventDefault();
            if (!pending && !uncertain) setDiscard(true);
          }
        }}
        onPointerDownOutside={(event) => event.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle>{jobOperations[operation].label}</DialogTitle>
          <DialogDescription>
            {jobOperations[operation].consequence}
          </DialogDescription>
        </DialogHeader>
        {!allowed ? (
          <AccessDenied />
        ) : terminal ? (
          <p role="alert">
            This Job operation is unavailable with your current access. Close
            this review and reload before continuing.
          </p>
        ) : context.isLoading ? (
          <Skeleton className="h-24 w-full" />
        ) : context.isError || !profile ? (
          <ListError
            message="Couldn’t load the authorised Job context."
            error={context.error}
            onRetry={() => void context.refetch()}
          />
        ) : (
          <>
            <div className="rounded-md border border-border p-3 space-y-2 break-words">
              <p className="font-semibold">{profile.number}</p>
              <JobStatus state={profile.state} />
              <p className="text-sm text-muted-foreground">
                Review generation{" "}
                {uncertain?.body.expected_generation ?? profile.generation}
              </p>
              {profile.responsibility_id != null && (
                <p>
                  Current responsibility reference: {profile.responsibility_id}
                </p>
              )}
              {profile.has_responsibility !== undefined && (
                <p>
                  {profile.has_responsibility
                    ? "Responsibility is recorded."
                    : "No responsibility is recorded."}
                </p>
              )}
              {profile.open_hold_count !== undefined && (
                <p>
                  {profile.open_hold_count} open{" "}
                  {profile.open_hold_count === 1 ? "hold" : "holds"}
                </p>
              )}
              {profile.completion_recorded_at && (
                <p>
                  Completion recorded{" "}
                  {formatDateTime(profile.completion_recorded_at)}
                </p>
              )}
            </div>
            {problem && !uncertain && (
              <p role="status" className="text-sm">
                {problem}
              </p>
            )}
            {operation !== "COMPLETE" && (
              <JobCommandFields
                operation={operation}
                context={profile}
                draft={draft}
                setDraft={(value) => {
                  setDraft(value);
                  setConfirmed(false);
                  setDiscard(false);
                }}
                disabled={pending || Boolean(uncertain)}
                actorId={actorId}
                errors={errors}
              />
            )}
            {operation === "CLOSE" && !uncertain && (
              <>
                <div className="flex items-start gap-3">
                  <input
                    id="job-assessment-confirm"
                    type="checkbox"
                    className="mt-1 size-5 shrink-0"
                    checked={assessmentConfirmed}
                    disabled={pending || context.isFetching}
                    onChange={(event) =>
                      setAssessmentConfirmed(event.target.checked)
                    }
                  />
                  <Label htmlFor="job-assessment-confirm">
                    Record an immutable closeout readiness assessment for{" "}
                    {profile.number}. This will not close the Job.
                  </Label>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  disabled={
                    pending ||
                    context.isFetching ||
                    profile.state === "CLOSED" ||
                    !assessmentConfirmed
                  }
                  onClick={() => void submit("ASSESS_CLOSEOUT")}
                >
                  Assess closeout readiness
                </Button>
              </>
            )}
            {assessment && (
              <section
                aria-label="Closeout assessment"
                className="rounded-md border border-border p-3 space-y-2"
              >
                <StatusBadge
                  tone={assessment.status === "READY" ? "success" : "pending"}
                >
                  {assessment.status}
                </StatusBadge>
                <p>
                  {assessment.status === "READY"
                    ? "The Job was ready at this assessment. Closing will check all conditions again."
                    : "The Job cannot be closed yet."}
                </p>
                <ul className="list-disc space-y-2 pl-5">
                  {assessment.blockers.map((code) => (
                    <li key={code}>
                      {blockerAdvice[code] ??
                        "A prerequisite needs review by the responsible owner."}
                    </li>
                  ))}
                </ul>
                {assessment.generation !== profile.generation && (
                  <p role="alert">
                    The Job changed after assessment. Assess it again.
                  </p>
                )}
              </section>
            )}
            {notice && (
              <p role="status" className="text-sm">
                {notice}
              </p>
            )}
            {error && (
              <p role="alert" className="text-sm text-destructive break-words">
                {error}
              </p>
            )}
            {uncertain ? (
              <p role="alert" className="text-sm">
                The result is unconfirmed. Inputs and identities are retained.
                Retry the original request to establish its outcome before
                starting a different command.
              </p>
            ) : (
              !problem &&
              (operation !== "CLOSE" || !blocked) && (
                <div className="flex items-start gap-3">
                  <input
                    id="job-command-confirm"
                    type="checkbox"
                    className="mt-1 size-5 shrink-0"
                    checked={confirmed}
                    disabled={pending || context.isFetching}
                    onChange={(e) => setConfirmed(e.target.checked)}
                  />
                  <Label htmlFor="job-command-confirm">
                    I confirm this operation for {profile.number} after
                    reviewing the current context.
                  </Label>
                </div>
              )
            )}
            {discard && (
              <div className="rounded-md border border-border p-3 space-y-2">
                <p>Discard these unsaved changes?</p>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDiscard(false)}
                >
                  Keep editing
                </Button>
                <Button type="button" variant="destructive" onClick={onClose}>
                  Discard changes
                </Button>
              </div>
            )}
          </>
        )}
        <DialogFooter className="gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={pending || Boolean(uncertain)}
            onClick={close}
          >
            Cancel
          </Button>
          {allowed && profile && (
            <Button
              type="button"
              className="h-11 md:h-9"
              disabled={pending || (!uncertain && (blocked || !confirmed))}
              onClick={() => void submit()}
            >
              {pending
                ? "Recording…"
                : uncertain
                  ? "Retry original request"
                  : jobOperations[operation].label}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
