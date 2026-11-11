import { useRef, useState } from "react";
import { Link } from "react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { describeLoadError, shouldRetryQuery } from "../../lib/api-errors";
import { formatDate, formatDateTime } from "../../lib/format-date";
import { AccessDenied } from "../../routes/permission-route";
import { useCompanyToday } from "../company/company-calendar";
import { useAuthStore } from "../../stores/auth-store";
import { IntentKey } from "../quotations/request-key";
import { discountAuthorityAccess } from "./discount-authority-access";
import { liveDiscountAuthorityGateway } from "./discount-authority-gateway";
import {
  ENFORCEMENT_LABEL,
  MODE_VIEW,
  OPERATION_LABEL,
  PURPOSE_LABEL,
  isDemoOnly,
  type AuthorityConfiguration,
  type DiscountAssignment,
  type DiscountAuthorityGateway,
  type DiscountBand,
  type DiscountPolicy,
  type Person,
} from "./discount-authority-contract";
import { bandLimits } from "./quotation-discount-authority-panel";
import {
  AssignmentSheet,
  PolicyVersionSheet,
  ReasonDialog,
  type ReasonAction,
} from "./discount-authority-forms";

function DemoBadge({ text }: { text: string | null | undefined }) {
  return isDemoOnly(text) ? <StatusBadge tone="pending">Demo only</StatusBadge> : null;
}

function BandLine({ band }: { band: DiscountBand }) {
  const limits = bandLimits(band);
  return (
    <li className="break-words text-sm">
      <span className="font-medium">{band.label}</span> · rank {band.rank} ·{" "}
      {band.exception_only
        ? "exception approver only"
        : limits.length > 0
          ? limits.join(", ")
          : "no limit set"}
      {isDemoOnly(band.label) && " "}
      {isDemoOnly(band.label) && <StatusBadge tone="pending">Demo only</StatusBadge>}
    </li>
  );
}

function PolicyCard({
  policy,
  bands,
  activated,
  mayWrite,
  onActivate,
}: {
  policy: DiscountPolicy;
  bands: DiscountBand[];
  activated: string | null;
  mayWrite: boolean;
  onActivate: (policy: DiscountPolicy) => void;
}) {
  return (
    <li className="min-w-0 space-y-2 rounded-lg border border-border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="break-all font-semibold">
          {policy.code} · version {policy.version_no}
        </h3>
        {activated ? (
          <StatusBadge tone="success">Activated</StatusBadge>
        ) : (
          <StatusBadge tone="draft">Not activated</StatusBadge>
        )}
        <DemoBadge text={policy.reason} />
      </div>
      <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
        <div><dt className="inline text-muted-foreground">Applies to: </dt><dd className="inline">{OPERATION_LABEL[policy.operation]}</dd></div>
        <div><dt className="inline text-muted-foreground">Limits checked on: </dt><dd className="inline">{ENFORCEMENT_LABEL[policy.enforcement]}</dd></div>
        <div><dt className="inline text-muted-foreground">Effective: </dt><dd className="inline">{formatDate(policy.effective_from)} to {policy.effective_to ? formatDate(policy.effective_to) : "no end date"}</dd></div>
        <div><dt className="inline text-muted-foreground">Approval valid for: </dt><dd className="inline">{policy.expiry_calendar_days} days</dd></div>
        <div><dt className="inline text-muted-foreground">Priority: </dt><dd className="inline">{policy.priority}</dd></div>
        <div><dt className="inline text-muted-foreground">Higher level may approve: </dt><dd className="inline">{policy.allow_higher_approver ? "Yes" : "No"}</dd></div>
      </dl>
      {policy.reason && <p className="break-words text-sm text-muted-foreground">“{policy.reason}”</p>}
      {activated && <p className="text-xs text-muted-foreground">Activated {formatDateTime(activated)}</p>}
      {bands.length === 0 ? (
        <p className="text-sm">No authority levels are recorded for this version.</p>
      ) : (
        <ul className="space-y-1" aria-label={`Authority levels of ${policy.code} version ${policy.version_no}`}>
          {bands.map((band) => <BandLine key={band.id} band={band} />)}
        </ul>
      )}
      {mayWrite && !activated && (
        <Button className="min-h-11" type="button" variant="outline" onClick={() => onActivate(policy)}>
          Activate this version
        </Button>
      )}
    </li>
  );
}

