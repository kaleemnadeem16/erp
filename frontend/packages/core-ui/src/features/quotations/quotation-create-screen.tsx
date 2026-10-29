import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router";
import {
  quotationsStore,
  useCurrenciesIndex,
  useOpportunitiesIndex,
  useOpportunitiesShow,
  type StoreQuotationDraftRequest,
} from "@erp/core-api";
import { useAuthStore } from "../../stores/auth-store";
import { quotationAccess } from "./quotation-access";
import { AccessDenied } from "../../routes/permission-route";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { PartyCombobox } from "../../components/lookups/entity-comboboxes";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { Label } from "../../components/ui/label";
import { Button } from "../../components/ui/button";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import {
  getApiErrorMessage,
  getApiReasonCode,
  isApiError,
  isTransientFailure,
  lookupState,
} from "../../lib/api-errors";
import { newAttemptKey, type PendingAttempt } from "./quotation-draft-changes";

type Fields = {
  recipient: string;
  currency: string;
  pricing: string;
  tax: string;
  valid: string;
  opportunity?: string;
};
type Stored = {
  fields: Fields;
  pending: Extract<PendingAttempt, { kind: "create" }> | null;
};
const EMPTY: Fields = {
  recipient: "",
  currency: "",
  pricing: "",
  tax: "",
  valid: "",
  opportunity: "",
};
const STORAGE = "quotation-create";

export function QuotationCreateScreen() {
  const user = useAuthStore((s) => s.user);
  const access = quotationAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (x): x is string => typeof x === "string",
      ),
    ),
  );
  return access.review ? <QuotationCreateForm /> : <AccessDenied />;
}

