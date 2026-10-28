import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import {
  quotationsUpdate,
  useQuotationsEditable,
  useCurrenciesIndex,
} from "@erp/core-api";
import { useAuthStore } from "../../stores/auth-store";
import { AccessDenied } from "../../routes/permission-route";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { DetailSection } from "../../components/detail/detail-page-layout";
import {
  PartyCombobox,
  ItemCombobox,
} from "../../components/lookups/entity-comboboxes";
import { Combobox } from "../../components/ui/combobox";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { DateField } from "../../components/ui/date-field";
import { DecimalInput } from "../../components/ui/decimal-input";
import { checkDecimal } from "../../lib/decimal";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import {
  getApiErrorMessage,
  getApiReasonCode,
  isApiError,
  isTransientFailure,
  lookupState,
} from "../../lib/api-errors";
import { quotationAccess } from "./quotation-access";
import { revisionId } from "./quotation-contract";
import { QuotationFailure } from "./quotation-display";
import {
  buildChanges,
  hasChanges,
  newAttemptKey,
  readEditable,
  valuesFromEditable,
  type DraftValues,
  type Editable,
  type EditableLine,
  type NewLine,
  type PendingAttempt,
} from "./quotation-draft-changes";

type EditAttempt = Extract<PendingAttempt, { kind: "edit" }>;
type Stored = {
  baseline: Editable;
  values: DraftValues;
  pending: EditAttempt | null;
};
export function QuotationEditScreen() {
  const id = revisionId(useParams<{ revision: string }>().revision);
  const user = useAuthStore((s) => s.user);
  const access = quotationAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (x): x is string => typeof x === "string",
      ),
    ),
  );
  if (!access.review) return <AccessDenied />;
  return id ? (
    <QuotationEditForm id={id} />
  ) : (
    <QuotationFailure error={Object.assign(new Error(), { status: 404 })} />
  );
}
export function QuotationEditForm({ id }: { id: number }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const key = `quotation-edit-${id}`;
  const [stored] = useState(() => readDraft<Stored>(key)?.value);
  const [baseline, setBaseline] = useState<Editable | null>(
    stored?.baseline ?? null,
  );
  const [values, setValues] = useState<DraftValues | null>(
    stored?.values ?? null,
  );
  const [pending, setPending] = useState<EditAttempt | null>(
    stored?.pending ?? null,
  );
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<string[]>([]);
  const [review, setReview] = useState(false);
  const [needsRefresh, setNeedsRefresh] = useState(false);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const query = useQuotationsEditable(id, { query: { retry: false } });
  const currencies = useCurrenciesIndex({ per_page: 250, sort: "code" });
  let fresh: Editable | null = null;
  let parseError: unknown = null;
  if (query.data?.status === 200) {
    try {
      fresh = readEditable(query.data.data.data);
      if (fresh.revision_id !== id) throw new Error("Unexpected revision.");
    } catch (failure) {
      fresh = null;
      parseError = failure;
    }
  }
  useEffect(() => {
    if (fresh && !baseline) {
      setBaseline(fresh);
      setValues(valuesFromEditable(fresh));
    }
  }, [fresh, baseline]);
  useEffect(() => {
    if (baseline && values) writeDraft(key, { baseline, values, pending });
  }, [baseline, values, pending, key]);
  const stale =
    needsRefresh ||
    !!(
      baseline &&
      fresh &&
      baseline.input_generation !== fresh.input_generation
    );
  function update(next: DraftValues) {
    setValues(next);
    setReview(false);
    setError(null);
    setServerErrors([]);
  }
  function validate(): string | null {
    if (!values) return "The draft has not loaded.";
    for (const line of [...values.lines, ...values.added]) {
      if (!line.description?.trim()) return "Each line needs a description.";
      if (
        !checkDecimal(line.offered_quantity, {
          spec: "stockLedger",
          sign: "positive",
          label: "Quantity",
        }).ok
      )
        return "Each quantity must be positive and have at most four decimal places.";
      if (
        line.unit_price != null &&
        !checkDecimal(line.unit_price, {
          spec: "money",
          sign: "nonNegative",
          label: "Unit price",
        }).ok
      )
        return "Enter a non-negative unit price with at most four decimal places, or leave it blank.";
      if (
        line.source_kind === "MANUAL" &&
        (!line.manual_measure?.trim() || line.manual_measure.length > 100)
      )
        return "Manual lines need a measure of 1–100 characters.";
      if (
        line.source_kind === "DIRECT_ITEM" &&
        (!line.item_id || !line.offered_uom_id)
      )
        return "Select an Item and its stock unit for each Item line.";
      if (
        line.sort_order !== undefined &&
        (!Number.isSafeInteger(line.sort_order) || line.sort_order < 0)
      )
        return "Line order must be a non-negative whole number.";
      if (line.scope_ref !== undefined && !line.scope_ref.trim())
        return "A scope reference cannot be blank.";
      if (
        (line.section_title?.length ?? 0) > 200 ||
        (line.scope_ref?.length ?? 0) > 80 ||
        (line.choice_group_ref?.length ?? 0) > 80
      )
        return "A section title or reference is too long.";
      if (
        line.membership === "CHOOSE_ONE"
          ? !line.choice_group_ref?.trim()
          : !!line.choice_group_ref || !!line.is_baseline
      )
        return "Choose-one lines need a group; other lines cannot have a group or baseline.";
    }
    return null;
  }
  function startReview() {
    if (!baseline || !values) return;
    const problem = validate();
    if (problem) {
      setError(problem);
      return;
    }
    try {
      if (!hasChanges(buildChanges(baseline, values))) {
        setError("There are no changes to save.");
        return;
      }
    } catch {
      setError("The line changes could not be prepared.");
      return;
    }
    setError(null);
    setReview(true);
  }
  async function submit(attempt: EditAttempt) {
    if (!baseline || !values || busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    setError(null);
    writeDraft(key, { baseline, values, pending: attempt });
    setPending(attempt);
    try {
      const result = await quotationsUpdate(id, attempt.payload, {
        "Idempotency-Key": attempt.key,
      });
      if (result.status !== 200 || result.data.data.revision_id !== id)
        throw new Error("Unexpected server response.");
      clearDraft(key);
      await queryClient.invalidateQueries({
        queryKey: [`/v1/quotations/${id}`],
      });
      navigate("/quotations", { replace: true });
    } catch (failure) {
      if (isTransientFailure(failure))
        setError(
          "The result is uncertain. Retry the same request before changing the draft.",
        );
      else {
        setPending(null);
        setServerErrors(
          isApiError(failure) && failure.status === 422
            ? Object.values(failure.body?.errors ?? {}).flat()
            : [],
        );
        if (
          ["QUOTATION_DRAFT_STALE", "QUOTATION_DRAFT_CHANGED"].includes(
            getApiReasonCode(failure) ?? "",
          )
        ) {
          setNeedsRefresh(true);
          void query.refetch();
        }
        if (isApiError(failure) && failure.status === 404) void query.refetch();
        writeDraft(key, { baseline, values, pending: null });
        setReview(false);
        const status = isApiError(failure) ? failure.status : 0;
        setError(
          status === 404
            ? "This quotation is unavailable."
            : status === 409
              ? "This request key was already used for different input. Review and submit again."
              : getApiReasonCode(failure) === "QUOTATION_DRAFT_STALE" ||
                  getApiReasonCode(failure) === "QUOTATION_DRAFT_CHANGED"
                ? "This draft or its evidence changed. Preserve your work and reload before editing again."
                : (getApiErrorMessage(failure) ??
                  "The draft could not be saved."),
        );
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }
  if (!baseline || !values)
    return (
      <ScreenTemplate kind="detail" className="space-y-4 p-4">
        <PageHeader title="Edit quotation" />
        {query.isError || parseError ? (
          <QuotationFailure
            error={query.error ?? parseError}
            retry={() => void query.refetch()}
          />
        ) : (
          <p role="status">Loading draft…</p>
        )}
      </ScreenTemplate>
    );
  const rows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  const changes = (() => {
    try {
      return buildChanges(baseline, values);
    } catch {
      return null;
    }
  })();
  return (
    <ScreenTemplate kind="form" className="erp-screen space-y-4 pb-24">
      <PageHeader
        title={`Edit quotation #${id}`}
        description="Changes apply to the saved draft. Review them before saving."
        actions={
          <Link className="ui-link-action" to="/quotations">
            Back to quotations
          </Link>
        }
      />
      {stored && (
        <p className="text-sm">Picked up your unfinished quotation.</p>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {serverErrors.length > 0 && (
        <ul role="alert" className="list-disc pl-5 text-sm text-destructive">
          {serverErrors.map((message, index) => (
            <li key={index}>{message}</li>
          ))}
        </ul>
      )}
      {pending ? (
        <DetailSection title="Resolve the previous request">
          <div className="col-span-full space-y-3">
            <p>
              The last save may have completed. Retry its exact saved input and
              key.
            </p>
            <Button
              type="button"
              disabled={busy}
              onClick={() => void submit(pending)}
            >
              Retry save
            </Button>
          </div>
        </DetailSection>
      ) : query.isPending ? (
        <p role="status">Loading current draft…</p>
      ) : query.isError || parseError ? (
        <QuotationFailure
          error={query.error ?? parseError}
          retry={() => void query.refetch()}
        />
      ) : stale ? (
        <DetailSection title="Draft changed">
          <div className="col-span-full space-y-3">
            <p>
              Your unfinished input is preserved. The saved draft changed since
              you started; start over only after recording any changes you need.
            </p>
            <ul className="list-disc pl-5">
              {values.lines.map((line) => (
                <li key={line.id}>
                  {line.description}: quantity {line.offered_quantity}; unit
                  price {line.unit_price ?? "not entered"}
                </li>
              ))}
              {values.added.map((line) => (
                <li key={line.client_id}>
                  New: {line.description || "Untitled"}; quantity{" "}
                  {line.offered_quantity}; unit price{" "}
                  {line.unit_price ?? "not entered"}
                </li>
              ))}
              {values.removed.map((lineId) => (
                <li key={lineId}>
                  Remove:{" "}
                  {
                    baseline.lines.find((line) => line.id === lineId)
                      ?.description
                  }
                </li>
              ))}
            </ul>
            <Button
              type="button"
              variant="outline"
              disabled={!fresh || query.isFetching}
              onClick={() => {
                if (!fresh || query.isFetching) return;
                clearDraft(key);
                setBaseline(fresh);
                setValues(valuesFromEditable(fresh));
                setError(null);
                setNeedsRefresh(false);
              }}
            >
              Start over from current draft
            </Button>
          </div>
        </DetailSection>
      ) : (
        <>
          <DetailSection title="Draft header">
            <div className="space-y-2">
              <Label htmlFor="edit-recipient">Recipient</Label>
              <PartyCombobox
                id="edit-recipient"
                label="Recipient"
                value={
                  values.header.recipient_party_id
                    ? String(values.header.recipient_party_id)
                    : ""
                }
                onValueChange={(v) =>
                  update({
                    ...values,
                    header: {
                      ...values.header,
                      recipient_party_id: v ? Number(v) : null,
                    },
                  })
                }
              />
              {values.header.recipient_party_id && (
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() =>
                    update({
                      ...values,
                      header: { ...values.header, recipient_party_id: null },
                    })
                  }
                >
                  Clear recipient
                </Button>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="edit-currency">Currency</Label>
              <Combobox
                id="edit-currency"
                value={String(values.header.currency_id)}
                onValueChange={(v) =>
                  update({
                    ...values,
                    header: { ...values.header, currency_id: Number(v) },
                  })
                }
                options={rows.map((row) => ({
                  value: String(row.id),
                  label: `${row.code} — ${row.name}`,
                }))}
                {...lookupState(currencies, "currencies")}
              />
            </div>
            {(
              [
                ["pricing_date", "Pricing date"],
                ["tax_estimate_date", "Tax estimate date"],
                ["valid_through_local_date", "Valid through"],
              ] as const
            ).map(([name, label]) => (
              <div key={name} className="space-y-2">
                <Label htmlFor={`edit-${name}`}>{label}</Label>
                <DateField
                  id={`edit-${name}`}
                  value={values.header[name] ?? ""}
                  onChange={(v) =>
                    update({
                      ...values,
                      header: { ...values.header, [name]: v || null },
                    })
                  }
                />
              </div>
            ))}
          </DetailSection>
          <DetailSection
            title="Lines"
            description="Amounts are calculated by the server after saving."
          >
            <div className="col-span-full space-y-4">
              {values.lines.map((line) => (
                <LineEditor
                  key={line.id}
                  line={line}
                  onChange={(next) =>
                    update({
                      ...values,
                      lines: values.lines.map((x) =>
                        x.id === line.id ? (next as EditableLine) : x,
                      ),
                    })
                  }
                  onRemove={() =>
                    update({
                      ...values,
                      lines: values.lines.filter((x) => x.id !== line.id),
                      removed: [...values.removed, line.id],
                    })
                  }
                />
              ))}
              {values.added.map((line) => (
                <LineEditor
                  key={line.client_id}
                  line={line}
                  onChange={(next) =>
                    update({
                      ...values,
                      added: values.added.map((x) =>
                        x.client_id === line.client_id ? (next as NewLine) : x,
                      ),
                    })
                  }
                  onRemove={() =>
                    update({
                      ...values,
                      added: values.added.filter(
                        (x) => x.client_id !== line.client_id,
                      ),
                    })
                  }
                />
              ))}
              {values.removed.map((lineId) => (
                <div key={lineId} className="flex flex-wrap items-center gap-3">
                  <span>
                    Line marked for removal:{" "}
                    {baseline.lines.find((x) => x.id === lineId)?.description}
                  </span>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                      const original = baseline.lines.find(
                        (x) => x.id === lineId,
                      );
                      if (original)
                        update({
                          ...values,
                          removed: values.removed.filter((x) => x !== lineId),
                          lines: [...values.lines, original],
                        });
                    }}
                  >
                    Undo removal
                  </Button>
                </div>
              ))}
              <div className="flex flex-wrap gap-3">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() =>
                    update({
                      ...values,
                      added: [
                        ...values.added,
                        {
                          client_id: newAttemptKey(),
                          source_kind: "MANUAL",
                          description: "",
                          manual_measure: "",
                          offered_quantity: "1",
                          unit_price: null,
                          membership: "BASE",
                          choice_group_ref: null,
                          is_baseline: false,
                        },
                      ],
                    })
                  }
                >
                  Add manual line
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() =>
                    update({
                      ...values,
                      added: [
                        ...values.added,
                        {
                          client_id: newAttemptKey(),
                          source_kind: "DIRECT_ITEM",
                          description: "",
                          offered_quantity: "1",
                          unit_price: null,
                          membership: "BASE",
                          choice_group_ref: null,
                          is_baseline: false,
                        },
                      ],
                    })
                  }
                >
                  Add Item line
                </Button>
              </div>
            </div>
          </DetailSection>
          {review && changes && (
            <DetailSection title="Review changes">
              <div className="col-span-full space-y-3">
                <p>
                  Header fields changed:{" "}
                  {Object.keys(changes)
                    .filter(
                      (k) =>
                        ![
                          "expected_generation",
                          "add_lines",
                          "update_lines",
                          "remove_lines",
                        ].includes(k),
                    )
                    .map(
                      (k) =>
                        (
                          ({
                            recipient_party_id: "recipient",
                            currency_id: "currency",
                            pricing_date: "pricing date",
                            tax_estimate_date: "tax estimate date",
                            valid_through_local_date: "valid through",
                          }) as Record<string, string>
                        )[k] ?? k,
                    )
                    .join(", ") || "none"}
                  .
                </p>
                <p>
                  Lines added: {changes.add_lines?.length ?? 0}; changed:{" "}
                  {changes.update_lines?.length ?? 0}; removed:{" "}
                  {changes.remove_lines?.length ?? 0}.
                </p>
                <ul className="list-disc pl-5">
                  {values.added.map((line) => (
                    <li key={line.client_id}>
                      Add {line.description}: quantity {line.offered_quantity},
                      unit price {line.unit_price ?? "not entered"}
                    </li>
                  ))}
                  {changes.update_lines?.map((line) => (
                    <li key={line.id}>
                      Change{" "}
                      {
                        values.lines.find((current) => current.id === line.id)
                          ?.description
                      }
                      :{" "}
                      {Object.keys(line)
                        .filter((key) => key !== "id")
                        .map((key) => key.replaceAll("_", " "))
                        .join(", ")}
                    </li>
                  ))}
                  {changes.remove_lines?.map((lineId) => (
                    <li key={lineId}>
                      Remove{" "}
                      {
                        baseline.lines.find((line) => line.id === lineId)
                          ?.description
                      }
                    </li>
                  ))}
                </ul>
                <Button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    void submit({
                      kind: "edit",
                      revision: id,
                      key: newAttemptKey(),
                      payload: changes,
                    })
                  }
                >
                  Save changes
                </Button>
              </div>
            </DetailSection>
          )}
          <Button type="button" variant="outline" onClick={startReview}>
            Review changes
          </Button>
        </>
      )}
    </ScreenTemplate>
  );
}

type Line = EditableLine | NewLine;
function LineEditor({
  line,
  onChange,
  onRemove,
}: {
  line: Line;
  onChange: (value: Line) => void;
  onRemove: () => void;
}) {
  const key = "id" in line ? String(line.id) : line.client_id;
  function set(patch: Partial<Line>) {
    onChange({ ...line, ...patch } as Line);
  }
  return (
    <fieldset className="space-y-3 rounded-lg border p-4">
      <legend className="px-1 font-medium">
        {line.source_kind === "MANUAL" ? "Manual line" : "Item line"}
      </legend>
      {line.source_kind === "DIRECT_ITEM" && (
        <div className="space-y-2">
          <Label htmlFor={`item-${key}`}>Item</Label>
          <ItemCombobox
            id={`item-${key}`}
            label="Item"
            activeOnly
            value={line.item_id ? String(line.item_id) : ""}
            onValueChange={(value, item) => {
              if (item)
                set({
                  item_id: Number(value),
                  offered_uom_id: item.stock_uom_id,
                });
            }}
          />
        </div>
      )}
      <div className="space-y-2">
        <Label htmlFor={`description-${key}`}>Description</Label>
        <Input
          id={`description-${key}`}
          value={line.description ?? ""}
          onChange={(e) => set({ description: e.target.value })}
        />
      </div>
      {line.source_kind === "MANUAL" && (
        <div className="space-y-2">
          <Label htmlFor={`measure-${key}`}>Measure</Label>
          <Input
            id={`measure-${key}`}
            value={line.manual_measure ?? ""}
            maxLength={100}
            onChange={(e) => set({ manual_measure: e.target.value })}
          />
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor={`quantity-${key}`}>Quantity</Label>
          <DecimalInput
            id={`quantity-${key}`}
            value={line.offered_quantity}
            rule={{ spec: "stockLedger", sign: "positive" }}
            onChange={(value) => set({ offered_quantity: value })}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor={`price-${key}`}>Unit price (optional)</Label>
          <DecimalInput
            id={`price-${key}`}
            value={line.unit_price ?? ""}
            rule={{ spec: "money", sign: "nonNegative" }}
            onChange={(value) => set({ unit_price: value || null })}
          />
        </div>
      </div>
      <div className="space-y-2">
        <Label htmlFor={`section-${key}`}>Section title (optional)</Label>
        <Input
          id={`section-${key}`}
          maxLength={200}
          value={line.section_title ?? ""}
          onChange={(e) => set({ section_title: e.target.value || null })}
        />
      </div>
      {"client_id" in line && (
        <div className="space-y-2">
          <Label htmlFor={`scope-${key}`}>Scope reference (optional)</Label>
          <Input
            id={`scope-${key}`}
            maxLength={80}
            value={line.scope_ref ?? ""}
            onChange={(e) => set({ scope_ref: e.target.value || undefined })}
          />
        </div>
      )}
      <div className="space-y-2">
        <Label htmlFor={`order-${key}`}>Sort order (optional)</Label>
        <Input
          id={`order-${key}`}
          type="number"
          min="0"
          step="1"
          value={Number.isNaN(line.sort_order) ? "" : (line.sort_order ?? "")}
          onChange={(e) =>
            set({
              sort_order:
                e.target.value === ""
                  ? "id" in line
                    ? Number.NaN
                    : undefined
                  : Number(e.target.value),
            })
          }
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor={`membership-${key}`}>Offer membership</Label>
        <select
          id={`membership-${key}`}
          className="h-10 w-full rounded-md border bg-background px-3"
          value={line.membership ?? "BASE"}
          onChange={(e) => {
            const membership = e.target.value as
              "BASE" | "OPTIONAL" | "CHOOSE_ONE";
            set({
              membership,
              ...(membership === "CHOOSE_ONE"
                ? {}
                : { choice_group_ref: null, is_baseline: false }),
            });
          }}
        >
          <option value="BASE">Base</option>
          <option value="OPTIONAL">Optional</option>
          <option value="CHOOSE_ONE">Choose one</option>
        </select>
      </div>
      {line.membership === "CHOOSE_ONE" && (
        <>
          <div className="space-y-2">
            <Label htmlFor={`group-${key}`}>Choice group reference</Label>
            <Input
              id={`group-${key}`}
              maxLength={80}
              value={line.choice_group_ref ?? ""}
              onChange={(e) => set({ choice_group_ref: e.target.value })}
            />
          </div>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={line.is_baseline ?? false}
              onChange={(e) => set({ is_baseline: e.target.checked })}
            />{" "}
            Baseline choice
          </label>
        </>
      )}
      <Button type="button" variant="outline" onClick={onRemove}>
        Remove line
      </Button>
    </fieldset>
  );
}
