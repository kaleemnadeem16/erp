import { useState, type FormEvent } from "react";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Textarea } from "../../components/ui/textarea";
import { PartyCombobox } from "../../components/lookups/entity-comboboxes";
import {
  AUTHORITY_BASES,
  AUTHORITY_LABELS,
  PRECISIONS,
  PRECISION_LABELS,
  SOURCE_KINDS,
  SOURCE_LABELS,
  refusalMessage,
  type AuthorityBasis,
  type ObservationInput,
  type OpenCaseInput,
  type Observation,
  type Precision,
  type SourceKind,
} from "./customer-decision-contract";

/** What every record form says first: an observation is a note of what arrived, not an agreement. */
export function NoConsentNotice() {
  return (
    <p
      className="rounded-md border border-border bg-muted/40 p-3 text-sm"
      data-testid="no-consent-notice"
    >
      This records what the customer sent. It does not accept the quotation,
      hold the offer or reserve anything: the offer can still expire or be
      withdrawn.
    </p>
  );
}

function FormError({
  problem,
  error,
}: {
  problem: string | null;
  error?: unknown;
}) {
  const shown = problem ?? (error ? refusalMessage(error) : null);
  return shown ? (
    <p role="alert" className="text-sm text-destructive sm:col-span-2">
      {shown}
    </p>
  ) : null;
}

/** Opens a case for one customer: who it is about and the reference the customer used. */
export function OpenCaseForm({
  onSubmit,
  onCancel,
  pending,
  error,
}: {
  onSubmit: (input: OpenCaseInput) => void;
  onCancel: () => void;
  pending: boolean;
  error?: unknown;
}) {
  const [party, setParty] = useState("");
  const [identity, setIdentity] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    if (!party || !identity.trim()) {
      setProblem("Choose the customer and enter the reference they used.");
      return;
    }
    setProblem(null);
    onSubmit({ party_id: Number(party), intake_identity: identity.trim() });
  }
  return (
    <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit} noValidate>
      <div className="sm:col-span-2">
        <NoConsentNotice />
      </div>
      <div className="space-y-2 sm:col-span-2">
        <Label htmlFor="decision-party">Customer</Label>
        <PartyCombobox
          id="decision-party"
          label="Customer"
          value={party}
          onValueChange={(value) => setParty(value)}
        />
      </div>
      <div className="space-y-2 sm:col-span-2">
        <Label htmlFor="decision-identity">Customer’s reference</Label>
        <Input
          className="min-h-11"
          id="decision-identity"
          maxLength={120}
          value={identity}
          onChange={(e) => setIdentity(e.target.value)}
          aria-describedby="decision-identity-hint"
          required
        />
        <p
          id="decision-identity-hint"
          className="text-xs text-muted-foreground"
        >
          For example their purchase order number. A reference that already
          exists is never merged: open that case instead.
        </p>
      </div>
      <FormError problem={problem} error={error} />
      <div className="flex flex-wrap gap-2 sm:col-span-2">
        <Button className="min-h-11" type="submit" disabled={pending}>
          {pending ? "Saving…" : "Open case"}
        </Button>
        <Button
          className="min-h-11"
          type="button"
          variant="outline"
          onClick={onCancel}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}

const PRECISION_HINT: Record<Precision, string> = {
  UNKNOWN: "Leave the time empty: unknown stays unknown.",
  DATE: "Times are read in the company’s time zone.",
  INSTANT: "Times are read in the company’s time zone.",
  INTERVAL: "Times are read in the company’s time zone.",
};

