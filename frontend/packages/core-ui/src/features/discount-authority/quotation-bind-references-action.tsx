import { useRef, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { getApiReasonCode } from "../../lib/api-errors";
import { useAuthStore } from "../../stores/auth-store";
import { IntentKey } from "../quotations/request-key";
import { discountAuthorityAccess } from "./discount-authority-access";
import { liveQuotationAuthorityGateway } from "./discount-authority-gateway";
import {
  AUTHORITY_REFUSALS,
  type QuotationAuthorityGateway,
} from "./discount-authority-contract";

/** Plain wording for a refused capture; here a stale generation means the draft moved, not the configuration. */
export function bindRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code === "AUTHORITY_STALE")
    return "This quotation changed since you reviewed it. Recheck it, then capture the references again.";
  if (code && AUTHORITY_REFUSALS[code]) return AUTHORITY_REFUSALS[code];
  return "The references could not be captured. Check the quotation and try again.";
}

/**
 * Capture the approved price reference for each line of a draft. It records which trusted price each line is measured against;
 * it changes no price, clears no discount and approves nothing. Renders nothing unless the person may prepare this quotation
 * and the quotation UI gate is open (the server still refuses otherwise).
 */
export function BindQuotationReferencesAction({
  revisionId,
  expectedGeneration,
  gateway = liveQuotationAuthorityGateway,
}: {
  revisionId: number;
  /** The draft generation the person has reviewed; a newer draft needs a new review first. */
  expectedGeneration: number;
  gateway?: QuotationAuthorityGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  if (!discountAuthorityAccess(permissions).bind) return null;
  return (
    <BindControl
      revisionId={revisionId}
      expectedGeneration={expectedGeneration}
      gateway={gateway}
    />
  );
}

function BindControl({
  revisionId,
  expectedGeneration,
  gateway,
}: {
  revisionId: number;
  expectedGeneration: number;
  gateway: QuotationAuthorityGateway;
}) {
  const client = useQueryClient();
  const [open, setOpen] = useState(false);
  const [done, setDone] = useState<number | null>(null);
  // One key per exact intent (revision + reviewed generation): an uncertain failure keeps it so a retry replays.
  const keys = useRef(new IntentKey());
  const bind = useMutation({
    mutationFn: () =>
      gateway.bind({
        revisionId,
        expectedGeneration,
        requestKey: keys.current.for({ revisionId, expectedGeneration }),
      }),
    onSuccess: (result) => {
      keys.current.settle();
      setOpen(false);
      setDone(result.bound_lines);
      void client.invalidateQueries({ queryKey: ["quotation-discount-authority"] });
    },
    onError: (error) => keys.current.settle(error),
  });
  return (
    <DetailSection
      title="Price references"
      description="Capture the approved trusted price each line is measured against, so a discount can be checked."
      fullWidth
    >
      <div className="col-span-full space-y-2">
        {done !== null && (
          <p role="status" className="text-sm">
            References captured for {done} {done === 1 ? "line" : "lines"}. Nothing was priced or approved by this.
          </p>
        )}
        <Button
          className="min-h-11"
          type="button"
          variant="outline"
          onClick={() => {
            bind.reset();
            setDone(null);
            setOpen(true);
          }}
        >
          Capture approved references
        </Button>
      </div>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (!next && !bind.isPending) setOpen(false);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Capture approved references?</DialogTitle>
            <DialogDescription>
              Each line of this draft will be tied to the independently approved price reference that covers it. This
              changes no price and approves no discount, and the capture carries over to later revisions of this quotation.
              A line without an approved reference stops the capture.
            </DialogDescription>
          </DialogHeader>
          {bind.error !== null && (
            <p role="alert" className="text-sm text-destructive">
              {bindRefusalMessage(bind.error)}
            </p>
          )}
          <DialogFooter>
            <Button className="min-h-11" type="button" variant="outline" disabled={bind.isPending} onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button className="min-h-11" type="button" disabled={bind.isPending} onClick={() => bind.mutate()}>
              {bind.isPending ? "Capturing…" : "Capture references"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </DetailSection>
  );
}
