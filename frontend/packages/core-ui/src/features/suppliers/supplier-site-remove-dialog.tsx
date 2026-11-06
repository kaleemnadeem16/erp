import { useState } from "react";
import { useSupplierSitesDestroy, type SupplierSiteResource } from "@erp/core-api";

import { Button } from "../../components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { getApiErrorMessage } from "../../lib/api-errors";

/**
 * Asks before a supplier address is taken off a supplier, on the record and inside the edit form alike. The address itself is kept
 * (another role of the same trading partner may use it); a refusal from the server stays in the dialog in its own words.
 * Render it only while a site is being removed.
 */
export function SupplierSiteRemoveDialog({
  site,
  onClose,
  onRemoved,
}: {
  site: SupplierSiteResource;
  onClose: () => void;
  onRemoved: () => void | Promise<unknown>;
}) {
  const removeSite = useSupplierSitesDestroy();
  const [error, setError] = useState<string | null>(null);

  return (
    <Dialog open onOpenChange={(open) => !open && !removeSite.isPending && onClose()}>
      <DialogContent className="max-h-[90dvh] max-w-md overflow-y-auto" data-testid="remove-site-dialog">
        <DialogHeader>
          <DialogTitle>Remove {site.party_site?.name ?? "this address"} from this supplier?</DialogTitle>
          <DialogDescription>
            The address stops being offered for this supplier. The address itself is kept, because another role of the same trading partner (a customer, say) may still use it.
          </DialogDescription>
        </DialogHeader>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="outline" className="h-11 md:h-9" disabled={removeSite.isPending} onClick={onClose}>
            Keep it
          </Button>
          <Button
            variant="destructive"
            className="h-11 md:h-9"
            disabled={removeSite.isPending}
            onClick={async () => {
              try {
                await removeSite.mutateAsync({ supplierSite: site.id });
                onClose();
                await onRemoved();
              } catch (failure) {
                setError(getApiErrorMessage(failure) ?? "The address could not be removed.");
              }
            }}
          >
            {removeSite.isPending ? "Removing…" : "Remove address"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