export function QuotationCreateForm() {
  const navigate = useNavigate();
  const usePipeline = useAuthStore((s) =>
    (s.user?.permissions ?? []).includes("sales.pipeline.view"),
  );
  const [saved] = useState(() => readDraft<Stored>(STORAGE)?.value);
  const [fields, setFields] = useState<Fields>(saved?.fields ?? EMPTY);
  const [pending, setPending] = useState<Stored["pending"]>(
    saved?.pending ?? null,
  );
  const [review, setReview] = useState(false);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const currencies = useCurrenciesIndex({ per_page: 250, sort: "code" });
  const rows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  useEffect(() => {
    if (pending || Object.values(fields).some(Boolean))
      writeDraft(STORAGE, { fields, pending });
    else clearDraft(STORAGE);
  }, [fields, pending]);
  function set(key: keyof Fields, value: string) {
    setFields((previous) => ({ ...previous, [key]: value }));
    setReview(false);
    setError(null);
    setFieldErrors({});
  }
  function prepare() {
    const payload: StoreQuotationDraftRequest = {};
    if (fields.recipient) payload.recipient_party_id = Number(fields.recipient);
    if (fields.currency) payload.currency_id = Number(fields.currency);
    if (fields.pricing) payload.pricing_date = fields.pricing;
    if (fields.tax) payload.tax_estimate_date = fields.tax;
    if (fields.valid) payload.valid_through_local_date = fields.valid;
    if (fields.opportunity) payload.opportunity_id = Number(fields.opportunity);
    return payload;
  }
  async function submit(attempt: NonNullable<Stored["pending"]>) {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    setError(null);
    // Persist before sending: navigation or a lost response must not create a second draft.
    writeDraft(STORAGE, { fields, pending: attempt });
    setPending(attempt);
    try {
      const response = await quotationsStore(attempt.payload, {
        "Idempotency-Key": attempt.key,
      });
      if (response.status !== 200 && response.status !== 201)
        throw new Error("Unexpected server response.");
      const receipt = response.data.data;
      if (!Number.isSafeInteger(receipt.revision_id) || receipt.revision_id < 1)
        throw new Error("The server did not return a usable revision.");
      clearDraft(STORAGE);
      navigate(`/quotations/${receipt.revision_id}/edit`, { replace: true });
    } catch (failure) {
      if (isTransientFailure(failure)) {
        setError(
          "The result is uncertain. Retry this exact request before making changes; this will not create another draft if the first request succeeded.",
        );
      } else {
        setPending(null);
        setFieldErrors(
          isApiError(failure) && failure.status === 422
            ? (failure.body?.errors ?? {})
            : {},
        );
        writeDraft(STORAGE, { fields, pending: null });
        setReview(false);
        setError(
          isApiError(failure) && failure.status === 409
            ? "This request key was already used for different input. Review and submit again."
            : getApiReasonCode(failure) === "QUOTATION_DRAFT_STALE"
              ? "The quotation evidence changed. Review your input and try again."
              : (getApiErrorMessage(failure) ??
                "The draft could not be created."),
        );
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }
  return (
    <ScreenTemplate kind="form" className="erp-screen space-y-4 pb-24">
      <PageHeader
        title="Create quotation draft"
        description="Create the header first; add lines after the draft is saved."
        actions={
          <Link className="ui-link-action" to="/quotations">
            Back to quotations
          </Link>
        }
      />
      {saved && <p className="text-sm">Picked up your unfinished quotation.</p>}
      {fields.opportunity && !usePipeline && !pending && (
        <p role="alert">
          This unfinished draft includes an Opportunity you can no longer view.{" "}
          <Button
            type="button"
            variant="ghost"
            onClick={() => set("opportunity", "")}
          >
            Remove Opportunity
          </Button>
        </p>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {pending ? (
        <DetailSection title="Resolve the previous request">
          <div className="col-span-full space-y-3">
            <p>
              The last request may have completed. Retry with the same saved
              input and key.
            </p>
            <Button
              type="button"
              disabled={busy}
              onClick={() => void submit(pending)}
            >
              Retry create
            </Button>
          </div>
        </DetailSection>
      ) : (
        <>
          <DetailSection
            title="Draft header"
            description="These fields can be completed later."
          >
            <div className="space-y-2">
              <Label htmlFor="quotation-recipient">Recipient (optional)</Label>
              <PartyCombobox
                id="quotation-recipient"
                label="Recipient"
                value={fields.recipient}
                onValueChange={(value) => {
                  setFields((previous) => ({
                    ...previous,
                    recipient: value,
                    opportunity: "",
                  }));
                  setReview(false);
                  setError(null);
                }}
              />
              {fieldErrors.recipient_party_id?.map((message) => (
                <p
                  role="alert"
                  className="text-sm text-destructive"
                  key={message}
                >
                  {message}
                </p>
              ))}
              {fields.recipient && (
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => set("recipient", "")}
                >
                  Clear recipient
                </Button>
              )}
            </div>
            {usePipeline && (
              <OpportunityPicker
                recipient={fields.recipient}
                value={fields.opportunity ?? ""}
                onChange={(value) => set("opportunity", value)}
              />
            )}
            <div className="space-y-2">
              <Label htmlFor="quotation-currency">Currency</Label>
              <Combobox
                id="quotation-currency"
                value={fields.currency || "default"}
                onValueChange={(value) =>
                  set("currency", value === "default" ? "" : value)
                }
                options={[
                  { value: "default", label: "Company base currency" },
                  ...rows.map((row) => ({
                    value: String(row.id),
                    label: `${row.code} — ${row.name}`,
                  })),
                ]}
                {...lookupState(currencies, "currencies")}
              />
              {fieldErrors.currency_id?.map((message) => (
                <p
                  role="alert"
                  className="text-sm text-destructive"
                  key={message}
                >
                  {message}
                </p>
              ))}
            </div>
            {(
              [
                ["pricing", "Pricing date"],
                ["tax", "Tax estimate date"],
                ["valid", "Valid through"],
              ] as const
            ).map(([key, label]) => (
              <div className="space-y-2" key={key}>
                <Label htmlFor={`quotation-${key}`}>{label}</Label>
                <DateField
                  id={`quotation-${key}`}
                  value={fields[key]}
                  onChange={(value) => set(key, value)}
                />
                {fieldErrors[
                  (
                    {
                      pricing: "pricing_date",
                      tax: "tax_estimate_date",
                      valid: "valid_through_local_date",
                    } as const
                  )[key]
                ]?.map((message) => (
                  <p
                    role="alert"
                    className="text-sm text-destructive"
                    key={message}
                  >
                    {message}
                  </p>
                ))}
              </div>
            ))}
          </DetailSection>
          {review && (
            <DetailSection title="Review before creating">
              <div className="col-span-full space-y-2">
                <p>
                  Create a draft header
                  {fields.recipient
                    ? " with the selected recipient"
                    : " without a recipient"}
                  . Lines are added after creation.
                </p>
                <p>
                  Currency:{" "}
                  {fields.currency
                    ? (rows.find((row) => String(row.id) === fields.currency)
                        ?.code ?? "selected currency")
                    : "company base currency"}
                  .{" "}
                  {fields.opportunity
                    ? "Linked to the selected Opportunity."
                    : "No Opportunity selected."}
                </p>
                <p>
                  Pricing date: {fields.pricing || "not set"}; tax estimate
                  date: {fields.tax || "not set"}; valid through:{" "}
                  {fields.valid || "not set"}.
                </p>
                <Button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    void submit({
                      kind: "create",
                      key: newAttemptKey(),
                      payload: prepare(),
                    })
                  }
                >
                  Create draft
                </Button>
              </div>
            </DetailSection>
          )}
          <div className="flex flex-wrap gap-3">
            <Button
              type="button"
              variant="outline"
              disabled={!!fields.opportunity && !usePipeline}
              onClick={() => setReview(true)}
            >
              Review draft
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                clearDraft(STORAGE);
                setFields(EMPTY);
                setReview(false);
                setError(null);
              }}
            >
              Start over
            </Button>
          </div>
        </>
      )}
    </ScreenTemplate>
  );
}

function OpportunityPicker({
  recipient,
  value,
  onChange,
}: {
  recipient: string;
  value: string;
  onChange: (value: string) => void;
}) {
  const [search, setSearch] = useState("");
  const list = useOpportunitiesIndex({
    "filter[outcome]": "OPEN",
    "filter[search]": search || undefined,
    "filter[party_id]": recipient ? Number(recipient) : undefined,
    per_page: 25,
  });
  const selected = useOpportunitiesShow(Number(value) || 0, undefined, {
    query: { enabled: !!value, retry: false },
  });
  const rows = list.data?.status === 200 ? list.data.data.data : [];
  const chosen =
    selected.data?.status === 200 ? selected.data.data.data : undefined;
  return (
    <div className="space-y-2">
      <Label htmlFor="quotation-opportunity">Opportunity (optional)</Label>
      <RemoteLookupCombobox
        id="quotation-opportunity"
        label="Opportunity"
        value={value}
        search={search}
        onSearchChange={setSearch}
        onValueChange={onChange}
        selectedLabel={chosen?.title}
        options={rows.map((row) => ({
          value: String(row.id),
          label: row.title,
        }))}
        {...lookupState(list, "opportunities")}
      />
      {value && (
        <Button type="button" variant="ghost" onClick={() => onChange("")}>
          Clear opportunity
        </Button>
      )}
    </div>
  );
}
