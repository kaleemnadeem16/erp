import { useId, useState } from "react";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Textarea } from "../../components/ui/textarea";
import { RecordSheet } from "../../components/page/record-sheet";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { getApiReasonCode, isApiError } from "../../lib/api-errors";
import {
  CustomerCombobox,
  ItemCombobox,
  UomCombobox,
} from "../../components/lookups/entity-comboboxes";
import {
  AUTHORITY_REFUSALS,
  ENFORCEMENT_LABEL,
  OPERATION_LABEL,
  PURPOSE_LABEL,
  SOURCE_KIND_LABEL,
  isDemoOnly,
  type AssignmentInput,
  type Currency,
  type DiscountBand,
  type DiscountPolicy,
  type Person,
  type PolicyInput,
  type PriceReference,
  type ReferenceInput,
} from "./discount-authority-contract";
import {
  CEILINGS,
  CEILING_LABEL,
  emptyAssignmentForm,
  emptyBand,
  emptyPolicyForm,
  emptyReferenceForm,
  validateAssignmentForm,
  validatePolicyForm,
  validateReferenceForm,
  type AssignmentForm,
  type FormErrors,
  type PolicyForm,
  type ReferenceForm,
} from "./discount-authority-form-model";

/** The reason shown for a refused configuration write; only the stable code decides the text. */
export function authorityRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && AUTHORITY_REFUSALS[code]) return AUTHORITY_REFUSALS[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to change discount authority.";
  return "That could not be saved. Check the details and try again.";
}

const selectClass =
  "min-h-11 w-full rounded-md border bg-background px-3 text-sm";

