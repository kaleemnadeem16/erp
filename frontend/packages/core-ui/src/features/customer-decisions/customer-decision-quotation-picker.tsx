import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button } from "../../components/ui/button";
import { Label } from "../../components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Skeleton } from "../../components/ui/skeleton";
import { describeLoadError, shouldRetryQuery } from "../../lib/api-errors";
import type {
  CaseComponentInput,
  CustomerDecisionGateway,
} from "./customer-decision-contract";

/** The most the server accepts on one case. */
export const MAX_LINKED_SCOPES = 50;

/**
 * The quotation side of "Record a customer message": link the message to the issued scopes it is about, so a later acceptance can use it.
 * Needs the quotation view duty (a case with components needs sales.quotation.view on each family); without it the picker says so and the
 * message can still be recorded unlinked. Linking holds nothing: the offer can still expire or be withdrawn.
 */
export function QuotationLinkPicker({
  gateway,
  partyId,
  value,
  onChange,
  mayLink,
}: {
  gateway: CustomerDecisionGateway;
  /** The customer chosen above; quotations are those issued to them. */
  partyId: number | null;
  value: CaseComponentInput[];
  onChange: (next: CaseComponentInput[]) => void;
  /** Holds sales.quotation.view. */
  mayLink: boolean;
}) {
  const [revision, setRevision] = useState("");
  const quotations = useQuery({
    queryKey: ["customer-decision-linkable-quotations", partyId],
    queryFn: () => gateway.quotationsFor(partyId as number),
    enabled: mayLink && partyId !== null,
    retry: shouldRetryQuery,
  });
  const scopes = useQuery({
    queryKey: ["customer-decision-linkable-scopes", revision],
    queryFn: () => gateway.quotationScopes(Number(revision)),
    enabled: mayLink && revision !== "",
    retry: shouldRetryQuery,
  });
  const heading = (
    <div>
      <h3 className="text-sm font-medium">Quotation scopes this is about</h3>
      <p className="text-xs text-muted-foreground">
        Optional. Link the issued scopes the customer’s message refers to; an
        acceptance can only be recorded against a linked scope. Linking holds
        nothing: the offer can still expire or be withdrawn.
      </p>
    </div>
  );
  if (!mayLink)
    return (
      <div className="space-y-2" data-testid="link-picker-denied">
        {heading}
        <p className="text-sm" role="note">
          Linking quotation scopes needs permission to view quotations, which
          your access does not include. You can still record the message and
          link nothing.
        </p>
      </div>
    );
  const data = scopes.data;
  const chosen = new Set(value.map((c) => identityOf(c)));
  const full = value.length >= MAX_LINKED_SCOPES;
  function add(scope: { logical_scope_id: number }) {
    if (!data) return;
    const next: CaseComponentInput = {
      intent_identity: `${data.official_number} scope ${scope.logical_scope_id}`.slice(
        0,
        120,
      ),
      family_id: data.family_id,
      original_scope_anchor_id: scope.logical_scope_id,
    };
    if (!chosen.has(identityOf(next)) && !full) onChange([...value, next]);
  }
  return (
    <div className="space-y-3">
      {heading}
      {partyId === null ? (
        <p className="text-sm text-muted-foreground" role="note">
          Choose the customer first to see their issued quotations.
        </p>
      ) : quotations.isPending ? (
        <div role="status">
          <Skeleton className="h-11 w-full" />
          <span className="sr-only">Loading issued quotations…</span>
        </div>
      ) : quotations.isError ? (
        <div role="alert" className="space-y-2 text-sm">
          <p>{describeLoadError(quotations.error)}</p>
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={() => void quotations.refetch()}
          >
            Try again
          </Button>
        </div>
      ) : quotations.data.length === 0 ? (
        <p className="text-sm text-muted-foreground" role="status">
          This customer has no issued quotation to link.
        </p>
      ) : (
        <div className="space-y-2">
          <Label htmlFor="link-quotation">Issued quotation</Label>
          <Select value={revision} onValueChange={setRevision}>
            <SelectTrigger id="link-quotation" className="min-h-11">
              <SelectValue placeholder="Choose a quotation" />
            </SelectTrigger>
            <SelectContent>
              {quotations.data.map((q) => (
                <SelectItem key={q.revision_id} value={String(q.revision_id)}>
                  {`${q.official_number ?? `Revision ${q.revision_id}`} · ${q.availability.toLowerCase()}`}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
      {revision !== "" && scopes.isPending && (
        <div role="status">
          <Skeleton className="h-16 w-full" />
          <span className="sr-only">Loading scopes…</span>
        </div>
      )}
      {scopes.isError && (
        <div role="alert" className="space-y-2 text-sm">
          <p>{describeLoadError(scopes.error)}</p>
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={() => void scopes.refetch()}
          >
            Try again
          </Button>
        </div>
      )}
      {data && data.scopes === null && (
        <p className="text-sm" role="note">
          The scopes of this quotation are not shown to you, so it cannot be
          linked.
        </p>
      )}
      {data && data.scopes && (
        <ul className="space-y-2" aria-label="Scopes of the chosen quotation">
          {data.scopes.map((s) => {
            const added = chosen.has(
              identityOf({
                family_id: data.family_id,
                original_scope_anchor_id: s.logical_scope_id,
              }),
            );
            return (
              <li
                key={s.issued_line_id}
                className="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-md border border-border p-2 text-sm"
              >
                <span>{`Scope ${s.logical_scope_id}`}</span>
                <Button
                  className="min-h-11"
                  type="button"
                  variant="outline"
                  disabled={added || full}
                  aria-label={`${added ? "Linked" : "Link"} scope ${s.logical_scope_id} of ${data.official_number}`}
                  onClick={() => add(s)}
                >
                  {added ? "Linked" : "Link"}
                </Button>
              </li>
            );
          })}
        </ul>
      )}
      {value.length > 0 && (
        <ul className="space-y-2" aria-label="Linked scopes">
          {value.map((c) => (
            <li
              key={identityOf(c)}
              className="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-muted/40 p-2 text-sm"
            >
              <span className="min-w-0 break-words">{c.intent_identity}</span>
              <Button
                className="min-h-11"
                type="button"
                variant="outline"
                aria-label={`Remove ${c.intent_identity}`}
                onClick={() =>
                  onChange(value.filter((x) => identityOf(x) !== identityOf(c)))
                }
              >
                Remove
              </Button>
            </li>
          ))}
        </ul>
      )}
      {full && (
        <p className="text-xs text-muted-foreground" role="note">
          A case can link at most {MAX_LINKED_SCOPES} scopes.
        </p>
      )}
    </div>
  );
}

function identityOf(c: {
  family_id: number;
  original_scope_anchor_id: number;
}): string {
  return `${c.family_id}:${c.original_scope_anchor_id}`;
}
