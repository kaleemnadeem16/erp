import { useRef, useState } from "react";
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
import { useAuthStore } from "../../stores/auth-store";
import { useCompanyToday } from "../company/company-calendar";
import { IntentKey } from "../quotations/request-key";
import { discountAuthorityAccess } from "./discount-authority-access";
import { liveReferenceGateway } from "./discount-authority-gateway";
import {
  MODE_VIEW,
  SOURCE_KIND_LABEL,
  isDemoOnly,
  type Currency,
  type Person,
  type PriceReference,
  type ReferenceApproval,
  type ReferenceConfiguration,
  type ReferenceGateway,
} from "./discount-authority-contract";
import { ReasonDialog, ReferenceSheet, type ReasonAction } from "./discount-authority-forms";

type ProposeDraft = Omit<Parameters<ReferenceGateway["propose"]>[0], "requestKey" | "expectedGeneration">;

/** What a reference prices, in words: the item or manual measure, the unit, the customer and the family it is limited to. */
function scopeText(reference: PriceReference): string {
  return [
    reference.item_id !== null ? `Item #${reference.item_id}` : reference.manual_measure,
    reference.uom_id !== null ? `unit #${reference.uom_id}` : null,
    reference.customer_id !== null ? `customer #${reference.customer_id}` : null,
    reference.family_id !== null ? `quotation family #${reference.family_id}` : null,
  ]
    .filter((part): part is string => Boolean(part))
    .join(" · ");
}

/** Presentational: every reference version with who proposed it and whether someone else approved it. Actions only when allowed. */
export function ReferenceListView({
  config,
  people,
  currencies,
  actorId,
  today,
  mayPropose,
  mayApprove,
  busy = false,
  onPropose,
  onApprove,
}: {
  config: ReferenceConfiguration;
  people: Person[] | null;
  currencies: Currency[] | null;
  actorId: number | null;
  today: string;
  mayPropose: boolean;
  mayApprove: boolean;
  busy?: boolean;
  onPropose?: () => void;
  onApprove?: (reference: PriceReference) => void;
}) {
  const mode = MODE_VIEW[config.mode];
  const approvals = new Map<number, ReferenceApproval>(config.approvals.map((a) => [a.reference_id, a]));
  const nameOf = (id: number | null) =>
    id === null ? "Unknown" : (people?.find((p) => p.id === id)?.name ?? `Person #${id}`);
  const currencyOf = (id: number) => currencies?.find((c) => c.id === id)?.code ?? `Currency #${id}`;
  return (
    <DetailPageLayout>
      <DetailSection title="Status" fullWidth>
        <DetailField label="Discount authority">
          <StatusBadge tone={mode.tone}>{mode.label}</StatusBadge>
        </DetailField>
        <DetailField label="Reference versions">{config.references.length}</DetailField>
        <DetailField label="Awaiting approval">
          {config.references.filter((r) => !approvals.has(r.id)).length}
        </DetailField>
        <p className="col-span-full text-sm" role="note">
          A price reference is the trusted price a discount is measured against. It counts only after someone other than
          the person who proposed it approves it. Saving or approving one does not switch discount authority on.
        </p>
        {config.truncated && (
          <p className="col-span-full text-sm" role="note">Only the newest 100 records of each list are shown.</p>
        )}
        {!mayPropose && !mayApprove && (
          <p className="col-span-full text-sm" role="note">You can view these references. Changing them needs a separate permission.</p>
        )}
      </DetailSection>
      <DetailSection
        title="Price references"
        description="Each version is fixed once saved. To change a price, propose a new version and have it approved."
        fullWidth
      >
        {mayPropose && onPropose && (
          <div className="col-span-full">
            <Button className="min-h-11" type="button" onClick={onPropose} disabled={busy}>
              Propose a price reference
            </Button>
          </div>
        )}
        {config.references.length === 0 ? (
          <p className="col-span-full text-sm">No price references yet.</p>
        ) : (
          <ul className="col-span-full space-y-3">
            {config.references.map((reference) => {
              const approval = approvals.get(reference.id);
              const own = actorId !== null && reference.proposed_by === actorId;
              const ended = reference.effective_to !== null && reference.effective_to < today;
              return (
                <li key={reference.id} className="min-w-0 space-y-1 rounded-lg border border-border p-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="break-all font-semibold">
                      {reference.code} · version {reference.version_no}
                    </h3>
                    {approval ? (
                      ended ? <StatusBadge tone="draft">Ended</StatusBadge> : <StatusBadge tone="success">Approved</StatusBadge>
                    ) : (
                      <StatusBadge tone="pending">Awaiting approval</StatusBadge>
                    )}
                    {isDemoOnly(reference.reason) && <StatusBadge tone="pending">Demo only</StatusBadge>}
                  </div>
                  <p className="break-words">
                    <span className="tabular-nums font-medium">{reference.unit_price}</span> {currencyOf(reference.currency_id)} ·{" "}
                    {SOURCE_KIND_LABEL[reference.source_kind]} “{reference.source_key}” version {reference.source_version}
                  </p>
                  <p className="break-words">{scopeText(reference)}</p>
                  <p>
                    {formatDate(reference.effective_from)} to {reference.effective_to ? formatDate(reference.effective_to) : "no end date"} · priority {reference.priority}
                  </p>
                  <p>Proposed by {nameOf(reference.proposed_by)}</p>
                  {reference.reason && <p className="break-words text-muted-foreground">“{reference.reason}”</p>}
                  {approval && (
                    <p className="break-words text-muted-foreground">
                      Approved by {nameOf(approval.approved_by)} on {formatDateTime(approval.recorded_at)}
                      {approval.reason ? ` — “${approval.reason}”` : ""}
                    </p>
                  )}
                  {!approval && mayApprove && onApprove &&
                    (own ? (
                      <p role="note">You proposed this version, so someone else has to approve it.</p>
                    ) : (
                      <Button className="min-h-11" type="button" variant="outline" onClick={() => onApprove(reference)} disabled={busy}>
                        Approve this reference
                      </Button>
                    ))}
                </li>
              );
            })}
          </ul>
        )}
      </DetailSection>
    </DetailPageLayout>
  );
}

