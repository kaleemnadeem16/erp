import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { usePermission } from "../../hooks/use-permission";
import { quotationDevModeEnabled } from "../../lib/quotation-dev-mode";
import { AccessDenied } from "../../routes/permission-route";

/** Entry by revision identity; this is deliberately not a list or a search endpoint. */
export function QuotationsWorkspaceScreen() {
  const mayView = usePermission("sales.quotation.view");
  const mayPrepare = usePermission("sales.quotation.prepare");
  if (!quotationDevModeEnabled() || (!mayView && !mayPrepare))
    return <AccessDenied />;
  return <OpenQuotation mayView={mayView} mayPrepare={mayPrepare} />;
}

function OpenQuotation({
  mayView,
  mayPrepare,
}: {
  mayView: boolean;
  mayPrepare: boolean;
}) {
  const navigate = useNavigate();
  const [revision, setRevision] = useState("");
  const [error, setError] = useState<string | null>(null);
  function open(review: boolean) {
    if (
      !/^[1-9]\d*$/.test(revision) ||
      !Number.isSafeInteger(Number(revision))
    ) {
      setError("Enter a positive whole revision number.");
      return;
    }
    navigate(`/quotations/${revision}${review ? "/review" : ""}`);
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    open(!mayView);
  }
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Quotations"
        description="Open a quotation by its revision number."
      />
      <DetailSection title="Open a quotation">
        <form className="col-span-full space-y-4" onSubmit={submit}>
          <div className="space-y-2">
            <Label htmlFor="quotation-revision">Revision number</Label>
            <Input
              id="quotation-revision"
              inputMode="numeric"
              value={revision}
              onChange={(event) => {
                setRevision(event.target.value);
                setError(null);
              }}
              aria-invalid={!!error}
              aria-describedby={error ? "quotation-revision-error" : undefined}
            />
            {error && (
              <p
                id="quotation-revision-error"
                role="alert"
                className="text-sm text-destructive"
              >
                {error}
              </p>
            )}
          </div>
          <div className="flex flex-wrap gap-3">
            {mayView && <Button type="submit">Open details</Button>}
            {mayPrepare && (
              <Button
                type={mayView ? "button" : "submit"}
                variant={mayView ? "outline" : "default"}
                onClick={mayView ? () => open(true) : undefined}
              >
                Review quotation
              </Button>
            )}
          </div>
        </form>
      </DetailSection>
    </ScreenTemplate>
  );
}
