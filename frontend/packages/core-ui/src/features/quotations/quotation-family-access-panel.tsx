import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router";
import { useAuthStore } from "../../stores/auth-store";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { shouldRetryQuery } from "../../lib/api-errors";
import { quotationAccess } from "./quotation-access";
import {
  FamilyAccessForm,
  accessRefusalMessage,
} from "./quotation-family-access-form";
import { FamilyAccessHistory } from "./quotation-family-access-history";
import { liveFamilyAccessGateway } from "./quotation-family-access-gateway";
import type { AccessFormMode } from "./quotation-family-access-form-model";
import type {
  AccessHistoryQuery,
  AccessOutcome,
  FamilyAccess,
  FamilyAccessGateway,
} from "./quotation-family-access-contract";

export function useFamilyAccessData(
  familyId: number | null,
  query: AccessHistoryQuery = {},
  gateway: FamilyAccessGateway = liveFamilyAccessGateway,
  enabled = true,
) {
  const actorId = useAuthStore((state) => state.user?.id);
  return useQuery({
    queryKey: [
      "quotation-family-access",
      actorId ?? "fixture",
      familyId,
      query,
    ],
    queryFn: () => gateway.read(familyId!, query),
    enabled:
      enabled &&
      familyId !== null &&
      Number.isSafeInteger(familyId) &&
      familyId > 0,
    retry: shouldRetryQuery,
  });
}

/** Production entry point: denies before mounting a query. The internal workspace is fixture-injectable. */
export function QuotationFamilyAccessPanel({ familyId }: { familyId: number }) {
  const user = useAuthStore((state) => state.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter(
      (value): value is string => typeof value === "string",
    ),
  );
  return quotationAccess(permissions).detail ? (
    <FamilyAccessWorkspace
      key={`${user?.id}:${familyId}`}
      familyId={familyId}
    />
  ) : null;
}
const scopes: Record<FamilyAccess["record_scope"], string> = {
  OWN: "Your current responsibility",
  MY_UNITS: "Your active unit scope",
  DELEGATED: "Named access shared with you",
  ALL: "Company-wide quotation access",
};
function cursor(value: string | null): number | undefined | false {
  if (value === null) return undefined;
  const id = Number(value);
  return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(id) ? id : false;
}

