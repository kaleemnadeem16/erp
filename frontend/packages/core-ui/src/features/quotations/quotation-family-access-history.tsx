import { Button } from "../../components/ui/button";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import { formatDateTime } from "../../lib/format-date";
import type {
  FamilyAccess,
  FamilyShare,
} from "./quotation-family-access-contract";

const statusLabel: Record<FamilyShare["status"], string> = {
  ACTIVE: "Active",
  SCHEDULED: "Scheduled",
  EXPIRED: "Expired",
  REVOKED: "Revoked",
  INVALIDATED: "No longer effective",
};
const statusTone: Record<FamilyShare["status"], StatusTone> = {
  ACTIVE: "active",
  SCHEDULED: "pending",
  EXPIRED: "draft",
  REVOKED: "draft",
  INVALIDATED: "pending",
};
export function FamilyAccessHistory({
  snapshot,
  pending,
  onRevoke,
  onOlderOwnership,
  onOlderShares,
}: {
  snapshot: FamilyAccess;
  pending: boolean;
  onRevoke: (share: FamilyShare) => void;
  onOlderOwnership: () => void;
  onOlderShares: () => void;
}) {
  return (
    <>
      {snapshot.shares !== undefined && (
        <DetailSection title="Named access" fullWidth>
          <div className="col-span-full space-y-3">
            <p className="text-sm text-muted-foreground">
              These shares supply only their stated access. Independent roles or
              ownership may still allow access after a share ends.
            </p>
            {snapshot.shares.length === 0 ? (
              <p className="text-sm">No shares recorded.</p>
            ) : (
              <ul className="space-y-3">
                {snapshot.shares.map((share) => (
                  <li
                    key={share.id}
                    className="min-w-0 space-y-2 border-b border-border pb-3 text-sm"
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <strong className="break-words">
                        {share.recipient_name}
                      </strong>
                      <span>
                        {share.level === "PREPARE"
                          ? "View and prepare"
                          : "View only"}
                      </span>
                      <StatusBadge tone={statusTone[share.status]}>
                        {statusLabel[share.status]}
                      </StatusBadge>
                    </div>
                    <p>
                      From {formatDateTime(share.starts_at)}
                      {share.ends_at
                        ? ` until ${formatDateTime(share.ends_at)}`
                        : "; no fixed expiry"}
                    </p>
                    <p className="text-muted-foreground">
                      Shared by{" "}
                      {share.issuer_name ?? `user #${share.issuer_id}`} on{" "}
                      {formatDateTime(share.recorded_at)}
                      {share.revoked_at
                        ? `. Revoked by ${share.revoked_by_name ?? `user #${share.revoked_by}`} on ${formatDateTime(share.revoked_at)}`
                        : ""}
                      .
                    </p>
                    {snapshot.allowed_actions.revoke &&
                      share.status !== "REVOKED" && (
                        <Button
                          type="button"
                          variant="outline"
                          className="min-h-11"
                          disabled={pending}
                          onClick={() => onRevoke(share)}
                          aria-label={`Revoke ${share.level === "VIEW" ? "view" : "preparation"} share for ${share.recipient_name}`}
                        >
                          Revoke share…
                        </Button>
                      )}
                  </li>
                ))}
              </ul>
            )}
            {snapshot.shares_has_more && (
              <Button
                type="button"
                variant="outline"
                disabled={pending}
                onClick={onOlderShares}
              >
                Older shares
              </Button>
            )}
          </div>
        </DetailSection>
      )}
      {snapshot.ownership_history !== undefined && (
        <DetailSection title="Responsibility history" fullWidth>
          <div className="col-span-full space-y-3">
            <p className="text-sm text-muted-foreground">
              Responsibility at each change is retained. Display names reflect
              the current directory; free-text audit reasons are not shown here.
            </p>
            {snapshot.ownership_history.length === 0 ? (
              <p className="text-sm">No responsibility changes on this page.</p>
            ) : (
              <ol className="space-y-3">
                {snapshot.ownership_history.map((event) => (
                  <li
                    key={event.id}
                    className="min-w-0 border-b border-border pb-3 text-sm"
                  >
                    <p className="break-words">
                      {event.kind === "UNIT_CHANGED"
                        ? `Access unit: ${event.old_unit_name ?? "No unit"} → ${event.new_unit_name ?? "No unit"}`
                        : event.kind === "REASSIGNED"
                          ? `Responsible owner: ${event.old_owner_name ?? `user #${event.old_owner_id}`} → ${event.new_owner_name ?? `user #${event.new_owner_id}`}`
                          : `${event.kind === "INITIAL" ? "Initial responsibility" : "Approved baseline"}: ${event.new_owner_name ?? `user #${event.new_owner_id}`}`}
                    </p>
                    <p className="text-muted-foreground">
                      {formatDateTime(event.recorded_at)} ·{" "}
                      {event.actor_name ?? `user #${event.actor_id}`}
                    </p>
                  </li>
                ))}
              </ol>
            )}
            {snapshot.ownership_history_has_more && (
              <Button
                type="button"
                variant="outline"
                disabled={pending}
                onClick={onOlderOwnership}
              >
                Older responsibility changes
              </Button>
            )}
          </div>
        </DetailSection>
      )}
    </>
  );
}
