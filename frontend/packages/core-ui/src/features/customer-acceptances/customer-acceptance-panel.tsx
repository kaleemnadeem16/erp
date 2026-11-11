import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { formatDateTime } from "../../lib/format-date";
import { describeLoadError, shouldRetryQuery } from "../../lib/api-errors";
import type { CustomerDecisionGateway } from "../customer-decisions/customer-decision-contract";
import { liveCustomerDecisionGateway } from "../customer-decisions/customer-decision-gateway";
import {
  AUTHORITY_LABELS,
  SOURCE_LABELS,
  type AuthorityBasis,
  type SourceKind,
} from "../customer-decisions/customer-decision-contract";
import { availabilityView } from "../quotations/quotation-issue-contract";
import type { IssuedTerms } from "../quotations/quotation-issue-contract";
import {
  consentProblem,
  scopeStatusView,
  type AcceptanceTarget,
  type CustomerAcceptanceGateway,
} from "./customer-acceptance-contract";
import { liveCustomerAcceptanceGateway } from "./customer-acceptance-gateway";
import { AcceptanceReviewSheet } from "./customer-acceptance-review";

/**
 * The Accept panel on an issued quotation. One acceptance is one full scope on the exact issued terms: choose the scope, choose the
 * customer message that carries the consent, review the exact terms, confirm. There is no quantity or price to change. A person who
 * lacks the duty still sees each scope's state, with the reason the action is off.
 */