/** Price references: proposing needs the configuration duty, approving the independent approval duty. Denies before any query unless one is held and the quotation UI gate is open. */
export function DiscountAuthorityReferencesScreen({
  gateway = liveReferenceGateway,
}: {
  gateway?: ReferenceGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = discountAuthorityAccess(
    new Set((user?.permissions ?? []).filter((p): p is string => typeof p === "string")),
  );
  if (!access.references) return <AccessDenied />;
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Price references"
        description="The trusted prices a discount is measured against. Proposed by one person, approved by another."
        backTo="/settings/discount-authority"
      />
      <GatedReferences
        key={String(user?.id)}
        gateway={gateway}
        actorId={typeof user?.id === "number" ? user.id : null}
        mayPropose={access.referencePropose}
        mayApprove={access.referenceApprove}
      />
    </ScreenTemplate>
  );
}

function GatedReferences({
  gateway,
  actorId,
  mayPropose,
  mayApprove,
}: {
  gateway: ReferenceGateway;
  actorId: number | null;
  mayPropose: boolean;
  mayApprove: boolean;
}) {
  const client = useQueryClient();
  const today = useCompanyToday();
  const [proposing, setProposing] = useState(false);
  const [approving, setApproving] = useState<ReasonAction | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const keys = useRef(new IntentKey());
  const scope = actorId ?? "fixture";
  const configKey = ["discount-authority-references", scope];
  const config = useQuery({ queryKey: configKey, queryFn: () => gateway.read(), retry: shouldRetryQuery });
  // Names are a convenience: either lookup may be unavailable to this person, and the list then shows reference numbers.
  const people = useQuery({ queryKey: ["discount-authority-reference-people", scope], queryFn: () => gateway.people(), retry: false });
  const currencies = useQuery({ queryKey: ["discount-authority-currencies", scope], queryFn: () => gateway.currencies(), retry: false });
  const generation = config.data?.generation ?? 0;
  const done = (message: string) => {
    keys.current.settle();
    setProposing(false);
    setApproving(null);
    setNotice(message);
    void client.invalidateQueries({ queryKey: configKey });
  };
  const failed = (error: unknown) => {
    keys.current.settle(error);
    void client.invalidateQueries({ queryKey: configKey });
  };
  const propose = useMutation({
    mutationFn: (v: ProposeDraft) =>
      gateway.propose({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "propose", generation, v }) }),
    onSuccess: () => done("Price reference saved. It needs approval by someone else before it counts."),
    onError: failed,
  });
  const approve = useMutation({
    mutationFn: (v: { referenceId: number; reason: string }) =>
      gateway.approve({ ...v, expectedGeneration: generation, requestKey: keys.current.for({ op: "approve", generation, v }) }),
    onSuccess: () => done("Price reference approved."),
    onError: failed,
  });
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
        <span className="sr-only">Loading price references…</span>
      </div>
    );
  return (
    <>
      {notice && <p role="status" className="text-sm">{notice}</p>}
      <ReferenceListView
        config={config.data}
        people={people.data ?? null}
        currencies={currencies.data ?? null}
        actorId={actorId}
        today={today}
        mayPropose={mayPropose}
        mayApprove={mayApprove}
        busy={propose.isPending || approve.isPending}
        onPropose={() => { propose.reset(); setNotice(null); setProposing(true); }}
        onApprove={(reference) => { approve.reset(); setNotice(null); setApproving({ kind: "approve", reference }); }}
      />
      {proposing && (
        <ReferenceSheet
          today={today}
          currencies={currencies.isError ? null : (currencies.data ?? null)}
          pending={propose.isPending}
          error={propose.error}
          onClose={() => setProposing(false)}
          onSubmit={(reference, reason) => propose.mutate({ reference, reason })}
        />
      )}
      <ReasonDialog
        action={approving}
        pending={approve.isPending}
        error={approve.error}
        onClose={() => setApproving(null)}
        onConfirm={(reason) => {
          if (approving?.kind === "approve") approve.mutate({ referenceId: approving.reference.id, reason });
        }}
      />
    </>
  );
}