function Field({
  label,
  error,
  hint,
  id,
  children,
}: {
  label: string;
  error?: string;
  hint?: string;
  id: string;
  children: React.ReactNode;
}) {
  return (
    <div className="min-w-0 space-y-1">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="text-xs text-muted-foreground">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

function FormActions({
  pending,
  error,
  onCancel,
  submitLabel,
}: {
  pending: boolean;
  error: unknown;
  onCancel: () => void;
  submitLabel: string;
}) {
  return (
    <div className="sticky bottom-0 -mx-4 space-y-2 border-t bg-background p-4">
      {error !== null && error !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {authorityRefusalMessage(error)}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-3">
        <Button
          className="min-h-11"
          type="button"
          variant="outline"
          onClick={onCancel}
          disabled={pending}
        >
          Cancel
        </Button>
        <Button className="min-h-11" type="submit" disabled={pending}>
          {pending ? "Saving…" : submitLabel}
        </Button>
      </div>
    </div>
  );
}

/** A new, immutable policy version with all its authority levels. Nothing is applied until it is activated. */
export function PolicyVersionSheet({
  today,
  pending,
  error,
  onSubmit,
  onClose,
}: {
  today: string;
  pending: boolean;
  error: unknown;
  onSubmit: (input: PolicyInput, reason: string) => void;
  onClose: () => void;
}) {
  const uid = useId();
  const [form, setForm] = useState<PolicyForm>(() => emptyPolicyForm(today));
  const [errors, setErrors] = useState<FormErrors>({});
  const set = <K extends keyof PolicyForm>(key: K, value: PolicyForm[K]) =>
    setForm((f) => ({ ...f, [key]: value }));
  const showLine = form.enforcement !== "DOCUMENT";
  const showDoc = form.enforcement !== "LINE";
  const visible = CEILINGS.filter(
    (c) =>
      (c.startsWith("line") && showLine) ||
      (c.startsWith("document") && showDoc) ||
      c.startsWith("input"),
  );
  return (
    <RecordSheet
      title="New policy version"
      description="A version can’t be edited after it is saved. It only takes effect once activated, and only while discount authority is switched on."
      size="lg"
      dismissOnOutsideClick={false}
      onClose={onClose}
    >
      <form
        noValidate
        className="space-y-4 p-4"
        onSubmit={(event) => {
          event.preventDefault();
          const result = validatePolicyForm(form);
          if ("errors" in result) {
            setErrors(result.errors);
            return;
          }
          setErrors({});
          onSubmit(result.input, result.reason);
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={`${uid}-code`} label="Policy code" error={errors.code} hint="For example sales-standard. Reuse a code to add a new version of the same policy.">
            <Input id={`${uid}-code`} value={form.code} maxLength={80} aria-invalid={!!errors.code} aria-describedby={errors.code ? `${uid}-code-error` : `${uid}-code-hint`} onChange={(e) => set("code", e.target.value)} />
          </Field>
          <Field id={`${uid}-priority`} label="Priority" error={errors.priority} hint="Where two policies cover the same quotation, the higher number is used.">
            <Input id={`${uid}-priority`} inputMode="numeric" value={form.priority} aria-invalid={!!errors.priority} onChange={(e) => set("priority", e.target.value)} />
          </Field>
          <Field id={`${uid}-operation`} label="Applies to">
            <select id={`${uid}-operation`} className={selectClass} value={form.operation} onChange={(e) => set("operation", e.target.value as PolicyForm["operation"])}>
              {Object.entries(OPERATION_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </Field>
          <Field id={`${uid}-enforcement`} label="Limits checked on">
            <select id={`${uid}-enforcement`} className={selectClass} value={form.enforcement} onChange={(e) => set("enforcement", e.target.value as PolicyForm["enforcement"])}>
              {Object.entries(ENFORCEMENT_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </Field>
          <Field id={`${uid}-from`} label="Effective from" error={errors.effective_from}>
            <DateField id={`${uid}-from`} value={form.effective_from} aria-invalid={!!errors.effective_from} onChange={(v) => set("effective_from", v)} />
          </Field>
          <Field id={`${uid}-to`} label="Effective to (optional)" error={errors.effective_to}>
            <DateField id={`${uid}-to`} value={form.effective_to} aria-invalid={!!errors.effective_to} onChange={(v) => set("effective_to", v)} />
          </Field>
          <Field id={`${uid}-days`} label="Approval valid for (calendar days)" error={errors.expiry_calendar_days}>
            <Input id={`${uid}-days`} inputMode="numeric" value={form.expiry_calendar_days} aria-invalid={!!errors.expiry_calendar_days} onChange={(e) => set("expiry_calendar_days", e.target.value)} />
          </Field>
          <div className="flex min-h-11 items-center gap-2 self-end">
            <Checkbox id={`${uid}-higher`} checked={form.allow_higher_approver} onCheckedChange={(v) => set("allow_higher_approver", v === true)} />
            <Label htmlFor={`${uid}-higher`}>A higher level may also approve</Label>
          </div>
        </div>

        <fieldset className="space-y-3">
          <legend className="text-sm font-semibold">Authority levels</legend>
          {errors.bands && <p role="alert" className="text-sm text-destructive">{errors.bands}</p>}
          {form.bands.map((band, index) => {
            const at = (f: string) => errors[`bands.${index}.${f}`];
            const update = (patch: Partial<typeof band>) =>
              set("bands", form.bands.map((b) => (b.key === band.key ? { ...b, ...patch } : b)));
            return (
              <div key={band.key} className="space-y-3 rounded-lg border border-border p-3" role="group" aria-label={`Authority level ${index + 1}`}>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field id={`${uid}-b${band.key}-label`} label="Level name" error={at("label")}>
                    <Input id={`${uid}-b${band.key}-label`} value={band.label} maxLength={100} aria-invalid={!!at("label")} onChange={(e) => update({ label: e.target.value })} />
                  </Field>
                  <Field id={`${uid}-b${band.key}-rank`} label="Rank" error={at("rank")} hint="Higher rank, more authority.">
                    <Input id={`${uid}-b${band.key}-rank`} inputMode="numeric" value={band.rank} aria-invalid={!!at("rank")} onChange={(e) => update({ rank: e.target.value })} />
                  </Field>
                </div>
                <div className="flex min-h-11 items-center gap-2">
                  <Checkbox id={`${uid}-b${band.key}-exc`} checked={band.exception_only} onCheckedChange={(v) => update({ exception_only: v === true })} />
                  <Label htmlFor={`${uid}-b${band.key}-exc`}>Exception approver only (no routine limit)</Label>
                </div>
                {!band.exception_only && (
                  <div className="grid gap-3 sm:grid-cols-2">
                    {visible.map((c) => (
                      <Field key={c} id={`${uid}-b${band.key}-${c}`} label={CEILING_LABEL[c]} error={at(c)}>
                        <Input id={`${uid}-b${band.key}-${c}`} inputMode="decimal" value={band.limits[c]} aria-invalid={!!at(c)} onChange={(e) => update({ limits: { ...band.limits, [c]: e.target.value } })} />
                      </Field>
                    ))}
                    {at("limits") && <p role="alert" className="col-span-full text-sm text-destructive">{at("limits")}</p>}
                  </div>
                )}
                {form.bands.length > 1 && (
                  <Button className="min-h-11" type="button" variant="outline" onClick={() => set("bands", form.bands.filter((b) => b.key !== band.key))}>
                    Remove level {index + 1}
                  </Button>
                )}
              </div>
            );
          })}
          <Button className="min-h-11" type="button" variant="outline" disabled={form.bands.length >= 100} onClick={() => set("bands", [...form.bands, emptyBand()])}>
            Add authority level
          </Button>
        </fieldset>

        <Field id={`${uid}-reason`} label="Reason" error={errors.reason}>
          <Textarea id={`${uid}-reason`} value={form.reason} maxLength={1000} aria-required aria-invalid={!!errors.reason} onChange={(e) => set("reason", e.target.value)} />
        </Field>
        <FormActions pending={pending} error={error} onCancel={onClose} submitLabel="Save policy version" />
      </form>
    </RecordSheet>
  );
}

/** Name one person's authority level, purpose and dates. Holds no permission; it only says how much they may clear or approve. */
export function AssignmentSheet({
  today,
  bands,
  policies,
  people,
  roles = null,
  units = null,
  pending,
  error,
  onSubmit,
  onClose,
}: {
  today: string;
  bands: DiscountBand[];
  policies: DiscountPolicy[];
  /** null while unavailable: the form then asks for a reference number. */
  people: Person[] | null;
  /** Named roles and organisation units; null when the lookup is not available to this person, then a reference number is asked for. */
  roles?: Person[] | null;
  units?: Person[] | null;
  pending: boolean;
  error: unknown;
  onSubmit: (input: AssignmentInput, reason: string) => void;
  onClose: () => void;
}) {
  const uid = useId();
  const [form, setForm] = useState<AssignmentForm>(() => emptyAssignmentForm(today));
  const [errors, setErrors] = useState<FormErrors>({});
  const set = <K extends keyof AssignmentForm>(key: K, value: AssignmentForm[K]) =>
    setForm((f) => ({ ...f, [key]: value }));
  const policyOf = (band: DiscountBand) => policies.find((p) => p.id === band.policy_id);
  return (
    <RecordSheet
      title="Assign authority"
      description="Choose how much discount one person may clear, or approve for others. This does not give them any permission."
      size="md"
      dismissOnOutsideClick={false}
      onClose={onClose}
    >
      <form
        noValidate
        className="space-y-4 p-4"
        onSubmit={(event) => {
          event.preventDefault();
          const result = validateAssignmentForm(form);
          if ("errors" in result) {
            setErrors(result.errors);
            return;
          }
          setErrors({});
          onSubmit(result.input, result.reason);
        }}
      >
        <Field id={`${uid}-user`} label="Person" error={errors.user_id} hint={people === null ? "The list of people is not available to you. Enter the person’s reference number." : undefined}>
          {people === null ? (
            <Input id={`${uid}-user`} inputMode="numeric" value={form.user_id} aria-invalid={!!errors.user_id} onChange={(e) => set("user_id", e.target.value)} />
          ) : (
            <select id={`${uid}-user`} className={selectClass} value={form.user_id} aria-invalid={!!errors.user_id} onChange={(e) => set("user_id", e.target.value)}>
              <option value="">Choose a person</option>
              {people.map((p) => <option key={p.id} value={p.id}>{p.name} · #{p.id}</option>)}
            </select>
          )}
        </Field>
        <Field id={`${uid}-band`} label="Authority level" error={errors.band_id} hint={bands.length === 0 ? "No authority levels exist yet. Save a policy version first." : undefined}>
          <select id={`${uid}-band`} className={selectClass} value={form.band_id} aria-invalid={!!errors.band_id} onChange={(e) => set("band_id", e.target.value)}>
            <option value="">Choose a level</option>
            {bands.map((b) => {
              const policy = policyOf(b);
              return <option key={b.id} value={b.id}>{b.label} · rank {b.rank}{policy ? ` · ${policy.code} v${policy.version_no}` : ""}</option>;
            })}
          </select>
        </Field>
        <Field id={`${uid}-purpose`} label="Purpose">
          <select id={`${uid}-purpose`} className={selectClass} value={form.purpose} onChange={(e) => set("purpose", e.target.value as AssignmentForm["purpose"])}>
            {Object.entries(PURPOSE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={`${uid}-from`} label="Effective from" error={errors.effective_from}>
            <DateField id={`${uid}-from`} value={form.effective_from} aria-invalid={!!errors.effective_from} onChange={(v) => set("effective_from", v)} />
          </Field>
          <Field id={`${uid}-to`} label="Effective to (optional)" error={errors.effective_to}>
            <DateField id={`${uid}-to`} value={form.effective_to} aria-invalid={!!errors.effective_to} onChange={(v) => set("effective_to", v)} />
          </Field>
          <Field id={`${uid}-role`} label="Only in role (optional)" error={errors.role_id} hint={roles === null ? "The list of roles is not available to you. Enter the role’s reference number." : undefined}>
            {roles === null ? (
              <Input id={`${uid}-role`} inputMode="numeric" value={form.role_id} aria-invalid={!!errors.role_id} onChange={(e) => set("role_id", e.target.value)} />
            ) : (
              <select id={`${uid}-role`} className={selectClass} value={form.role_id} aria-invalid={!!errors.role_id} onChange={(e) => set("role_id", e.target.value)}>
                <option value="">Any role</option>
                {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            )}
          </Field>
          <Field id={`${uid}-unit`} label="Only in unit (optional)" error={errors.unit_id} hint={units === null ? "The list of units is not available to you. Enter the unit’s reference number." : undefined}>
            {units === null ? (
              <Input id={`${uid}-unit`} inputMode="numeric" value={form.unit_id} aria-invalid={!!errors.unit_id} onChange={(e) => set("unit_id", e.target.value)} />
            ) : (
              <select id={`${uid}-unit`} className={selectClass} value={form.unit_id} aria-invalid={!!errors.unit_id} onChange={(e) => set("unit_id", e.target.value)}>
                <option value="">Any unit</option>
                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
            )}
          </Field>
        </div>
        <Field id={`${uid}-reason`} label="Reason" error={errors.reason}>
          <Textarea id={`${uid}-reason`} value={form.reason} maxLength={1000} aria-required aria-invalid={!!errors.reason} onChange={(e) => set("reason", e.target.value)} />
        </Field>
        <FormActions pending={pending} error={error} onCancel={onClose} submitLabel="Assign authority" />
      </form>
    </RecordSheet>
  );
}

export type ReasonAction =
  | { kind: "activate"; policy: DiscountPolicy }
  | { kind: "revoke"; assignmentId: number; who: string }
  | { kind: "approve"; reference: PriceReference };

/** Confirm activating a version or revoking an assignment, with the reason the server records. */
export function ReasonDialog({
  action,
  pending,
  error,
  onConfirm,
  onClose,
}: {
  action: ReasonAction | null;
  pending: boolean;
  error: unknown;
  onConfirm: (reason: string) => void;
  onClose: () => void;
}) {
  const [reason, setReason] = useState("");
  const trimmed = reason.trim();
  const copy =
    action === null
      ? null
      : action.kind === "approve"
        ? {
            title: `Approve ${action.reference.code} version ${action.reference.version_no}?`,
            body: `This makes the price ${action.reference.unit_price} the trusted reference for the quotations it covers${isDemoOnly(action.reference.reason) ? " (demonstration data)" : ""}. It must be approved by someone other than the person who proposed it, it can’t be edited, and it has no effect while discount authority is switched off.`,
            confirm: "Approve reference",
          }
        : action.kind === "activate"
        ? {
            title: `Activate ${action.policy.code} version ${action.policy.version_no}?`,
            body: `This version becomes the one used for quotations it covers${isDemoOnly(action.policy.reason) ? " (demonstration data)" : ""}. It has no effect while discount authority is switched off, and it can’t be edited later.`,
            confirm: "Activate version",
          }
        : {
            title: "Revoke this assignment?",
            body: `${action.who} will no longer have this authority. The assignment stays on record.`,
            confirm: "Revoke assignment",
          };
  return (
    <Dialog
      open={action !== null}
      onOpenChange={(open) => {
        if (!open && !pending) {
          setReason("");
          onClose();
        }
      }}
    >
      <DialogContent>
        {copy && (
          <>
            <DialogHeader>
              <DialogTitle>{copy.title}</DialogTitle>
              <DialogDescription>{copy.body}</DialogDescription>
            </DialogHeader>
            <div className="space-y-2">
              <Label htmlFor="authority-reason">Reason</Label>
              <Textarea id="authority-reason" value={reason} maxLength={1000} aria-required onChange={(e) => setReason(e.target.value)} />
            </div>
            {error !== null && error !== undefined && (
              <p role="alert" className="text-sm text-destructive">{authorityRefusalMessage(error)}</p>
            )}
            <DialogFooter>
              <Button className="min-h-11" type="button" variant="outline" disabled={pending} onClick={() => { setReason(""); onClose(); }}>Cancel</Button>
              <Button className="min-h-11" type="button" variant={action?.kind === "revoke" ? "destructive" : "default"} disabled={pending || trimmed === ""} onClick={() => onConfirm(trimmed)}>
                {pending ? "Saving…" : copy.confirm}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

/** Propose a governed price reference. It stays unusable until someone other than the proposer approves it. */
export function ReferenceSheet({
  today,
  currencies,
  pending,
  error,
  onSubmit,
  onClose,
}: {
  today: string;
  /** null while unavailable: the form then asks for a reference number. */
  currencies: Currency[] | null;
  pending: boolean;
  error: unknown;
  onSubmit: (input: ReferenceInput, reason: string) => void;
  onClose: () => void;
}) {
  const uid = useId();
  const [form, setForm] = useState<ReferenceForm>(() => emptyReferenceForm(today));
  const [errors, setErrors] = useState<FormErrors>({});
  const set = <K extends keyof ReferenceForm>(key: K, value: ReferenceForm[K]) =>
    setForm((f) => ({ ...f, [key]: value }));
  const manual = form.basis === "manual";
  return (
    <RecordSheet
      title="Propose a price reference"
      description="The trusted price a discount is measured against. It can’t be edited after it is saved, and it only counts once someone other than you approves it."
      size="lg"
      dismissOnOutsideClick={false}
      onClose={onClose}
    >
      <form
        noValidate
        className="space-y-4 p-4"
        onSubmit={(event) => {
          event.preventDefault();
          const result = validateReferenceForm(form);
          if ("errors" in result) {
            setErrors(result.errors);
            return;
          }
          setErrors({});
          onSubmit(result.input, result.reason);
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={`${uid}-code`} label="Reference code" error={errors.code} hint="Reuse a code to add a new version of the same reference.">
            <Input id={`${uid}-code`} value={form.code} maxLength={80} aria-invalid={!!errors.code} onChange={(e) => set("code", e.target.value)} />
          </Field>
          <Field id={`${uid}-kind`} label="Source" error={errors.source_kind}>
            <select id={`${uid}-kind`} className={selectClass} value={form.source_kind} aria-invalid={!!errors.source_kind} onChange={(e) => set("source_kind", e.target.value as ReferenceForm["source_kind"])}>
              {Object.entries(SOURCE_KIND_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </Field>
          <Field id={`${uid}-key`} label="Source document" error={errors.source_key} hint="For example the price list or agreement number.">
            <Input id={`${uid}-key`} value={form.source_key} maxLength={160} aria-invalid={!!errors.source_key} onChange={(e) => set("source_key", e.target.value)} />
          </Field>
          <Field id={`${uid}-version`} label="Source version" error={errors.source_version}>
            <Input id={`${uid}-version`} value={form.source_version} maxLength={80} aria-invalid={!!errors.source_version} onChange={(e) => set("source_version", e.target.value)} />
          </Field>
          <Field id={`${uid}-basis`} label="Priced by">
            <select id={`${uid}-basis`} className={selectClass} value={form.basis} onChange={(e) => set("basis", e.target.value as ReferenceForm["basis"])}>
              <option value="item">An item</option>
              <option value="manual">A manual measure (project baseline)</option>
            </select>
          </Field>
          {manual ? (
            <Field id={`${uid}-measure`} label="Measure" error={errors.manual_measure} hint="For example per square metre.">
              <Input id={`${uid}-measure`} value={form.manual_measure} maxLength={80} aria-invalid={!!errors.manual_measure} onChange={(e) => set("manual_measure", e.target.value)} />
            </Field>
          ) : (
            <Field id={`${uid}-item`} label="Item" error={errors.item_id}>
              <ItemCombobox id={`${uid}-item`} label="Item" value={form.item_id} aria-invalid={!!errors.item_id} onValueChange={(v) => set("item_id", v)} />
            </Field>
          )}
          <Field id={`${uid}-uom`} label="Unit (optional)" error={errors.uom_id}>
            <UomCombobox id={`${uid}-uom`} label="Unit" value={form.uom_id || "all"} allLabel="Any unit" onValueChange={(v) => set("uom_id", v === "all" ? "" : v)} />
          </Field>
          <Field id={`${uid}-customer`} label={form.source_kind === "CUSTOMER_AGREEMENT" ? "Customer" : "Customer (optional)"} error={errors.customer_id}>
            <CustomerCombobox id={`${uid}-customer`} label="Customer" value={form.customer_id || (form.source_kind === "CUSTOMER_AGREEMENT" ? "" : "all")} allLabel={form.source_kind === "CUSTOMER_AGREEMENT" ? undefined : "No customer"} onValueChange={(v) => set("customer_id", v === "all" ? "" : v)} />
          </Field>
          <Field id={`${uid}-family`} label={manual ? "Quotation family" : "Quotation family (optional)"} error={errors.family_id} hint="Quotation family reference number; limits this reference to one quotation family.">
            <Input id={`${uid}-family`} inputMode="numeric" value={form.family_id} aria-invalid={!!errors.family_id} onChange={(e) => set("family_id", e.target.value)} />
          </Field>
          <Field id={`${uid}-currency`} label="Currency" error={errors.currency_id} hint={currencies === null ? "The list of currencies is not available to you. Enter the currency’s reference number." : undefined}>
            {currencies === null ? (
              <Input id={`${uid}-currency`} inputMode="numeric" value={form.currency_id} aria-invalid={!!errors.currency_id} onChange={(e) => set("currency_id", e.target.value)} />
            ) : (
              <select id={`${uid}-currency`} className={selectClass} value={form.currency_id} aria-invalid={!!errors.currency_id} onChange={(e) => set("currency_id", e.target.value)}>
                <option value="">Choose a currency</option>
                {currencies.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
              </select>
            )}
          </Field>
          <Field id={`${uid}-price`} label="Unit price" error={errors.unit_price}>
            <Input id={`${uid}-price`} inputMode="decimal" value={form.unit_price} aria-invalid={!!errors.unit_price} onChange={(e) => set("unit_price", e.target.value)} />
          </Field>
          <Field id={`${uid}-priority`} label="Priority" error={errors.priority} hint="Where two references cover the same line, the higher number is used.">
            <Input id={`${uid}-priority`} inputMode="numeric" value={form.priority} aria-invalid={!!errors.priority} onChange={(e) => set("priority", e.target.value)} />
          </Field>
          <Field id={`${uid}-from`} label="Effective from" error={errors.effective_from}>
            <DateField id={`${uid}-from`} value={form.effective_from} aria-invalid={!!errors.effective_from} onChange={(v) => set("effective_from", v)} />
          </Field>
          <Field id={`${uid}-to`} label="Effective to (optional)" error={errors.effective_to}>
            <DateField id={`${uid}-to`} value={form.effective_to} aria-invalid={!!errors.effective_to} onChange={(v) => set("effective_to", v)} />
          </Field>
        </div>
        <Field id={`${uid}-reason`} label="Reason" error={errors.reason}>
          <Textarea id={`${uid}-reason`} value={form.reason} maxLength={1000} aria-required aria-invalid={!!errors.reason} onChange={(e) => set("reason", e.target.value)} />
        </Field>
        <FormActions pending={pending} error={error} onCancel={onClose} submitLabel="Save reference" />
      </form>
    </RecordSheet>
  );
}
