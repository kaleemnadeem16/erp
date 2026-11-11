import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router";
import { RecordSheet } from "../../components/page/record-sheet";
import { Button } from "../../components/ui/button";
import { Label } from "../../components/ui/label";
import { Skeleton } from "../../components/ui/skeleton";
import { isTransientFailure, shouldRetryQuery } from "../../lib/api-errors";
import { IntentKey } from "../quotations/request-key";
import {
  needsFreshReview,
  previewScope,
  refusalMessage,
  warningText,
  type AcceptancePreview,
  type AcceptanceTarget,
  type CustomerAcceptanceGateway,
} from "./customer-acceptance-contract";

/** The exact issued terms for one line, as the server states them. Nothing here can be edited. */
export function AcceptanceTermsList({
  terms,
}: {
  terms: Pick<
    AcceptancePreview["terms"],
    | "description"
    | "quantity"
    | "uom_id"
    | "unit_price"
    | "currency_id"
    | "net"
    | "tax"
    | "gross"
  >;
}) {
  const currency = `Currency reference #${terms.currency_id}`;
  const rows: [string, string][] = [
    ["Description", terms.description],
    [
      "Quantity",
      terms.uom_id === null
        ? terms.quantity
        : `${terms.quantity} · Unit reference #${terms.uom_id}`,
    ],
    ["Unit price", `${terms.unit_price} · ${currency}`],
    ["Net", terms.net],
    ["Tax", terms.tax ?? "Not stated"],
    ["Total including tax", terms.gross ?? "Not stated"],
  ];
  return (
    <dl
      className="grid gap-3 rounded-md border border-border p-3 text-sm sm:grid-cols-2"
      data-testid="acceptance-terms"
    >
      {rows.map(([label, value]) => (
        <div key={label} className="min-w-0">
          <dt className="text-xs text-muted-foreground">{label}</dt>
          <dd className="break-words">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function minutesText(seconds: number): string {
  const minutes = Math.max(1, Math.ceil(seconds / 60));
  return minutes === 1 ? "about 1 minute" : `about ${minutes} minutes`;
}

/**
 * The review sheet: exact terms, the unchanged-terms statement, any warnings, then the confirm. The confirm sends the SAME intent the
 * server reviewed, under one Idempotency-Key per (intent, review): an uncertain failure keeps the key so the retry replays instead of
 * recording twice; a changed review mints a new one.
 */
export function AcceptanceReviewSheet({
  gateway,
  target,
  onClose,
}: {
  gateway: CustomerAcceptanceGateway;
  target: AcceptanceTarget;
  onClose: () => void;
}) {
  const navigate = useNavigate();
  const client = useQueryClient();
  const [confirmed, setConfirmed] = useState(false);
  const review = useQuery({
    queryKey: ["customer-acceptance-preview", target],
    queryFn: () => previewScope(gateway, target),
    retry: shouldRetryQuery,
    // A review is a moment-in-time statement: never reuse a stale one silently.
    gcTime: 0,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const keys = useRef(new IntentKey());
  const record = useMutation({
    mutationFn: (preview: AcceptancePreview) =>
      gateway.record({
        intent: preview.intent,
        previewToken: preview.preview_token,
        requestKey: keys.current.for({
          intent: preview.intent,
          token: preview.preview_token,
        }),
      }),
    onSuccess: (outcome) => {
      keys.current.settle();
      void client.invalidateQueries({ queryKey: ["quotation-issued"] });
      void navigate(`/customer-acceptances/${outcome.acceptance_id}`, {
        state: { recorded: true, replayed: outcome.replayed },
      });
    },
    onError: (error) => keys.current.settle(error),
  });
  const data = review.data;
  // A new review (after "Review again") starts unconfirmed.
  useEffect(() => setConfirmed(false), [data?.preview_token]);
  const stale = record.isError && needsFreshReview(record.error);
  return (
    <RecordSheet
      title="Review before you confirm"
      description="The exact terms of the issued offer. Nothing can be changed here."
      size="md"
      dismissOnOutsideClick={false}
      onClose={onClose}
    >
      <div className="space-y-4" aria-live="polite">
        {review.isPending && (
          <div role="status">
            <Skeleton className="h-40 w-full" />
            <span className="sr-only">Preparing the review…</span>
          </div>
        )}
        {review.isError && (
          <div role="alert" className="space-y-3">
            <p className="text-sm text-destructive">
              {refusalMessage(review.error)}
            </p>
            <Button
              className="min-h-11"
              type="button"
              variant="outline"
              onClick={() => void review.refetch()}
            >
              Try again
            </Button>
          </div>
        )}
        {data && (
          <>
            <AcceptanceTermsList terms={data.terms} />
            {data.intent.expected_choice_generation !== null && (
              <p
                className="rounded-md border border-border bg-muted/40 p-3 text-sm"
                role="note"
              >
                This scope is one of several alternatives. Once one is accepted,
                the others in the set can no longer be accepted.
              </p>
            )}
            <p className="text-sm font-medium" data-testid="unchanged-statement">
              {data.unchanged_terms_statement}
            </p>
            {data.warnings.length > 0 && (
              <ul
                className="space-y-1 rounded-md border border-border bg-muted/40 p-3 text-sm"
                aria-label="Warnings"
              >
                {data.warnings.map((code) => (
                  <li key={code}>{warningText(code)}</li>
                ))}
              </ul>
            )}
            <p className="text-xs text-muted-foreground">
              This review stays valid for {minutesText(data.expires_in_seconds)}.
              Recording an acceptance does not create an order, a job, a stock
              reservation or an invoice, and it is not a sales outcome.
            </p>
            <div className="flex items-start gap-3">
              <input
                id="acceptance-unchanged"
                type="checkbox"
                className="mt-3 size-5 shrink-0 accent-[var(--primary)]"
                checked={confirmed}
                onChange={(event) => setConfirmed(event.target.checked)}
              />
              <Label
                htmlFor="acceptance-unchanged"
                className="flex min-h-11 items-center text-sm"
              >
                The customer accepts these terms unchanged.
              </Label>
            </div>
            {record.isError && (
              <p role="alert" className="text-sm text-destructive">
                {refusalMessage(record.error)}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {stale ? (
                <Button
                  className="min-h-11"
                  type="button"
                  onClick={() => {
                    record.reset();
                    void review.refetch();
                  }}
                >
                  Review again
                </Button>
              ) : (
                <Button
                  className="min-h-11"
                  type="button"
                  disabled={!confirmed || record.isPending}
                  onClick={() => record.mutate(data)}
                >
                  {record.isPending
                    ? "Recording…"
                    : record.isError && isTransientFailure(record.error)
                      ? "Try again"
                      : "Record acceptance"}
                </Button>
              )}
              <Button
                className="min-h-11"
                type="button"
                variant="outline"
                onClick={onClose}
              >
                Cancel
              </Button>
            </div>
          </>
        )}
        {!data && !review.isPending && (
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={onClose}
          >
            Close
          </Button>
        )}
      </div>
    </RecordSheet>
  );
}