/** Presentational: everything the configuration screen shows, driven by one read. Writes are offered only when `mayWrite`. */
export function DiscountAuthorityView({
  config,
  people,
  today,
  referencesTo,
  mayWrite,
  busy = false,
  onNewPolicy,
  onActivate,
  onAssign,
  onRevoke,
}: {
  config: AuthorityConfiguration;
  people: Person[] | null;
  /** The company's calendar day, for "expired" and "starts later". */
  today: string;
  /** Where the price references screen is, when this person may open it. */
  referencesTo?: string;
  mayWrite: boolean;
  busy?: boolean;
  onNewPolicy?: () => void;
  onActivate?: (policy: DiscountPolicy) => void;
  onAssign?: () => void;
  onRevoke?: (assignment: DiscountAssignment, who: string) => void;
}) {
  const mode = MODE_VIEW[config.mode];
  const activations = new Map(config.activations.map((a) => [a.policy_id, a.recorded_at]));
  const revoked = new Map(config.revocations.map((r) => [r.assignment_id, r]));
  const bandOf = (id: number) => config.bands.find((b) => b.id === id);
  const policyOf = (id: number) => config.policies.find((p) => p.id === id);
  const nameOf = (id: number) => people?.find((p) => p.id === id)?.name ?? `Person #${id}`;
  const noBands = config.bands.length === 0;
  const noActivated = config.policies.every((p) => !activations.has(p.id));
  return (
    <DetailPageLayout>
      <DetailSection title="Status" fullWidth>
        <DetailField label="Discount authority">
          <StatusBadge tone={mode.tone}>{mode.label}</StatusBadge>
        </DetailField>
        <DetailField label="Policy versions">{config.policies.length}</DetailField>
        <DetailField label="Assignments">{config.assignments.length}</DetailField>
        <p className="col-span-full text-sm" role="note">{mode.note}</p>
        {config.mode === "OFF" && (
          <p className="col-span-full text-sm" role="note">
            Turning discount authority on is a separate decision that is not made on this screen. Saving a policy
            version or an assignment here does not switch it on.
          </p>
        )}
        {noBands && (
          <p className="col-span-full text-sm" role="note">
            No authority levels are set up yet. Until a policy version with authority levels is saved and
            activated, no quotation can be checked.
          </p>
        )}
        {!noBands && noActivated && (
          <p className="col-span-full text-sm" role="note">
            Policy versions exist but none is activated yet.
          </p>
        )}
        {config.truncated && (
          <p className="col-span-full text-sm" role="note">
            Only the newest 100 records of each list are shown.
          </p>
        )}
        {referencesTo && (
          <div className="col-span-full">
            <Link className="ui-link-action inline-flex min-h-11 items-center" to={referencesTo}>
              Price references
            </Link>
          </div>
        )}
        {!mayWrite && (
          <p className="col-span-full text-sm" role="note">
            You can view this configuration. Changing it needs a separate permission.
          </p>
        )}
      </DetailSection>

      <DetailSection
        title="Policy versions"
        description="Each version is fixed once saved. To change limits, save a new version and activate it."
        fullWidth
      >
        {mayWrite && onNewPolicy && (
          <div className="col-span-full">
            <Button className="min-h-11" type="button" onClick={onNewPolicy} disabled={busy}>
              New policy version
            </Button>
          </div>
        )}
        {config.policies.length === 0 ? (
          <p className="col-span-full text-sm">No policy versions yet.</p>
        ) : (
          <ul className="col-span-full space-y-3">
            {config.policies.map((policy) => (
              <PolicyCard
                key={policy.id}
                policy={policy}
                bands={config.bands.filter((b) => b.policy_id === policy.id).sort((a, b) => a.rank - b.rank)}
                activated={activations.get(policy.id) ?? null}
                mayWrite={mayWrite && onActivate !== undefined}
                onActivate={(p) => onActivate?.(p)}
              />
            ))}
          </ul>
        )}
      </DetailSection>

      <DetailSection
        title="Assignments"
        description="Who may clear or approve how much. An assignment grants no permission by itself."
        fullWidth
      >
        {mayWrite && onAssign && (
          <div className="col-span-full">
            <Button className="min-h-11" type="button" onClick={onAssign} disabled={busy || noBands}>
              Assign authority
            </Button>
            {noBands && <p className="mt-1 text-sm">Save a policy version first.</p>}
          </div>
        )}
        {config.assignments.length === 0 ? (
          <p className="col-span-full text-sm">No one has been assigned authority.</p>
        ) : (
          <ul className="col-span-full space-y-3">
            {config.assignments.map((assignment) => {
              const band = bandOf(assignment.band_id);
              const policy = band ? policyOf(band.policy_id) : undefined;
              const revocation = revoked.get(assignment.id);
              const expired = assignment.effective_to !== null && assignment.effective_to < today;
              const who = nameOf(assignment.user_id);
              return (
                <li key={assignment.id} className="min-w-0 space-y-1 rounded-lg border border-border p-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="break-words font-semibold">{who}</h3>
                    {revocation ? (
                      <StatusBadge tone="danger">Revoked</StatusBadge>
                    ) : expired ? (
                      <StatusBadge tone="draft">Expired</StatusBadge>
                    ) : assignment.effective_from > today ? (
                      <StatusBadge tone="pending">Starts later</StatusBadge>
                    ) : (
                      <StatusBadge tone="success">In effect</StatusBadge>
                    )}
                    <DemoBadge text={assignment.reason} />
                  </div>
                  <p className="break-words">
                    {band ? `${band.label} · rank ${band.rank}` : `Authority level #${assignment.band_id}`}
                    {policy ? ` · ${policy.code} v${policy.version_no}` : ""} · {PURPOSE_LABEL[assignment.purpose]}
                  </p>
                  <p>
                    {formatDate(assignment.effective_from)} to {assignment.effective_to ? formatDate(assignment.effective_to) : "no end date"}
                    {assignment.role_id ? ` · role #${assignment.role_id}` : ""}
                    {assignment.unit_id ? ` · unit #${assignment.unit_id}` : ""}
                  </p>
                  {assignment.reason && <p className="break-words text-muted-foreground">“{assignment.reason}”</p>}
                  {revocation?.reason && <p className="break-words text-muted-foreground">Revoked: “{revocation.reason}”</p>}
                  {mayWrite && onRevoke && !revocation && (
                    <Button className="min-h-11" type="button" variant="outline" onClick={() => onRevoke(assignment, who)} disabled={busy}>
                      Revoke
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </DetailSection>
    </DetailPageLayout>
  );
}

type Draft<K extends "createPolicy" | "assign"> = Omit<
  Parameters<DiscountAuthorityGateway[K]>[0],
  "requestKey" | "expectedGeneration"
>;
type Sheet = "policy" | "assignment" | null;

/** Discount authority configuration: policy versions and named assignments. Denies before any query unless the duty is held and the quotation UI gate is open. */
export function DiscountAuthoritySettingsScreen({
  gateway = liveDiscountAuthorityGateway,
}: {
  gateway?: DiscountAuthorityGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = discountAuthorityAccess(
    new Set((user?.permissions ?? []).filter((p): p is string => typeof p === "string")),
  );
  if (!access.configure) return <AccessDenied />;
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Discount authority"
        description="Who may clear or approve how much discount on a quotation. Off unless the company switches it on."
        backTo="/settings"
      />
      <GatedConfiguration
        key={String(user?.id)}
        gateway={gateway}
        actorId={user?.id}
        referencesTo={access.references ? "/settings/discount-authority/references" : undefined}
      />
    </ScreenTemplate>
  );
}

function GatedConfiguration({
  gateway,
  actorId,
  referencesTo,
}: {
  gateway: DiscountAuthorityGateway;
  actorId: number | string | undefined;
  referencesTo?: string;
}) {
  const client = useQueryClient();
  const today = useCompanyToday();
  const [sheet, setSheet] = useState<Sheet>(null);
  const [reasonAction, setReasonAction] = useState<ReasonAction | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  // One key per write intent: an uncertain failure keeps it so a retry replays; success or a definitive refusal ends it.
  const keys = useRef(new IntentKey());
  const configKey = ["discount-authority-configuration", actorId ?? "fixture"];
  const config = useQuery({
    queryKey: configKey,
    queryFn: () => gateway.read(),
    retry: shouldRetryQuery,
  });
  // Only needed by the assignment form; failing to load it is fine (the form asks for a reference number).
  const people = useQuery({
    queryKey: ["discount-authority-people", actorId ?? "fixture"],
    queryFn: () => gateway.people(),
    enabled: sheet === "assignment",
    retry: false,
  });
  // Role and unit names are a convenience: each may be unavailable to this person, and the form then asks for a reference number.
  const roles = useQuery({
    queryKey: ["discount-authority-roles", actorId ?? "fixture"],
    queryFn: () => gateway.roles(),
    enabled: sheet === "assignment",
    retry: false,
  });
  const units = useQuery({
    queryKey: ["discount-authority-units", actorId ?? "fixture"],
    queryFn: () => gateway.units(),
    enabled: sheet === "assignment",
    retry: false,
  });
  const done = (message: string) => {
    keys.current.settle();
    setSheet(null);
    setReasonAction(null);
    setNotice(message);
    void client.invalidateQueries({ queryKey: configKey });
  };
  const failed = (error: unknown) => {
    keys.current.settle(error);
    // A moved configuration: show the truth so the next attempt quotes the current generation.
    void client.invalidateQueries({ queryKey: configKey });
  };
  const generation = config.data?.generation ?? 0;
  const createPolicy = useMutation({
    mutationFn: (v: Draft<"createPolicy">) =>
      gateway.createPolicy({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "policy", generation, v }) }),
    onSuccess: () => done("Policy version saved. It has not been activated."),
    onError: failed,
  });
  const activate = useMutation({
    mutationFn: (v: { policyId: number; reason: string }) =>
      gateway.activatePolicy({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "activate", generation, v }) }),
    onSuccess: () => done("Policy version activated."),
    onError: failed,
  });
  const assign = useMutation({
    mutationFn: (v: Draft<"assign">) =>
      gateway.assign({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "assign", generation, v }) }),
    onSuccess: () => done("Authority assigned."),
    onError: failed,
  });
  const revoke = useMutation({
    mutationFn: (v: { assignmentId: number; reason: string }) =>
      gateway.revoke({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "revoke", generation, v }) }),
    onSuccess: () => done("Assignment revoked."),
    onError: failed,
  });
  const busy = createPolicy.isPending || activate.isPending || assign.isPending || revoke.isPending;

  if (config.isError)
    return (
      <div className="space-y-2" role="alert">
        <p className="text-sm">{describeLoadError(config.error)}</p>
        <Button className="min-h-11" type="button" variant="outline" onClick={() => void config.refetch()}>
          Try again
        </Button>
      </div>
    );
  if (!config.data)
    return (
      <div role="status">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading discount authority…</span>
      </div>
    );
  const data = config.data;
  return (
    <>
      {notice && <p role="status" className="text-sm">{notice}</p>}
      <DiscountAuthorityView
        config={data}
        people={people.data ?? null}
        today={today}
        referencesTo={referencesTo}
        mayWrite
        busy={busy}
        onNewPolicy={() => { createPolicy.reset(); setNotice(null); setSheet("policy"); }}
        onAssign={() => { assign.reset(); setNotice(null); setSheet("assignment"); }}
        onActivate={(policy) => { activate.reset(); setNotice(null); setReasonAction({ kind: "activate", policy }); }}
        onRevoke={(assignment, who) => { revoke.reset(); setNotice(null); setReasonAction({ kind: "revoke", assignmentId: assignment.id, who }); }}
      />
      {sheet === "policy" && (
        <PolicyVersionSheet
          today={today}
          pending={createPolicy.isPending}
          error={createPolicy.error}
          onClose={() => setSheet(null)}
          onSubmit={(policy, reason) => createPolicy.mutate({ policy, reason })}
        />
      )}
      {sheet === "assignment" && (
        <AssignmentSheet
          today={today}
          bands={data.bands}
          policies={data.policies}
          people={people.isError ? null : (people.data ?? null)}
          roles={roles.isError ? null : (roles.data ?? null)}
          units={units.isError ? null : (units.data ?? null)}
          pending={assign.isPending}
          error={assign.error}
          onClose={() => setSheet(null)}
          onSubmit={(assignment, reason) => assign.mutate({ assignment, reason })}
        />
      )}
      <ReasonDialog
        action={reasonAction}
        pending={activate.isPending || revoke.isPending}
        error={activate.error ?? revoke.error}
        onClose={() => setReasonAction(null)}
        onConfirm={(reason) => {
          if (reasonAction?.kind === "activate") activate.mutate({ policyId: reasonAction.policy.id, reason });
          else if (reasonAction?.kind === "revoke") revoke.mutate({ assignmentId: reasonAction.assignmentId, reason });
        }}
      />
    </>
  );
}
