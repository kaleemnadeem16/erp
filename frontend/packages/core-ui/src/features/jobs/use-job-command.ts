import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  jobsCompletionAttestationContext,
  jobsOperationContext,
  user as readCurrentUser,
} from "@erp/core-api";
import {
  getApiErrorMessage,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { useAuthStore } from "../../stores/auth-store";
import { usePermission } from "../../hooks/use-permission";
import {
  emptyJobDraft,
  jobDraftFields,
  type JobDraft,
} from "./job-command-fields";
import { sendJobCommand } from "./job-command-gateway";
import {
  jobOperations,
  type JobContext,
  operationProblem,
  retainJobAttempt,
  jobRefusalAdvice,
  JobCommandAttempt,
  type JobAssessment,
  type JobOperation,
  type JobWriteBody,
} from "./job-operation-contract";
export type JobCommandDialogProps = {
  jobId: number;
  operation: JobOperation;
  onClose: () => void;
  onSuccess: (message: string) => void;
};
type Uncertain = {
  operation: JobOperation;
  body: JobWriteBody;
  draft: JobDraft;
};
// Retain an unresolved request across closing/navigation in this session; never share it with another signed-in actor.
const unresolved = new Map<string, Uncertain>();
useAuthStore.subscribe((state, previous) => {
  if (state.user?.id !== previous.user?.id) unresolved.clear();
});

export function useJobCommand({
  jobId,
  operation,
  onClose,
  onSuccess,
}: JobCommandDialogProps) {
  const actorId = useAuthStore((s) => s.user?.id ?? 0);
  const allowed = usePermission(jobOperations[operation].duty);
  const cache = useQueryClient();
  const attempt = useRef(new JobCommandAttempt());
  const submitting = useRef(false);
  const sessionKey = `${actorId}:${jobId}:${operation}`;
  const [uncertain, setUncertain] = useState<Uncertain | null>(
    () => unresolved.get(sessionKey) ?? null,
  );
  const [draft, setDraft] = useState<JobDraft>(
    () => unresolved.get(sessionKey)?.draft ?? { ...emptyJobDraft },
  );
  const [confirmedReview, setConfirmedReview] = useState<string | null>(null);
  const [assessmentConfirmed, setAssessmentConfirmed] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [assessment, setAssessment] = useState<JobAssessment | null>(null);
  const [discard, setDiscard] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [terminal, setTerminal] = useState(false);
  const context = useQuery({
    queryKey: ["jobs-operation", actorId, jobId, operation],
    enabled: allowed && !terminal,
    queryFn: async () => {
      if (operation !== "COMPLETE")
        return jobsOperationContext(jobId, { operation });
      const result = await jobsCompletionAttestationContext(jobId);
      if (result.status !== 200)
        throw new Error("Completion context unavailable.");
      return {
        status: 200 as const,
        data: { data: { ...result.data.data, operation: "COMPLETE" as const } },
      };
    },
    retry: shouldRetryQuery,
    staleTime: 0,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });
  const profile: JobContext | undefined =
    !terminal && context.data?.status === 200
      ? context.data.data.data
      : undefined;
  const review = profile ? JSON.stringify(profile) : null;
  const confirmed = review !== null && confirmedReview === review;
  function setConfirmed(value: boolean) {
    setConfirmedReview(value ? review : null);
  }
  const dirty = JSON.stringify(draft) !== JSON.stringify(emptyJobDraft);
  useEffect(() => {
    if (!dirty && !uncertain && !pending) return;
    const guard = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty, uncertain, pending]);

  useEffect(() => {
    if (
      isApiError(context.error) &&
      [401, 403, 404].includes(context.error.status)
    ) {
      setTerminal(true);
      unresolved.delete(sessionKey);
      setUncertain(null);
      void refresh(true);
    }
  }, [context.error]);

  async function refresh(permissionChanged = false) {
    if (permissionChanged) {
      cache.removeQueries({ queryKey: ["jobs-assignees"] });
      cache.removeQueries({ queryKey: ["jobs-operation"] });
      try {
        const current = await readCurrentUser();
        if (current.status === 200)
          useAuthStore.getState().setUser(current.data.data);
      } catch {
        useAuthStore.getState().setUser(null);
      }
    }
    await cache.invalidateQueries({
      predicate: (query) =>
        typeof query.queryKey[0] === "string" &&
        query.queryKey[0].startsWith("/v1/jobs"),
    });
    if (!permissionChanged) {
      await cache.invalidateQueries({ queryKey: ["jobs-assignees"] });
      await context.refetch();
    }
  }
  function close() {
    if (pending || uncertain) return;
    if (dirty) {
      setDiscard(true);
      return;
    }
    onClose();
  }
  async function submit(mode: JobOperation = operation) {
    if (submitting.current || !profile || !allowed) return;
    if (!uncertain && mode === operation && (!confirmed || blocked)) return;
    submitting.current = true;
    setPending(true);
    setError(null);
    setErrors({});
    setNotice(null);
    let body: JobWriteBody | undefined;
    const actual = uncertain?.operation ?? mode;
    try {
      body =
        uncertain?.body ??
        attempt.current.for(
          jobId,
          actual,
          jobDraftFields(
            actual,
            draft,
            profile.generation,
            assessment?.assessment_id,
          ),
        );
      const result = await sendJobCommand(jobId, actual, body);
      attempt.current.settle();
      unresolved.delete(sessionKey);
      setUncertain(null);
      setConfirmed(false);
      setAssessmentConfirmed(false);
      await refresh();
      if ("assessment_id" in result) {
        setAssessment(result);
        setNotice(
          "The readiness assessment was recorded. Review its outcome below.",
        );
      } else {
        onSuccess(
          `${jobOperations[actual].label}: ${result.replayed ? "original result confirmed" : "recorded successfully"}.`,
        );
        onClose();
      }
    } catch (failure) {
      attempt.current.settle(failure);
      const advice = isApiError(failure)
        ? jobRefusalAdvice[failure.body?.reason_code ?? ""]
        : undefined;
      setError(
        [
          getApiErrorMessage(failure) ??
            "The operation could not be confirmed.",
          advice,
        ]
          .filter(Boolean)
          .join(" "),
      );
      if (retainJobAttempt(failure) && body) {
        const retry = { operation: actual, body, draft };
        unresolved.set(sessionKey, retry);
        setUncertain(retry);
      } else {
        unresolved.delete(sessionKey);
        setUncertain(null);
        setConfirmed(false);
        if (isApiError(failure)) {
          setErrors(failure.body?.errors ?? {});
          if (failure.body?.reason_code === "ASSIGNEE_NOT_ELIGIBLE")
            setDraft({ ...draft, employee: null });
          if (
            ["HOLD_NOT_FOUND", "HOLD_ALREADY_RESOLVED"].includes(
              failure.body?.reason_code ?? "",
            )
          )
            setDraft({ ...draft, hold: "" });
        }
        if (isApiError(failure) && [401, 403, 404].includes(failure.status)) {
          setTerminal(true);
          await refresh(true);
        } else if (isApiError(failure)) {
          await refresh();
          setNotice(
            "Current Job context has been refreshed. Review it before confirming another attempt.",
          );
          setAssessment(null);
        }
      }
    } finally {
      submitting.current = false;
      setPending(false);
    }
  }
  const problem = profile ? operationProblem(operation, profile) : null;
  const ready =
    assessment?.status === "READY" &&
    assessment.generation === profile?.generation &&
    profile?.state === "COMPLETED";
  const blocked =
    Boolean(problem) ||
    context.isFetching ||
    !profile ||
    (operation === "CLOSE" && !ready) ||
    (operation === "COMPLETE" && !draft.statement.trim());
  return {
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
  };
}
