import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router";
import { AccessDenied } from "../../routes/permission-route";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { Button } from "../../components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { Textarea } from "../../components/ui/textarea";
import {
  describeLoadError,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { useAuthStore } from "../../stores/auth-store";
import type { HandoverNavigationGateway } from "../handover-release/handover-release-contract";
import { liveHandoverNavigationGateway } from "../handover-release/handover-release-gateway";
import { IntentKey } from "../quotations/request-key";
import { handoverPrepareAccess } from "./handover-preparation-access";
import {
  alreadyPrepared,
  missingText,
  needsFreshReview,
  refusalMessage,
  type HandoverPreparationGateway,
  type PreparationInputs,
  type PreparationOptions,
  type PreparationPreview,
} from "./handover-preparation-contract";
import { liveHandoverPreparationGateway } from "./handover-preparation-gateway";

const MAX_INSTRUCTIONS = 2000;
const selectClass =
  "min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

/** Two reviews of the same inputs are the same review: the token is only valid for exactly what was reviewed. */
const sameInputs = (a: PreparationInputs, b: PreparationInputs) =>
  JSON.stringify(a) === JSON.stringify(b);

function contactLabel(c: PreparationOptions["contacts"][number]): string {
  const via = [c.email, c.phone].filter(Boolean).join(" · ");
  return via ? `${c.name} (${via})` : c.name;
}
function siteLabel(s: PreparationOptions["sites"][number]): string {
  const where = [s.address_line1, s.city, s.country_code]
    .filter(Boolean)
    .join(", ");
  return where ? `${s.name} (${where})` : s.name;
}

function Failure({ error, retry }: { error: unknown; retry: () => void }) {
  const missing = isApiError(error) && error.status === 404;
  return (
    <div
      role="alert"
      className="m-4 space-y-3 rounded-lg border border-border bg-card p-4 md:m-6"
    >
      <h2 className="font-semibold">
        {missing ? "Service unavailable" : "Could not load this page"}
      </h2>
      <p className="text-sm">
        {missing
          ? "This accepted service is not available."
          : describeLoadError(error)}
      </p>
      {!missing && (
        <Button
          type="button"
          variant="outline"
          className="min-h-11"
          onClick={retry}
        >
          Try again
        </Button>
      )}
    </div>
  );
}

/**
 * Prepare the handover of one accepted service: say whether it is delivered remotely or on site, choose the customer's contact (and site), add
 * instructions, review what the system says is missing, then confirm. Preparing is permanent and is NOT a release: it creates no Job and gives no
 * right to release. A handover that already exists is opened, never duplicated.
 */
export function HandoverPreparationScreen({
  acceptanceId,
  gateway = liveHandoverPreparationGateway,
  navigation = liveHandoverNavigationGateway,
}: {
  acceptanceId: number;
  gateway?: HandoverPreparationGateway;
  navigation?: HandoverNavigationGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  if (!handoverPrepareAccess(permissions)) return <AccessDenied />;
  return (
    <GatedPreparation
      acceptanceId={acceptanceId}
      gateway={gateway}
      navigation={navigation}
    />
  );
}

function GatedPreparation({
  acceptanceId,
  gateway,
  navigation,
}: {
  acceptanceId: number;
  gateway: HandoverPreparationGateway;
  navigation: HandoverNavigationGateway;
}) {
  const client = useQueryClient();
  const status = useQuery({
    queryKey: ["acceptance-handover", acceptanceId],
    queryFn: () => navigation.status(acceptanceId),
    retry: shouldRetryQuery,
    staleTime: 0,
  });
  const options = useQuery({
    queryKey: ["handover-preparation-options", acceptanceId],
    queryFn: () => gateway.options(acceptanceId),
    retry: shouldRetryQuery,
    staleTime: 0,
    gcTime: 0,
  });
  if (status.isError)
    return <Failure error={status.error} retry={() => void status.refetch()} />;
  if (options.isError)
    return (
      <Failure error={options.error} retry={() => void options.refetch()} />
    );
  if (status.isPending || options.isPending)
    return (
      <div role="status" className="p-4 md:p-6">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading…</span>
      </div>
    );
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Prepare handover"
        description="Record how and where this accepted service will be delivered. Preparing does not release the service or create a Job."
        backTo={`/customer-acceptances/${acceptanceId}`}
      />
      <DetailPageLayout>
        {status.data.prepared && status.data.plan_id !== null ? (
          <AlreadyPrepared
            planId={status.data.plan_id}
            released={status.data.release_performed}
          />
        ) : (
          <PreparationForm
            acceptanceId={acceptanceId}
            gateway={gateway}
            options={options.data}
            onAlreadyPrepared={() =>
              void client.invalidateQueries({
                queryKey: ["acceptance-handover", acceptanceId],
              })
            }
          />
        )}
      </DetailPageLayout>
    </ScreenTemplate>
  );
}

function AlreadyPrepared({
  planId,
  released,
}: {
  planId: number;
  released: boolean;
}) {
  return (
    <DetailSection title="Handover already prepared" fullWidth>
      <div className="col-span-full space-y-2 text-sm">
        <p role="status" data-testid="already-prepared">
          <StatusBadge tone={released ? "success" : "pending"}>
            {released ? "Released" : "Prepared, not released"}
          </StatusBadge>
        </p>
        <p>
          A handover has already been prepared for this service. It cannot be
          prepared a second time.
        </p>
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/handover-plans/${planId}`}
          data-testid="open-existing-handover"
        >
          Open the handover
        </Link>
      </div>
    </DetailSection>
  );
}

function PreparationForm({
  acceptanceId,
  gateway,
  options,
  onAlreadyPrepared,
}: {
  acceptanceId: number;
  gateway: HandoverPreparationGateway;
  options: PreparationOptions;
  onAlreadyPrepared: () => void;
}) {
  const navigate = useNavigate();
  const [locationKind, setLocationKind] =
    useState<PreparationInputs["locationKind"]>("UNKNOWN");
  const [contactId, setContactId] = useState<number | null>(null);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [instructions, setInstructions] = useState("");
  const [reviewed, setReviewed] = useState<{
    inputs: PreparationInputs;
    preview: PreparationPreview;
  } | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const keys = useRef(new IntentKey());
  const headingRef = useRef<HTMLHeadingElement>(null);

  const inputs: PreparationInputs = {
    locationKind,
    contactId,
    siteId: locationKind === "ON_SITE" ? siteId : null,
    instructions: instructions.trim(),
  };
  // A review belongs to exactly the inputs it was made for: any edit makes it stale, and a stale review can never be confirmed.
  const current = reviewed && sameInputs(reviewed.inputs, inputs);
  const tooLong = instructions.length > MAX_INSTRUCTIONS;

  useEffect(() => {
    if (reviewed && !current) setNotice(null);
  }, [reviewed, current]);

  const review = useMutation({
    mutationFn: () => gateway.preview(acceptanceId, inputs),
    onSuccess: (preview) => {
      setNotice(null);
      setReviewed({ inputs, preview });
    },
    onError: (error) => {
      setReviewed(null);
      setNotice(refusalMessage(error));
    },
  });
  const prepare = useMutation({
    mutationFn: async () => {
      if (!reviewed || !current) throw new Error("No current review.");
      const request = {
        acceptanceId,
        inputs: reviewed.inputs,
        previewToken: reviewed.preview.preview_token,
      };
      // One key per exact intent (the inputs and the token reviewed): a retry after an uncertain failure replays, a new review is a new intent.
      const requestKey = keys.current.for(request);
      try {
        const out = await gateway.prepare({ ...request, requestKey });
        keys.current.settle();
        return out;
      } catch (error) {
        keys.current.settle(error);
        throw error;
      }
    },
    onSuccess: (out) => {
      setConfirming(false);
      void navigate(`/handover-plans/${out.plan_id}`);
    },
    onError: (error) => {
      setConfirming(false);
      if (needsFreshReview(error)) setReviewed(null);
      if (alreadyPrepared(error)) onAlreadyPrepared();
      setNotice(refusalMessage(error));
    },
  });

  const noContacts = options.contacts.length === 0;
  const preview = current ? reviewed.preview : null;
  return (
    <>
      <DetailSection
        title="Delivery details"
        description="Only this customer’s own current contacts and active sites can be chosen."
        fullWidth
      >
        <form
          className="col-span-full space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            review.mutate();
          }}
          noValidate
        >
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">
              Where is it delivered?
            </legend>
            {(
              [
                ["REMOTE", "Remote"],
                ["ON_SITE", "On site"],
              ] as const
            ).map(([value, label]) => (
              <label
                key={value}
                className="flex min-h-11 items-center gap-2 text-sm"
              >
                <input
                  type="radio"
                  name="location-kind"
                  value={value}
                  checked={locationKind === value}
                  onChange={() => setLocationKind(value)}
                  className="h-5 w-5"
                />
                {label}
              </label>
            ))}
          </fieldset>

          <div className="space-y-1">
            <label className="text-sm font-medium" htmlFor="prep-contact">
              Customer contact
            </label>
            <select
              id="prep-contact"
              className={selectClass}
              value={contactId ?? ""}
              onChange={(e) =>
                setContactId(e.target.value ? Number(e.target.value) : null)
              }
              aria-describedby={noContacts ? "prep-contact-none" : undefined}
            >
              <option value="">Choose a contact</option>
              {options.contacts.map((c) => (
                <option key={c.id} value={c.id}>
                  {contactLabel(c)}
                </option>
              ))}
            </select>
            {noContacts && (
              <p id="prep-contact-none" className="text-sm" role="note">
                This customer has no current contact with an email address or
                telephone. Add one in Parties first.
              </p>
            )}
          </div>

          {locationKind === "ON_SITE" && (
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="prep-site">
                Customer site
              </label>
              <select
                id="prep-site"
                className={selectClass}
                value={siteId ?? ""}
                onChange={(e) =>
                  setSiteId(e.target.value ? Number(e.target.value) : null)
                }
                aria-describedby={
                  options.sites.length === 0 ? "prep-site-none" : undefined
                }
              >
                <option value="">Choose a site</option>
                {options.sites.map((s) => (
                  <option key={s.id} value={s.id}>
                    {siteLabel(s)}
                  </option>
                ))}
              </select>
              {options.sites.length === 0 && (
                <p id="prep-site-none" className="text-sm" role="note">
                  This customer has no active site. Add one in Parties first.
                </p>
              )}
            </div>
          )}

          <div className="space-y-1">
            <label className="text-sm font-medium" htmlFor="prep-instructions">
              Operational instructions
            </label>
            <Textarea
              id="prep-instructions"
              value={instructions}
              onChange={(e) => setInstructions(e.target.value)}
              aria-invalid={tooLong}
              aria-describedby="prep-instructions-hint"
              rows={4}
            />
            <p
              id="prep-instructions-hint"
              className={tooLong ? "text-sm text-destructive" : "text-sm"}
            >
              {instructions.length}/{MAX_INSTRUCTIONS} characters. Access,
              timing or handling notes for whoever does the work. Not customer
              conditions and not prices.
            </p>
          </div>

          {notice && (
            <p role="alert" className="text-sm" data-testid="prep-notice">
              {notice}
            </p>
          )}
          <Button
            type="submit"
            className="min-h-11"
            disabled={review.isPending || tooLong}
          >
            {review.isPending
              ? "Reviewing…"
              : reviewed
                ? "Review again"
                : "Review handover"}
          </Button>
        </form>
      </DetailSection>

      {reviewed && !current && (
        <DetailSection title="Review" fullWidth>
          <p
            className="col-span-full text-sm"
            role="status"
            data-testid="prep-review-stale"
          >
            You changed the details after the last review. Review again before
            you prepare the handover.
          </p>
        </DetailSection>
      )}

      {preview && (
        <DetailSection
          title="Review"
          description="What the system says about these details."
          fullWidth
        >
          <div
            className="col-span-full space-y-3 text-sm"
            data-testid="prep-review"
          >
            <h2 ref={headingRef} tabIndex={-1} className="sr-only">
              Review result
            </h2>
            <p role="status">
              <StatusBadge
                tone={
                  preview.readiness.preparation_ready ? "success" : "pending"
                }
              >
                {preview.readiness.preparation_ready
                  ? "Ready to prepare"
                  : "Not ready to prepare"}
              </StatusBadge>
            </p>
            {preview.readiness.missing.length > 0 && (
              <div data-testid="prep-missing">
                <p className="font-medium">Missing information</p>
                <ul className="list-disc space-y-1 pl-5">
                  {preview.readiness.missing.map((code) => {
                    const next = preview.readiness.next_actions.find(
                      (n) => n.fact === code,
                    );
                    return (
                      <li key={code}>
                        {missingText(code)}
                        {next ? ` ${next.action}` : ""}
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}
            <dl className="grid gap-1 sm:grid-cols-[10rem_1fr]">
              <dt className="font-medium">Delivery</dt>
              <dd>
                {preview.inputs.location_kind === "ON_SITE"
                  ? "On site"
                  : preview.inputs.location_kind === "REMOTE"
                    ? "Remote"
                    : "Not chosen"}
              </dd>
              <dt className="font-medium">Contact</dt>
              <dd>{preview.operational.contact?.name ?? "Not chosen"}</dd>
              {preview.inputs.location_kind === "ON_SITE" && (
                <>
                  <dt className="font-medium">Site</dt>
                  <dd>{preview.operational.site?.name ?? "Not chosen"}</dd>
                </>
              )}
              <dt className="font-medium">Instructions</dt>
              <dd className="whitespace-pre-wrap break-words">
                {preview.inputs.instructions || "None"}
              </dd>
            </dl>
            <Button
              type="button"
              className="min-h-11"
              disabled={
                !preview.readiness.preparation_ready || prepare.isPending
              }
              onClick={() => setConfirming(true)}
            >
              Prepare handover…
            </Button>
          </div>
        </DetailSection>
      )}

      <Dialog open={confirming} onOpenChange={setConfirming}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Prepare this handover?</DialogTitle>
            <DialogDescription>
              The delivery details you reviewed are recorded permanently and
              cannot be edited. Preparing does not release the service, create a
              Job or give anyone permission to release it.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              className="min-h-11"
              onClick={() => setConfirming(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="min-h-11"
              disabled={prepare.isPending || !current}
              onClick={() => prepare.mutate()}
            >
              {prepare.isPending ? "Preparing…" : "Prepare"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