/** Internal testable renderer, never an alternate route or a public allow flag. */
export function FamilyAccessWorkspace({
  familyId,
  gateway = liveFamilyAccessGateway,
}: {
  familyId: number;
  gateway?: FamilyAccessGateway;
}) {
  const client = useQueryClient();
  const actorId = useAuthStore((state) => state.user?.id);
  const [search, setSearch] = useSearchParams();
  const ownership = cursor(search.get("access_ownership_before"));
  const shares = cursor(search.get("access_shares_before"));
  const valid = ownership !== false && shares !== false;
  const params = useMemo<AccessHistoryQuery>(
    () => ({
      ...(ownership ? { ownership_before: ownership } : {}),
      ...(shares ? { shares_before: shares } : {}),
    }),
    [ownership, shares],
  );
  const query = useFamilyAccessData(familyId, params, gateway, valid);
  const [form, setForm] = useState<{
    actor: number | undefined;
    mode: AccessFormMode;
    snapshot: FamilyAccess;
  } | null>(null);
  const [unavailable, setUnavailable] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  useEffect(() => {
    setForm(null);
    setResult(null);
    setUnavailable(false);
  }, [familyId, actorId]);
  function latest() {
    const next = new URLSearchParams(search);
    next.delete("access_ownership_before");
    next.delete("access_shares_before");
    const hadHistory =
      search.has("access_ownership_before") ||
      search.has("access_shares_before");
    setSearch(next);
    setUnavailable(false);
    if (!hadHistory) void query.refetch();
  }
  function older(key: string, generation: number | null | undefined) {
    if (!generation) return;
    const next = new URLSearchParams(search);
    next.set(key, String(generation));
    setSearch(next);
  }
  function written(outcome: AccessOutcome) {
    setForm(null);
    setResult(
      outcome.replayed
        ? "The earlier change was confirmed."
        : outcome.changed
          ? "Quotation access and responsibility updated."
          : "No change was needed.",
    );
    void client.invalidateQueries({
      predicate: (entry) =>
        typeof entry.queryKey[0] === "string" &&
        (entry.queryKey[0].startsWith("quotation-") ||
          entry.queryKey[0].startsWith("/v1/quotation")),
    });
  }
  const snapshot = query.data;
  const failure = !valid
    ? "This history link is not valid."
    : unavailable
      ? "Access details are no longer available with your current permissions."
      : query.isError
        ? accessRefusalMessage(query.error)
        : null;
  return (
    <div
      className="min-w-0 space-y-4"
      aria-label="Quotation access and responsibility"
    >
      {result && (
        <p role="status" className="text-sm">
          {result}
        </p>
      )}
      {failure ? (
        <DetailSection title="Access and responsibility" fullWidth>
          <div className="col-span-full space-y-3">
            <p role="alert">{failure}</p>
            <Button type="button" variant="outline" onClick={latest}>
              Show latest access details
            </Button>
            <Link
              className="ui-link-action block"
              to={`/quotations${search.size ? `?${search}` : ""}`}
            >
              Back to quotations
            </Link>
          </div>
        </DetailSection>
      ) : query.isPending || query.isFetching ? (
        <div role="status">
          <Skeleton className="h-32 w-full" />
          <span className="sr-only">Loading access details…</span>
        </div>
      ) : snapshot ? (
        <DetailPageLayout>
          <DetailSection title="Access and responsibility" fullWidth>
            <DetailField label="Responsible owner">
              {snapshot.responsible_owner?.name ?? "Not yet recorded"}
              {snapshot.responsible_owner &&
                snapshot.responsible_owner.status !== "ACTIVE" && (
                  <StatusBadge tone="pending">Inactive owner</StatusBadge>
                )}
            </DetailField>
            <DetailField label="Created by">
              {snapshot.created_by_name ?? `User #${snapshot.created_by}`}
            </DetailField>
            <DetailField label="Access unit">
              {snapshot.access_unit?.name ?? "No access unit"}
            </DetailField>
            <DetailField label="Your access">
              {scopes[snapshot.record_scope]}
            </DetailField>
            <DetailField label="Quotation work">
              {snapshot.allowed_actions.prepare
                ? "View and prepare"
                : "View only"}
            </DetailField>
            <div className="col-span-full space-y-3">
              <p className="text-sm text-muted-foreground">
                Access applies across this quotation's revisions. A separate new
                quotation starts without these shares.
              </p>
              {snapshot.baseline_state === "UNRESOLVED" && (
                <p role="status" className="text-sm">
                  This quotation needs an approved ownership baseline before
                  access can be managed.
                </p>
              )}
              <div className="flex flex-wrap gap-2">
                {snapshot.allowed_actions.assign && (
                  <>
                    <Button
                      type="button"
                      variant="outline"
                      className="min-h-11"
                      onClick={() =>
                        setForm({
                          actor: actorId,
                          mode: { operation: "owner" },
                          snapshot,
                        })
                      }
                    >
                      Change responsible owner…
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      className="min-h-11"
                      onClick={() =>
                        setForm({
                          actor: actorId,
                          mode: { operation: "unit" },
                          snapshot,
                        })
                      }
                    >
                      Change access unit…
                    </Button>
                  </>
                )}
                {snapshot.allowed_actions.delegate_view && (
                  <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() =>
                      setForm({
                        actor: actorId,
                        mode: { operation: "grant" },
                        snapshot,
                      })
                    }
                  >
                    Share quotation…
                  </Button>
                )}
              </div>
              {(ownership || shares) && (
                <Button type="button" variant="ghost" onClick={latest}>
                  Latest history
                </Button>
              )}
            </div>
          </DetailSection>
          <FamilyAccessHistory
            snapshot={snapshot}
            pending={query.isFetching}
            onRevoke={(share) =>
              setForm({
                actor: actorId,
                mode: { operation: "revoke", share },
                snapshot,
              })
            }
            onOlderOwnership={() =>
              older(
                "access_ownership_before",
                snapshot.ownership_history_next_generation,
              )
            }
            onOlderShares={() =>
              older("access_shares_before", snapshot.shares_next_generation)
            }
          />
        </DetailPageLayout>
      ) : (
        <p role="alert">Access details could not be read.</p>
      )}
      {form &&
        form.actor === actorId &&
        form.snapshot.family_id === familyId && (
          <FamilyAccessForm
            key={`${actorId}:${familyId}:${form.mode.operation}:${form.mode.operation === "revoke" ? form.mode.share.id : ""}`}
            snapshot={form.snapshot}
            mode={form.mode}
            gateway={gateway}
            onClose={() => setForm(null)}
            onWritten={written}
            onUnavailable={() => {
              setUnavailable(true);
              setForm(null);
            }}
          />
        )}
    </div>
  );
}
