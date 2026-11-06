import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "../../components/ui/button";
import { Form } from "../../components/ui/form";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import {
  applyApiErrorsToForm,
  describeLoadError,
  getApiReasonCode,
  isApiError,
  isTransientFailure,
} from "../../lib/api-errors";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import { IntentKey } from "./request-key";
import { FamilyAccessFields } from "./quotation-family-access-fields";
import {
  ACCESS_REFUSALS,
  type AccessIntent,
  type AccessOutcome,
  type FamilyAccess,
  type FamilyAccessGateway,
} from "./quotation-family-access-contract";
import {
  ACCESS_ACTION_LABELS,
  accessFormDefaults,
  accessFormSchema,
  accessIntent,
  mayManageAccess,
  savedAccessDraftSchema,
  type AccessFormMode,
  type AccessFormValues,
} from "./quotation-family-access-form-model";

type PendingAttempt = { intent: AccessIntent; key: string };
export function accessRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  return code && ACCESS_REFUSALS[code]
    ? ACCESS_REFUSALS[code]
    : describeLoadError(error);
}

export function FamilyAccessForm({
  snapshot,
  mode,
  gateway,
  onClose,
  onWritten,
  onUnavailable,
}: {
  snapshot: FamilyAccess;
  mode: AccessFormMode;
  gateway: FamilyAccessGateway;
  onClose: () => void;
  onWritten: (result: AccessOutcome) => void;
  onUnavailable: () => void;
}) {
  const storage = `quotation-access:${snapshot.family_id}:${mode.operation}:${mode.operation === "revoke" ? mode.share.id : ""}`;
  const [restored] = useState(() => {
    const parsed = savedAccessDraftSchema.safeParse(
      readDraft<unknown>(storage)?.value,
    );
    if (
      !parsed.success ||
      (parsed.data.pending &&
        parsed.data.pending.intent.operation !== mode.operation)
    )
      return null;
    if (
      mode.operation === "revoke" &&
      parsed.data.pending?.intent.operation === "revoke" &&
      parsed.data.pending.intent.grant_id !== mode.share.id
    )
      return null;
    return parsed.data;
  });
  const [baseline, setBaseline] = useState(() => ({
    ...snapshot,
    ownership_generation: restored?.ownership ?? snapshot.ownership_generation,
    access_generation: restored?.access ?? snapshot.access_generation,
  }));
  const [pendingAttempt, setPendingAttempt] = useState<PendingAttempt | null>(
    restored?.pending ?? null,
  );
  const [phase, setPhase] = useState<"edit" | "review">(
    pendingAttempt ? "review" : "edit",
  );
  const [needsRefresh, setNeedsRefresh] = useState(
    Boolean(
      restored &&
      !pendingAttempt &&
      (restored.ownership !== snapshot.ownership_generation ||
        restored.access !== snapshot.access_generation),
    ),
  );
  const [pending, setPending] = useState(false);
  const [discard, setDiscard] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const busy = useRef(false);
  const finished = useRef(false);
  const keys = useRef(new IntentKey());
  const form = useForm<AccessFormValues>({
    defaultValues: restored?.fields ?? accessFormDefaults(snapshot, mode),
    resolver: zodResolver(
      accessFormSchema(mode, baseline.allowed_actions.delegate_prepare),
    ),
  });
  const dirty = form.formState.isDirty;
  useEffect(() => {
    const persist = () => {
      if (finished.current) return;
      if (form.formState.isDirty || restored || pendingAttempt)
        writeDraft(storage, {
          fields: form.getValues(),
          ownership: baseline.ownership_generation,
          access: baseline.access_generation,
          pending: pendingAttempt,
        });
      else clearDraft(storage);
    };
    persist();
    const watch = form.watch(persist);
    return () => watch.unsubscribe();
  }, [
    form,
    storage,
    restored,
    baseline.ownership_generation,
    baseline.access_generation,
    pendingAttempt,
    dirty,
  ]);
  useEffect(() => {
    const guard = (event: BeforeUnloadEvent) => {
      if (dirty || pendingAttempt) {
        event.preventDefault();
        event.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty, pendingAttempt]);
  const requestClose = () => {
    if (busy.current) return;
    if (pendingAttempt) {
      onClose();
      return;
    }
    if (dirty || restored) setDiscard(true);
    else onClose();
  };
  async function refresh() {
    if (busy.current || pendingAttempt) return;
    busy.current = true;
    setPending(true);
    setError(null);
    try {
      setBaseline(await gateway.read(snapshot.family_id));
      setNeedsRefresh(false);
      setPhase("edit");
      form.clearErrors();
    } catch (failure) {
      setError(accessRefusalMessage(failure));
      if (isApiError(failure) && [401, 403, 404].includes(failure.status))
        onUnavailable();
    } finally {
      busy.current = false;
      setPending(false);
    }
  }
  async function confirm() {
    if (busy.current || needsRefresh || !mayManageAccess(baseline, mode))
      return;
    busy.current = true;
    let intent: AccessIntent;
    let key: string;
    try {
      if (!pendingAttempt && !(await form.trigger())) {
        busy.current = false;
        setPhase("edit");
        return;
      }
      intent =
        pendingAttempt?.intent ??
        accessIntent(baseline, mode, form.getValues());
      key =
        pendingAttempt?.key ??
        keys.current.for({ family: snapshot.family_id, intent });
    } catch {
      busy.current = false;
      setError("Review the entered values before confirming.");
      setPhase("edit");
      return;
    }
    setPending(true);
    setError(null);
    let written: AccessOutcome | undefined;
    try {
      written = await gateway.write(snapshot.family_id, intent, key);
      keys.current.settle();
      finished.current = true;
      clearDraft(storage);
    } catch (failure) {
      keys.current.settle(failure);
      setError(accessRefusalMessage(failure));
      if (isTransientFailure(failure)) {
        const attempt = { intent, key };
        setPendingAttempt(attempt);
        writeDraft(storage, {
          fields: form.getValues(),
          ownership: baseline.ownership_generation,
          access: baseline.access_generation,
          pending: attempt,
        });
      } else {
        setPendingAttempt(null);
        setPhase("edit");
        const code = getApiReasonCode(failure);
        setNeedsRefresh(
          code === "QUOTATION_OWNERSHIP_STALE" || code === "REQUEST_KEY_REUSED",
        );
        applyApiErrorsToForm(failure, form.setError, [
          "reason",
          "starts_at",
          "ends_at",
          "level",
        ]);
        if (isApiError(failure)) {
          const fields = failure.body?.errors;
          const candidateError =
            fields?.owner_id?.[0] ??
            fields?.recipient_id?.[0] ??
            fields?.access_unit_id?.[0];
          if (candidateError)
            form.setError("candidate", {
              type: "server",
              message: candidateError,
            });
          if ([401, 403, 404].includes(failure.status)) onUnavailable();
        }
      }
    } finally {
      busy.current = false;
      setPending(false);
    }
    if (written) onWritten(written);
  }
  const values = form.watch();
  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) requestClose();
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{ACCESS_ACTION_LABELS[mode.operation]}</DialogTitle>
          <DialogDescription>
            {mode.operation === "revoke"
              ? `End the share for ${mode.share.recipient_name}. Expired or invalidated shares may also be revoked.`
              : "Review the proposed change before confirming. A change of responsibility or unit may remove your own access."}
          </DialogDescription>
        </DialogHeader>
        {restored && (
          <p role="status" className="text-sm text-muted-foreground">
            Restored an unfinished change from this tab.
          </p>
        )}
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        {pendingAttempt && (
          <p role="status" className="text-sm">
            The server may have applied this change. Retry the same request
            before editing. Closing keeps the retry details in this tab.
          </p>
        )}
        {needsRefresh && (
          <p role="status" className="text-sm">
            Refresh the current details, then review this change again. Your
            input will be kept.
          </p>
        )}
        {!mayManageAccess(baseline, mode) && (
          <p role="alert" className="text-sm">
            Your current access does not allow this change.
          </p>
        )}
        <Form {...form}>
          <form
            className="space-y-4"
            onSubmit={form.handleSubmit(() => {
              setError(null);
              setPhase("review");
            })}
          >
            {phase === "edit" ? (
              <FamilyAccessFields
                form={form}
                snapshot={baseline}
                mode={mode}
                gateway={gateway}
                pending={pending || pendingAttempt !== null}
              />
            ) : (
              <div
                className="space-y-3 text-sm"
                aria-label="Review access change"
              >
                <p>
                  <strong>
                    {mode.operation === "unit"
                      ? "Unit"
                      : mode.operation === "revoke"
                        ? "Recipient"
                        : "Person"}
                    :{" "}
                  </strong>
                  {mode.operation === "revoke"
                    ? mode.share.recipient_name
                    : mode.operation === "unit" && values.clear_unit
                      ? "No access unit"
                      : values.candidate?.name}
                </p>
                {mode.operation === "grant" && (
                  <>
                    <p>
                      <strong>Access: </strong>
                      {values.level === "PREPARE"
                        ? "View and prepare"
                        : "View only"}
                    </p>
                    <p>
                      <strong>Starts: </strong>
                      {values.starts_at || "Now (server time)"}
                    </p>
                    <p>
                      <strong>Ends: </strong>
                      {values.ends_at || "No fixed expiry"}
                    </p>
                  </>
                )}
                <p className="whitespace-pre-wrap break-words">
                  <strong>Reason: </strong>
                  {values.reason}
                </p>
                <p className="text-muted-foreground">
                  Quotation permissions remain independent. No issue, repair,
                  file or onward-management right is granted.
                </p>
              </div>
            )}
            <DialogFooter className="flex-wrap gap-2">
              {discard ? (
                <div
                  role="alert"
                  className="flex w-full flex-wrap items-center gap-2"
                >
                  <span>Discard these unsaved changes?</span>
                  <Button
                    type="button"
                    variant="outline"
                    autoFocus
                    onClick={() => setDiscard(false)}
                  >
                    Keep editing
                  </Button>
                  <Button
                    type="button"
                    variant="destructive"
                    onClick={() => {
                      finished.current = true;
                      clearDraft(storage);
                      onClose();
                    }}
                  >
                    Discard changes
                  </Button>
                </div>
              ) : (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    disabled={pending}
                    onClick={requestClose}
                  >
                    {pendingAttempt ? "Close for now" : "Cancel"}
                  </Button>
                  {needsRefresh && (
                    <Button
                      type="button"
                      variant="outline"
                      disabled={pending}
                      onClick={() => void refresh()}
                    >
                      Refresh current details
                    </Button>
                  )}
                  {phase === "review" && !pendingAttempt && (
                    <Button
                      type="button"
                      variant="outline"
                      disabled={pending}
                      onClick={() => setPhase("edit")}
                    >
                      Back to change
                    </Button>
                  )}
                  {phase === "edit" ? (
                    <Button
                      type="submit"
                      disabled={
                        pending ||
                        needsRefresh ||
                        !mayManageAccess(baseline, mode)
                      }
                    >
                      Review change
                    </Button>
                  ) : (
                    <Button
                      type="button"
                      disabled={
                        pending ||
                        needsRefresh ||
                        !mayManageAccess(baseline, mode)
                      }
                      onClick={() => void confirm()}
                    >
                      {pending
                        ? "Applying…"
                        : pendingAttempt
                          ? "Retry same change"
                          : "Confirm change"}
                    </Button>
                  )}
                </>
              )}
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