export function AcceptancePanel({
  terms,
  mayAccept,
  disabledReason,
  decisions = liveCustomerDecisionGateway,
  gateway = liveCustomerAcceptanceGateway,
  mayOpenMessages = false,
}: {
  terms: IssuedTerms;
  mayAccept: boolean;
  /** Why accepting is off for this person; shown instead of hiding the panel. */
  disabledReason: string | null;
  decisions?: CustomerDecisionGateway;
  gateway?: CustomerAcceptanceGateway;
  /** Offer a link to Customer Messages (given only to people who may read them). */
  mayOpenMessages?: boolean;
}) {
  const scopes = terms.scope_acceptance ?? null;
  const open = terms.availability === "AVAILABLE";
  const [scope, setScope] = useState<string>("");
  const [search, setSearch] = useState("");
  const [caseId, setCaseId] = useState<string>("");
  const [componentId, setComponentId] = useState<string>("");
  const [reviewing, setReviewing] = useState<AcceptanceTarget | null>(null);
  const canUse = mayAccept && open && scopes !== null;
  const chosen = scopes?.find((s) => String(s.issued_line_id) === scope);

  const cases = useQuery({
    queryKey: ["customer-decision-cases", "accept-picker", search],
    queryFn: () =>
      decisions.list({
        search: search || undefined,
        sort: "-id",
        page: 1,
        perPage: 25,
      }),
    enabled: canUse,
    retry: shouldRetryQuery,
  });
  const detail = useQuery({
    queryKey: ["customer-decision-case", Number(caseId)],
    queryFn: () => decisions.detail(Number(caseId)),
    enabled: canUse && caseId !== "",
    retry: shouldRetryQuery,
  });

  const linked = (detail.data?.components ?? []).filter(
    (c) => c.family_id === terms.family_id,
  );
  // The component for this scope: the one tied to it, else the only one this quotation has in the case, else the person's choice.
  const auto =
    linked.find((c) => c.original_scope_anchor_id === chosen?.logical_scope_id) ??
    (linked.length === 1 ? linked[0] : undefined);
  const component =
    linked.find((c) => String(c.id) === componentId) ?? auto ?? undefined;
  const observations = detail.data?.observations ?? [];
  const latest = observations[observations.length - 1];
  const problem = detail.data
    ? linked.length === 0
      ? "This customer message is not linked to this quotation. A message is linked to a quotation when it is recorded."
      : consentProblem(latest)
    : null;
  const ready =
    canUse && chosen !== undefined && component !== undefined && !!detail.data && problem === null && latest !== undefined;

  return (
    <DetailSection
      title="Accept"
      description="Record that the customer accepts one full scope of this offer, exactly as issued."
      fullWidth
    >
      <p className="col-span-full text-sm" role="note">
        Accepting records the customer’s consent and nothing else: no order, job,
        stock reservation or invoice is created, and prices are never worked out
        again. The terms cannot be changed here; a different quantity or price
        needs a new offer.
      </p>
      {scopes === null ? (
        <p className="col-span-full text-sm" role="note">
          Which scopes can be accepted is shown to people who may see customer
          messages.
        </p>
      ) : (
        <fieldset className="col-span-full space-y-2" disabled={!canUse}>
          <legend className="text-sm font-medium">
            Choose the one scope to accept
          </legend>
          <ul className="space-y-2" aria-label="Scopes of this quotation">
            {scopes.map((s) => {
              const view = scopeStatusView(s.status);
              const pickable = canUse && s.status === "AVAILABLE";
              const inputId = `accept-scope-${s.issued_line_id}`;
              return (
                <li
                  key={s.issued_line_id}
                  className="flex min-h-11 flex-wrap items-center gap-3 rounded-md border border-border p-2"
                >
                  <input
                    id={inputId}
                    type="radio"
                    name="accept-scope"
                    className="size-5 accent-[var(--primary)]"
                    checked={scope === String(s.issued_line_id)}
                    disabled={!pickable}
                    onChange={() => {
                      setScope(String(s.issued_line_id));
                      setComponentId("");
                    }}
                    aria-describedby={`${inputId}-note`}
                  />
                  <Label htmlFor={inputId} className="min-w-0 flex-1 text-sm">
                    {`Scope ${s.logical_scope_id}`}
                    <span className="block text-xs text-muted-foreground">
                      {`Issued line ${s.issued_line_id}`}
                    </span>
                  </Label>
                  <StatusBadge tone={view.tone}>{view.label}</StatusBadge>
                  <span id={`${inputId}-note`} className="sr-only">
                    {view.note}
                  </span>
                </li>
              );
            })}
          </ul>
          <p className="text-xs text-muted-foreground">
            The exact description, quantity and price of the scope you choose are
            shown in the review. If scopes are alternatives, accepting one closes
            the others.
          </p>
        </fieldset>
      )}
      {!canUse && (
        <p className="col-span-full text-sm" role="note" data-testid="accept-disabled">
          {!mayAccept
            ? (disabledReason ??
              "Recording an acceptance needs a permission your access does not include.")
            : !open
              ? `${availabilityView(terms.availability).label}: this offer is not open, so nothing on it can be accepted. A new offer is needed.`
              : "Which scopes can be accepted is not available to you."}
        </p>
      )}
      {canUse && (
        <div className="col-span-full space-y-3">
          <div className="space-y-2">
            <Label htmlFor="accept-search">
              Customer message that carries the consent
            </Label>
            <Input
              id="accept-search"
              className="min-h-11"
              type="search"
              placeholder="Search by the customer’s reference"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setCaseId("");
              }}
            />
            {cases.isPending && (
              <div role="status">
                <Skeleton className="h-11 w-full" />
                <span className="sr-only">Loading customer messages…</span>
              </div>
            )}
            {cases.isError && (
              <div role="alert" className="space-y-2 text-sm">
                <p>{describeLoadError(cases.error)}</p>
                <Button
                  className="min-h-11"
                  type="button"
                  variant="outline"
                  onClick={() => void cases.refetch()}
                >
                  Try again
                </Button>
              </div>
            )}
            {cases.data &&
              (cases.data.data.filter((c) => c.component_count > 0).length ===
              0 ? (
                <p className="text-sm text-muted-foreground" role="status">
                  No customer message linked to a quotation was found.{" "}
                  {mayOpenMessages && (
                    <Link
                      className="ui-link-action inline-flex min-h-11 items-center"
                      to="/customer-decisions"
                    >
                      Open Customer Messages
                    </Link>
                  )}
                </p>
              ) : (
                <Select
                  value={caseId}
                  onValueChange={(value) => {
                    setCaseId(value);
                    setComponentId("");
                  }}
                >
                  <SelectTrigger
                    id="accept-case"
                    className="min-h-11"
                    aria-label="Customer message"
                  >
                    <SelectValue placeholder="Choose a customer message" />
                  </SelectTrigger>
                  <SelectContent>
                    {cases.data.data
                      .filter((c) => c.component_count > 0)
                      .map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>
                          {`${c.intake_identity} · ${c.party_name ?? `Customer reference ${c.party_id}`}`}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              ))}
          </div>
          {caseId !== "" && detail.isPending && (
            <div role="status">
              <Skeleton className="h-24 w-full" />
              <span className="sr-only">Loading the customer message…</span>
            </div>
          )}
          {detail.isError && (
            <div role="alert" className="space-y-2 text-sm">
              <p>{describeLoadError(detail.error)}</p>
              <Button
                className="min-h-11"
                type="button"
                variant="outline"
                onClick={() => void detail.refetch()}
              >
                Try again
              </Button>
            </div>
          )}
          {detail.data && linked.length > 1 && chosen && (
            <div className="space-y-2">
              <Label htmlFor="accept-component">
                Which link in that message applies
              </Label>
              <Select
                value={component ? String(component.id) : ""}
                onValueChange={setComponentId}
              >
                <SelectTrigger id="accept-component" className="min-h-11">
                  <SelectValue placeholder="Choose a link" />
                </SelectTrigger>
                <SelectContent>
                  {linked.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.intent_identity}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          {detail.data && latest && (
            <dl
              className="grid gap-2 rounded-md border border-border p-3 text-sm sm:grid-cols-2"
              aria-label="The latest observation, which is the one that counts"
            >
              <div>
                <dt className="text-xs text-muted-foreground">What arrived</dt>
                <dd>
                  {SOURCE_LABELS[latest.source_kind as SourceKind] ??
                    latest.source_kind}
                  {latest.external_reference
                    ? ` · ${latest.external_reference}`
                    : ""}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Sent by</dt>
                <dd>{latest.customer_representative_snapshot ?? "Not named"}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">
                  Sender’s authority
                </dt>
                <dd>
                  {AUTHORITY_LABELS[
                    latest.authority_verification_basis as AuthorityBasis
                  ] ?? latest.authority_verification_basis}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Recorded</dt>
                <dd>{formatDateTime(latest.recorded_at)}</dd>
              </div>
            </dl>
          )}
          {problem && (
            <p role="alert" className="text-sm text-destructive" id="accept-problem">
              {problem}
            </p>
          )}
        </div>
      )}
      <div className="col-span-full flex flex-wrap gap-3">
        <Button
          className="min-h-11"
          type="button"
          disabled={!ready}
          aria-describedby={
            ready ? undefined : canUse ? "accept-ready-hint" : "accept-disabled"
          }
          onClick={() => {
            if (ready && detail.data && chosen && component && latest)
              setReviewing({
                revisionId: terms.revision_id,
                componentId: component.id,
                evidenceId: latest.id,
                issuedLineId: chosen.issued_line_id,
                caseGeneration: detail.data.case.generation,
              });
          }}
        >
          Review acceptance
        </Button>
      </div>
      {canUse && !ready && (
        <p id="accept-ready-hint" className="col-span-full text-xs text-muted-foreground">
          Choose an available scope and a customer message that is linked to it
          to continue.
        </p>
      )}
      {reviewing && (
        <AcceptanceReviewSheet
          gateway={gateway}
          target={reviewing}
          onClose={() => {
            setReviewing(null);
            // The message may have moved while the review was open; the next review quotes what is current.
            void detail.refetch();
          }}
        />
      )}
    </DetailSection>
  );
}