/** Adds one observation to a case. Unknown stays unknown: a time is asked for only when the precision says one is known. */
export function ObservationForm({
  onSubmit,
  onCancel,
  pending,
  error,
  correctable,
}: {
  onSubmit: (input: ObservationInput) => void;
  onCancel: () => void;
  pending: boolean;
  error?: unknown;
  /** The current observations that may be corrected by this one. */
  correctable: Observation[];
}) {
  const [kind, setKind] = useState<SourceKind>("PURCHASE_ORDER");
  const [basis, setBasis] = useState<AuthorityBasis>("UNVERIFIED");
  const [precision, setPrecision] = useState<Precision>("UNKNOWN");
  const [reference, setReference] = useState("");
  const [representative, setRepresentative] = useState("");
  const [from, setFrom] = useState("");
  const [until, setUntil] = useState("");
  const [description, setDescription] = useState("");
  const [corrects, setCorrects] = useState("none");
  const [problem, setProblem] = useState<string | null>(null);
  const inputType = precision === "DATE" ? "date" : "datetime-local";
  function submit(event: FormEvent) {
    event.preventDefault();
    if (!description.trim()) {
      setProblem("Describe what the customer sent, in plain facts.");
      return;
    }
    if (precision !== "UNKNOWN" && !from) {
      setProblem("Enter when it happened, or choose “Not known”.");
      return;
    }
    if (precision === "INTERVAL" && (!until || until < from)) {
      setProblem("Enter an end that is not before the start.");
      return;
    }
    setProblem(null);
    onSubmit({
      source_kind: kind,
      authority_verification_basis: basis,
      occurrence_precision: precision,
      factual_description: description.trim(),
      external_reference: reference.trim() || null,
      customer_representative_snapshot: representative.trim() || null,
      occurred_at: precision === "UNKNOWN" ? null : from,
      occurred_until: precision === "INTERVAL" ? until : null,
      predecessor_id: corrects === "none" ? null : Number(corrects),
    });
  }
  return (
    <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit} noValidate>
      <div className="sm:col-span-2">
        <NoConsentNotice />
      </div>
      <div className="space-y-2">
        <Label htmlFor="observation-kind">What arrived</Label>
        <Select value={kind} onValueChange={(v) => setKind(v as SourceKind)}>
          <SelectTrigger id="observation-kind" className="min-h-11">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SOURCE_KINDS.map((k) => (
              <SelectItem key={k} value={k}>
                {SOURCE_LABELS[k]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="space-y-2">
        <Label htmlFor="observation-basis">
          How the sender’s authority was checked
        </Label>
        <Select
          value={basis}
          onValueChange={(v) => setBasis(v as AuthorityBasis)}
        >
          <SelectTrigger id="observation-basis" className="min-h-11">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {AUTHORITY_BASES.map((b) => (
              <SelectItem key={b} value={b}>
                {AUTHORITY_LABELS[b]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="space-y-2">
        <Label htmlFor="observation-reference">
          Their reference (optional)
        </Label>
        <Input
          className="min-h-11"
          id="observation-reference"
          maxLength={200}
          value={reference}
          onChange={(e) => setReference(e.target.value)}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="observation-representative">
          Who sent it (optional)
        </Label>
        <Input
          className="min-h-11"
          id="observation-representative"
          maxLength={500}
          value={representative}
          onChange={(e) => setRepresentative(e.target.value)}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="observation-precision">When it happened</Label>
        <Select
          value={precision}
          onValueChange={(v) => setPrecision(v as Precision)}
        >
          <SelectTrigger id="observation-precision" className="min-h-11">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PRECISIONS.map((p) => (
              <SelectItem key={p} value={p}>
                {PRECISION_LABELS[p]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">
          {PRECISION_HINT[precision]}
        </p>
      </div>
      {precision !== "UNKNOWN" && (
        <div className="space-y-2">
          <Label htmlFor="observation-from">
            {precision === "INTERVAL" ? "From" : "On"}
          </Label>
          <Input
            className="min-h-11"
            id="observation-from"
            type={inputType}
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            required
          />
        </div>
      )}
      {precision === "INTERVAL" && (
        <div className="space-y-2">
          <Label htmlFor="observation-until">To</Label>
          <Input
            className="min-h-11"
            id="observation-until"
            type="datetime-local"
            value={until}
            onChange={(e) => setUntil(e.target.value)}
            required
          />
        </div>
      )}
      {correctable.length > 0 && (
        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="observation-corrects">
            Corrects an earlier observation (optional)
          </Label>
          <Select value={corrects} onValueChange={setCorrects}>
            <SelectTrigger id="observation-corrects" className="min-h-11">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">Does not correct one</SelectItem>
              {correctable.map((o) => (
                <SelectItem key={o.id} value={String(o.id)}>
                  {`#${o.id} · ${o.factual_description.slice(0, 60)}`}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
      <div className="space-y-2 sm:col-span-2">
        <Label htmlFor="observation-description">What the customer sent</Label>
        <Textarea
          id="observation-description"
          maxLength={4000}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          required
        />
      </div>
      <FormError problem={problem} error={error} />
      <div className="flex flex-wrap gap-2 sm:col-span-2">
        <Button className="min-h-11" type="submit" disabled={pending}>
          {pending ? "Saving…" : "Add observation"}
        </Button>
        <Button
          className="min-h-11"
          type="button"
          variant="outline"
          onClick={onCancel}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
