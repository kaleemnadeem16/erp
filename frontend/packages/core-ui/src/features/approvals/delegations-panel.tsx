import { useEffect, useState } from "react";
import {
  useApprovalDelegationsDestroy,
  useApprovalDelegationsIndex,
  useApprovalDelegationsStore,
  useMembersIndex,
  type ApprovalDelegationResource,
} from "@erp/core-api";
import { ChevronDown, Plus, UsersRound, ArrowRight } from "lucide-react";
import { Controller, useForm } from "react-hook-form";
import { Button } from "../../components/ui/button";
import { StatusBadge } from "../../components/ui/status-badge";
import { Collapse } from "../../components/ui/collapse";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "../../components/ui/dialog";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { ListError } from "../../components/data-table";
import {
  applyApiErrorsToForm,
  getApiErrorMessage,
  lookupState,
} from "../../lib/api-errors";
import { usePermission } from "../../hooks/use-permission";
import { useAuthStore } from "../../stores/auth-store";
import { formatDate } from "../../lib/format-date";

const ALL = "ALL";
type CoverageForm = { valid_from: string; valid_to: string; reason: string };

/** Dated approval cover, without granting broader access. Existing backend actor/authority rules remain authoritative. */
export function DelegationsPanel({
  subjectTypes = [],
}: {
  subjectTypes?: { code: string; label: string }[];
}) {
  const query = useApprovalDelegationsIndex();
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const mayManageAll = usePermission("approvals.delegation.manage");
  const actorId = useAuthStore((state) => state.user?.id);
  const store = useApprovalDelegationsStore();
  const revoke = useApprovalDelegationsDestroy();
  const [expanded, setExpanded] = useState(false);
  const [adding, setAdding] = useState(false);
  const [revoking, setRevoking] = useState<ApprovalDelegationResource | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);
  const [delegate, setDelegate] = useState("");
  const [delegateLabel, setDelegateLabel] = useState("");
  const [memberSearch, setMemberSearch] = useState("");
  const [committedSearch, setCommittedSearch] = useState("");
  const [subject, setSubject] = useState(ALL);
  useEffect(() => {
    const timer = window.setTimeout(
      () => setCommittedSearch(memberSearch),
      300,
    );
    return () => window.clearTimeout(timer);
  }, [memberSearch]);
  const members = useMembersIndex(
    { per_page: 25, search: committedSearch || undefined, status: "ACTIVE" },
    { query: { enabled: adding } },
  );
  const memberRows = members.data?.status === 200 ? members.data.data.data : [];
  const form = useForm<CoverageForm>({
    defaultValues: { valid_from: "", valid_to: "", reason: "" },
  });
  const activeCount = rows.filter((row) => row.in_force).length;
  function beginAdding() {
    setError(null);
    setAdding(true);
  }
  function submit(values: CoverageForm) {
    setError(null);
    if (delegate === "") {
      setError("Choose who is covering for you.");
      return;
    }
    store.mutate(
      {
        data: {
          delegate_id: Number(delegate),
          subject_type: subject === ALL ? null : (subject as never),
          valid_from: values.valid_from,
          valid_to: values.valid_to,
          reason: values.reason,
        },
      },
      {
        onSuccess: () => {
          setAdding(false);
          setExpanded(true);
          setDelegate("");
          setDelegateLabel("");
          setSubject(ALL);
          setMemberSearch("");
          form.reset();
          void query.refetch();
        },
        onError: (e) =>
          setError(
            applyApiErrorsToForm(e, form.setError, [
              "valid_from",
              "valid_to",
              "reason",
            ]),
          ),
      },
    );
  }
  return (
    <section
      className="approval-coverage"
      aria-labelledby="delegations-heading"
    >
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3">
          <div className="approval-coverage-icon">
            <UsersRound className="size-5" aria-hidden="true" />
          </div>
          <div className="min-w-0">
            <h2 id="delegations-heading" className="text-sm font-semibold">
              Cover while you are away
              {query.data?.status === 200 && (
                <span className="ml-2 text-xs font-normal text-muted-foreground">
                  {activeCount} active
                </span>
              )}
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
              Let a colleague cover your approvals for a set period.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            aria-expanded={expanded}
            aria-controls="approval-coverage-details"
            onClick={() => setExpanded((value) => !value)}
          >
            Manage coverage
            <ChevronDown
              className={`size-4 transition-transform duration-200 motion-reduce:transition-none ${expanded ? "rotate-180" : ""}`}
              aria-hidden="true"
            />
          </Button>
          <Button
            type="button"
            variant="default"
            size="sm"
            onClick={beginAdding}
          >
            <Plus className="size-4" aria-hidden="true" />
            Delegate my approvals
          </Button>
        </div>
      </div>
      <Collapse open={expanded} id="approval-coverage-details">
        <div className="mt-4 space-y-3 border-t border-border pt-4">
          <p className="text-xs text-muted-foreground">
            Coverage uses your approval authority and limits. It does not grant
            broader access.
            {mayManageAll
              ? " You can manage company-wide coverage."
              : " Only coverage involving you is shown."}
          </p>
          {query.isError ? (
            <ListError
              message="Couldn't load delegations."
              error={query.error}
              onRetry={() => void query.refetch()}
            />
          ) : query.isLoading ? (
            <p role="status" className="text-sm text-muted-foreground">
              Loading coverage…
            </p>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No approval coverage has been set up.
            </p>
          ) : (
            <ul className="approval-coverage-list">
              {rows.map((row) => (
                <li key={row.id} className="approval-coverage-row">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                      <span>{row.delegator_name}</span>
                      <ArrowRight
                        className="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <span>{row.delegate_name}</span>
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {formatDate(row.valid_from)} – {formatDate(row.valid_to)}{" "}
                      ·{" "}
                      {subjectTypes.find(
                        (item) => item.code === row.subject_type,
                      )?.label ??
                        row.subject_type?.toLowerCase().replace(/_/g, " ") ??
                        "All approval types"}
                    </p>
                    {row.reason && (
                      <p className="mt-1 text-xs text-muted-foreground break-words">
                        {row.reason}
                      </p>
                    )}
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <StatusBadge tone={row.in_force ? "success" : "draft"}>
                      {row.in_force
                        ? "In force"
                        : row.revoked_at
                          ? "Revoked"
                          : "Not in force"}
                    </StatusBadge>
                    {!row.revoked_at &&
                      (row.delegator_id === actorId || mayManageAll) && (
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="text-destructive"
                          aria-label={`Revoke delegation to ${row.delegate_name}`}
                          onClick={() => {
                            setError(null);
                            setRevoking(row);
                          }}
                        >
                          Revoke
                        </Button>
                      )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Collapse>
      <Dialog
        open={adding}
        onOpenChange={(open) => {
          if (!store.isPending) setAdding(open);
        }}
      >
        <DialogContent className="approval-controls" onInteractOutside={(event) => event.preventDefault()}>
          <DialogHeader>
            <DialogTitle>Delegate my approvals</DialogTitle>
            <DialogDescription>
              Choose a colleague and dates. They can only act within your
              approval authority.
            </DialogDescription>
          </DialogHeader>
          <form
            className="space-y-4"
            onSubmit={form.handleSubmit(submit)}
            aria-label="Add a delegation"
          >
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
            <div className="space-y-2">
              <Label htmlFor="delegation-colleague">Covered by</Label>
              <RemoteLookupCombobox
                {...lookupState(members, "people")}
                label="Covered by"
                id="delegation-colleague"
                searchPlaceholder="Search colleagues…"
                placeholder="Choose a colleague"
                value={delegate}
                selectedLabel={delegateLabel || undefined}
                className="w-full"
                search={memberSearch}
                onSearchChange={setMemberSearch}
                onValueChange={(value) => {
                  setDelegate(value);
                  setDelegateLabel(
                    memberRows.find((member) => String(member.id) === value)
                      ?.name ?? "",
                  );
                }}
                options={memberRows
                  .filter((member) => member.id !== actorId)
                  .map((member) => ({
                    value: String(member.id),
                    label: `${member.name} (${member.email})`,
                  }))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="delegation-subject">Approval type</Label>
              <Select value={subject} onValueChange={setSubject}>
                <SelectTrigger id="delegation-subject">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL}>All my approval types</SelectItem>
                  {subjectTypes.map((item) => (
                    <SelectItem key={item.code} value={item.code}>
                      {item.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="delegation-from">From</Label>
                <Controller
                  control={form.control}
                  name="valid_from"
                  rules={{ required: "Choose a start date." }}
                  render={({ field }) => (
                    <DateField
                      {...field}
                      id="delegation-from"
                      aria-label="From"
                      aria-invalid={!!form.formState.errors.valid_from}
                    />
                  )}
                />
                {form.formState.errors.valid_from && (
                  <p role="alert" className="text-xs text-destructive">
                    {form.formState.errors.valid_from.message}
                  </p>
                )}
              </div>
              <div className="space-y-2">
                <Label htmlFor="delegation-to">To</Label>
                <Controller
                  control={form.control}
                  name="valid_to"
                  rules={{
                    required: "Choose an end date.",
                    validate: (value) =>
                      value >= form.getValues("valid_from") ||
                      "End date must be on or after the start date.",
                  }}
                  render={({ field }) => (
                    <DateField
                      {...field}
                      id="delegation-to"
                      aria-label="To"
                      aria-invalid={!!form.formState.errors.valid_to}
                    />
                  )}
                />
                {form.formState.errors.valid_to && (
                  <p role="alert" className="text-xs text-destructive">
                    {form.formState.errors.valid_to.message}
                  </p>
                )}
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="delegation-reason">Reason</Label>
              <Input
                id="delegation-reason"
                placeholder="e.g. Annual leave"
                aria-invalid={!!form.formState.errors.reason}
                {...form.register("reason", {
                  required: "Enter a reason.",
                  validate: (value) =>
                    value.trim().length > 0 || "Enter a reason.",
                })}
              />
              {form.formState.errors.reason && (
                <p role="alert" className="text-xs text-destructive">
                  {form.formState.errors.reason.message}
                </p>
              )}
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                disabled={store.isPending}
                onClick={() => setAdding(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={store.isPending}>
                {store.isPending ? "Saving…" : "Add delegation"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
      <Dialog
        open={revoking !== null}
        onOpenChange={(open) => {
          if (!open && !revoke.isPending) setRevoking(null);
        }}
      >
        <DialogContent className="approval-controls">
          <DialogHeader>
            <DialogTitle>End approval coverage?</DialogTitle>
            <DialogDescription>
              {revoking?.delegate_name} will no longer be able to approve on
              behalf of {revoking?.delegator_name} through this delegation.
            </DialogDescription>
          </DialogHeader>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={revoke.isPending}
              onClick={() => setRevoking(null)}
            >
              Keep coverage
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={revoke.isPending}
              onClick={() => {
                if (revoking)
                  revoke.mutate(
                    { approvalDelegation: revoking.id },
                    {
                      onSuccess: () => {
                        setRevoking(null);
                        void query.refetch();
                      },
                      onError: (e) =>
                        setError(
                          getApiErrorMessage(e) ?? "Could not end coverage.",
                        ),
                    },
                  );
              }}
            >
              {revoke.isPending ? "Ending…" : "End coverage"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}
