import { useRef, useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
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
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { Textarea } from "../../components/ui/textarea";
import { formatDate, formatDateTime } from "../../lib/format-date";
import {
  getApiReasonCode,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { AccessDenied } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { QuotationFailure } from "./quotation-display";
import { IntentKey } from "./request-key";
import { QuotationScreenShell } from "./quotation-screen-shell";
import { liveDocumentGateway } from "./quotation-live-gateways";
import {
  CHANNEL_LABELS,
  CHECK_LABELS,
  DELIVERY_CHANNELS,
  DOCUMENT_REFUSALS,
  documentStateLabel,
  formatBytes,
  historyLabel,
  type Delivery,
  type DeliveryCorrectionInput,
  type DeliveryChannel,
  type DeliveryInput,
  type DocumentHistoryEntry,
  type DocumentStatus,
  type QuotationDocumentGateway,
} from "./quotation-document-contract";
import {
  quotationMilestoneAccess,
  type QuotationMilestoneAccess,
} from "./quotation-milestone-access";

export function documentRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && DOCUMENT_REFUSALS[code]) return DOCUMENT_REFUSALS[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do that.";
  if (isApiError(error) && error.status === 404)
    return "This document is not available.";
  if (isApiError(error) && error.status === 422)
    return "Check the details and try again.";
  return "That could not be completed. Try again.";
}

type DocumentAccess = Pick<
  QuotationMilestoneAccess,
  "generate" | "download" | "check"
>;

export function QuotationDocumentStatusView({
  status,
  access,
  busy,
  onGenerate,
  onDownload,
  onCheck,
  checkOutcome,
  actionError,
}: {
  status: DocumentStatus;
  access: DocumentAccess;
  busy: boolean;
  onGenerate: () => void;
  onDownload: (intent: "download" | "print") => void;
  onCheck: () => void;
  checkOutcome?: string | null;
  actionError?: unknown;
}) {
  const ready = status.state === "READY" && status.artefact !== null;
  const available = ready && status.artefact!.available;
  const failed = status.state === "FAILED";
  return (
    <DetailSection
      title="Official document"
      description="The PDF is produced from the quotation exactly as it was issued."
      fullWidth
    >
      <DetailField label="Status">
        <StatusBadge tone={ready ? "success" : failed ? "danger" : "pending"}>
          {documentStateLabel(status.state)}
        </StatusBadge>
      </DetailField>
      {failed && (
        <p role="alert" className="col-span-full text-sm text-destructive">
          The document could not be generated. You can try again.
        </p>
      )}
      {ready && (
        <>
          <DetailField label="Pages">{status.artefact!.pages}</DetailField>
          <DetailField label="Size">
            {formatBytes(status.artefact!.size_bytes)}
          </DetailField>
          <DetailField label="Generated">
            {formatDateTime(status.artefact!.created_at)}
          </DetailField>
          <DetailField label="Availability">
            {available
              ? "Available"
              : "The stored file is not available right now."}
          </DetailField>
        </>
      )}
      <div className="col-span-full flex flex-wrap gap-3">
        {access.generate && !ready && (
          <Button
            className="min-h-11"
            type="button"
            onClick={onGenerate}
            disabled={busy}
          >
            {failed ? "Try again" : "Generate document"}
          </Button>
        )}
        {access.download && available && (
          <>
            <Button
              className="min-h-11"
              type="button"
              onClick={() => onDownload("download")}
              disabled={busy}
            >
              Download PDF
            </Button>
            <Button
              className="min-h-11"
              type="button"
              variant="outline"
              onClick={() => onDownload("print")}
              disabled={busy}
            >
              Print PDF
            </Button>
          </>
        )}
        {access.check && ready && (
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={onCheck}
            disabled={busy}
          >
            Check stored file
          </Button>
        )}
      </div>
      {checkOutcome && (
        <p role="status" className="col-span-full text-sm">
          {CHECK_LABELS[checkOutcome] ?? "The check finished."}
        </p>
      )}
      {actionError !== undefined && actionError !== null && (
        <p role="alert" className="col-span-full text-sm text-destructive">
          {documentRefusalMessage(actionError)}
        </p>
      )}
    </DetailSection>
  );
}

export function QuotationDocumentHistoryView({
  entries,
}: {
  entries: DocumentHistoryEntry[];
}) {
  return (
    <DetailSection title="Document history" fullWidth>
      {entries.length === 0 ? (
        <p className="col-span-full text-sm">No document activity yet.</p>
      ) : (
        <ul className="col-span-full space-y-1 text-sm">
          {entries.map((entry, index) => (
            <li key={`${entry.at}-${index}`} className="flex flex-wrap gap-x-3">
              <span className="font-medium">{historyLabel(entry.event)}</span>
              <span className="text-muted-foreground">
                {formatDateTime(entry.at)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </DetailSection>
  );
}

export function QuotationDeliveriesView({
  deliveries,
  onCorrect,
  onWithdraw,
}: {
  deliveries: Delivery[];
  /** Offered only for the record that currently stands, and only to someone who may record deliveries. */
  onCorrect?: (delivery: Delivery) => void;
  onWithdraw?: (delivery: Delivery) => void;
}) {
  return (
    <DetailSection
      title="Manual delivery record"
      description="A person’s statement that the PDF was handed over. It is not proof the customer received, read or accepted it. Records are never edited: a mistake is corrected or withdrawn by adding a new record."
      fullWidth
    >
      {deliveries.length === 0 ? (
        <p className="col-span-full text-sm">No deliveries recorded.</p>
      ) : (
        <ul className="col-span-full space-y-3">
          {deliveries.map((d) => (
            <li
              key={d.id}
              className="min-w-0 break-words border-b border-border pb-3 text-sm last:border-0"
            >
              <p className="font-medium">
                {CHANNEL_LABELS[d.channel] ?? "Other"} ·{" "}
                {formatDate(d.delivered_on)} · {d.recipient_name}
              </p>
              {d.recipient_detail && <p>{d.recipient_detail}</p>}
              {d.note && <p>{d.note}</p>}
              {d.kind === "WITHDRAWAL" ? (
                <p className="text-muted-foreground">
                  Withdrawn: this delivery did not happen as recorded.
                </p>
              ) : (
                d.corrects_id !== null && (
                  <p className="text-muted-foreground">
                    Corrects an earlier record.
                  </p>
                )
              )}
              {d.correction_reason && (
                <p className="text-muted-foreground">
                  Reason: {d.correction_reason}
                </p>
              )}
              {d.superseded_by_id !== null && (
                <p className="text-muted-foreground">
                  Replaced by a later record.
                </p>
              )}
              <p className="text-muted-foreground">
                Recorded {formatDateTime(d.recorded_at)}
              </p>
              {d.is_current && (onCorrect || onWithdraw) && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {onCorrect && (
                    <Button
                      type="button"
                      variant="outline"
                      className="min-h-11"
                      onClick={() => onCorrect(d)}
                    >
                      Correct this record
                    </Button>
                  )}
                  {onWithdraw && (
                    <Button
                      type="button"
                      variant="outline"
                      className="min-h-11"
                      onClick={() => onWithdraw(d)}
                    >
                      Withdraw this record
                    </Button>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </DetailSection>
  );
}

/**
 * Corrects or withdraws one recorded delivery by adding a new record with a reason; the earlier record stays as it is.
 * `withdraw` hides the replacement values: the delivery did not happen as recorded.
 */
export function DeliveryCorrectionForm({
  delivery,
  withdraw,
  onSubmit,
  onCancel,
  pending,
  error,
}: {
  delivery: Delivery;
  withdraw: boolean;
  onSubmit: (input: DeliveryCorrectionInput) => void;
  onCancel: () => void;
  pending: boolean;
  error?: unknown;
}) {
  const [channel, setChannel] = useState<DeliveryChannel>(
    (DELIVERY_CHANNELS as readonly string[]).includes(delivery.channel)
      ? (delivery.channel as DeliveryChannel)
      : "OTHER",
  );
  const [deliveredOn, setDeliveredOn] = useState(delivery.delivered_on);
  const [name, setName] = useState(delivery.recipient_name);
  const [detail, setDetail] = useState(delivery.recipient_detail ?? "");
  const [note, setNote] = useState(delivery.note ?? "");
  const [reason, setReason] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    if (!reason.trim()) {
      setProblem("Say why the earlier record is being replaced.");
      return;
    }
    if (!withdraw && (!deliveredOn || !name.trim())) {
      setProblem("Enter the delivery date and who received it.");
      return;
    }
    setProblem(null);
    onSubmit({
      deliveryId: delivery.id,
      withdraw,
      reason: reason.trim(),
      // A withdrawal repeats the record it withdraws; the server ignores any other values.
      body: withdraw
        ? {
            channel: delivery.channel as DeliveryChannel,
            delivered_on: delivery.delivered_on,
            recipient_name: delivery.recipient_name,
            recipient_detail: delivery.recipient_detail,
            note: delivery.note,
          }
        : {
            channel,
            delivered_on: deliveredOn,
            recipient_name: name.trim(),
            recipient_detail: detail.trim() || null,
            note: note.trim() || null,
          },
    });
  }
  return (
    <DetailSection
      title={
        withdraw ? "Withdraw a delivery record" : "Correct a delivery record"
      }
      description={
        withdraw
          ? "Use this when the delivery did not happen as recorded. The earlier record stays visible, marked as withdrawn."
          : "The earlier record stays visible; this adds a new record that replaces it."
      }
      fullWidth
    >
      <form
        className="col-span-full grid gap-4 sm:grid-cols-2"
        onSubmit={submit}
        noValidate
      >
        {!withdraw && (
          <>
            <div className="space-y-2">
              <Label htmlFor="correction-channel">How it was delivered</Label>
              <Select
                value={channel}
                onValueChange={(v) => setChannel(v as DeliveryChannel)}
              >
                <SelectTrigger id="correction-channel" className="min-h-11">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DELIVERY_CHANNELS.map((c) => (
                    <SelectItem key={c} value={c}>
                      {CHANNEL_LABELS[c]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="correction-date">Delivered on</Label>
              <Input
                className="min-h-11"
                id="correction-date"
                type="date"
                value={deliveredOn}
                onChange={(e) => setDeliveredOn(e.target.value)}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="correction-name">Received by</Label>
              <Input
                className="min-h-11"
                id="correction-name"
                maxLength={200}
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="correction-detail">
                Contact detail (optional)
              </Label>
              <Input
                className="min-h-11"
                id="correction-detail"
                maxLength={200}
                value={detail}
                onChange={(e) => setDetail(e.target.value)}
              />
            </div>
            <div className="space-y-2 sm:col-span-2">
              <Label htmlFor="correction-note">Note (optional)</Label>
              <Textarea
                id="correction-note"
                maxLength={1000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>
          </>
        )}
        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="correction-reason">Reason</Label>
          <Textarea
            id="correction-reason"
            maxLength={500}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            required
          />
        </div>
        {(problem || (error !== undefined && error !== null)) && (
          <p role="alert" className="text-sm text-destructive sm:col-span-2">
            {problem ?? documentRefusalMessage(error)}
          </p>
        )}
        <div className="flex flex-wrap gap-2 sm:col-span-2">
          <Button className="min-h-11" type="submit" disabled={pending}>
            {pending
              ? "Saving…"
              : withdraw
                ? "Withdraw record"
                : "Save correction"}
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
    </DetailSection>
  );
}

export function DeliveryForm({
  onSubmit,
  pending,
  error,
}: {
  onSubmit: (input: DeliveryInput) => void;
  pending: boolean;
  error?: unknown;
}) {
  const [channel, setChannel] = useState<DeliveryChannel>("HAND");
  const [deliveredOn, setDeliveredOn] = useState("");
  const [name, setName] = useState("");
  const [detail, setDetail] = useState("");
  const [note, setNote] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    if (!deliveredOn || !name.trim()) {
      setProblem("Enter the delivery date and who received it.");
      return;
    }
    setProblem(null);
    onSubmit({
      channel,
      delivered_on: deliveredOn,
      recipient_name: name.trim(),
      recipient_detail: detail.trim() || null,
      note: note.trim() || null,
    });
  }
  return (
    <DetailSection title="Record a delivery" fullWidth>
      <form
        className="col-span-full grid gap-4 sm:grid-cols-2"
        onSubmit={submit}
        noValidate
      >
        <div className="space-y-2">
          <Label htmlFor="delivery-channel">How it was delivered</Label>
          <Select
            value={channel}
            onValueChange={(v) => setChannel(v as DeliveryChannel)}
          >
            <SelectTrigger id="delivery-channel" className="min-h-11">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DELIVERY_CHANNELS.map((c) => (
                <SelectItem key={c} value={c}>
                  {CHANNEL_LABELS[c]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="delivery-date">Delivered on</Label>
          <Input
            className="min-h-11"
            id="delivery-date"
            type="date"
            value={deliveredOn}
            onChange={(e) => setDeliveredOn(e.target.value)}
            required
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="delivery-name">Received by</Label>
          <Input
            className="min-h-11"
            id="delivery-name"
            maxLength={200}
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="delivery-detail">Contact detail (optional)</Label>
          <Input
            className="min-h-11"
            id="delivery-detail"
            maxLength={200}
            value={detail}
            onChange={(e) => setDetail(e.target.value)}
          />
        </div>
        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="delivery-note">Note (optional)</Label>
          <Textarea
            id="delivery-note"
            maxLength={1000}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </div>
        {(problem || (error !== undefined && error !== null)) && (
          <p role="alert" className="text-sm text-destructive sm:col-span-2">
            {problem ?? documentRefusalMessage(error)}
          </p>
        )}
        <div className="sm:col-span-2">
          <Button className="min-h-11" type="submit" disabled={pending}>
            {pending ? "Recording…" : "Record delivery"}
          </Button>
        </div>
      </form>
    </DetailSection>
  );
}

/**
 * Saves the authorised bytes the gateway returned. Only a PDF is accepted (an HTML or JSON body is an error,
 * never offered as a file), and the object URL lives briefly after the click: revoking it in the same tick
 * can cancel the download in some browsers.
 */
export function saveBlob(blob: Blob, name: string) {
  if (blob.type !== "application/pdf")
    throw new Error("The server did not return a PDF.");
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = name;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/** Unregistered in Phase 1; contract pending, so the default gateway fails closed. */
export function QuotationDocumentScreen({
  revisionId,
  gateway = liveDocumentGateway,
}: {
  revisionId: number;
  gateway?: QuotationDocumentGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = quotationMilestoneAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (p): p is string => typeof p === "string",
      ),
    ),
  );
  if (!access.documents && !access.deliveries) return <AccessDenied />;
  return (
    <QuotationScreenShell
      title="Official PDF"
      description="The issued quotation's document and the record of how it was delivered."
      revisionId={revisionId}
    >
      <GatedDocument
        revisionId={revisionId}
        gateway={gateway}
        access={access}
      />
    </QuotationScreenShell>
  );
}

function GatedDocument({
  revisionId,
  gateway,
  access,
}: {
  revisionId: number;
  gateway: QuotationDocumentGateway;
  access: QuotationMilestoneAccess;
}) {
  const client = useQueryClient();
  const status = useQuery({
    queryKey: ["quotation-document", revisionId],
    queryFn: () => gateway.status(revisionId),
    enabled: access.documents,
    retry: shouldRetryQuery,
  });
  const history = useQuery({
    queryKey: ["quotation-document-history", revisionId],
    queryFn: () => gateway.history(revisionId),
    enabled: access.documents,
    retry: shouldRetryQuery,
  });
  const deliveries = useQuery({
    queryKey: ["quotation-deliveries", revisionId],
    queryFn: () => gateway.deliveries(revisionId),
    enabled: access.deliveries,
    retry: shouldRetryQuery,
  });
  // Keys are bound to the exact intent: an uncertain failure keeps the key for a safe retry, success or a
  // definitive refusal ends it, and different delivery details always get a new key.
  const generateKey = useRef(new IntentKey());
  const deliveryKey = useRef(new IntentKey());
  const correctionKey = useRef(new IntentKey());
  const [deliveryForm, setDeliveryForm] = useState(0);
  const [correcting, setCorrecting] = useState<{
    delivery: Delivery;
    withdraw: boolean;
  } | null>(null);
  const generate = useMutation({
    mutationFn: () =>
      gateway.generate({
        revisionId,
        requestKey: generateKey.current.for({ revisionId }),
      }),
    onSuccess: () => {
      generateKey.current.settle();
      void client.invalidateQueries({
        queryKey: ["quotation-document", revisionId],
      });
      void client.invalidateQueries({
        queryKey: ["quotation-document-history", revisionId],
      });
    },
    onError: (e) => generateKey.current.settle(e),
  });
  const download = useMutation({
    mutationFn: async (intent: "download" | "print") => {
      const blob = await gateway.download({ revisionId, intent });
      saveBlob(blob, `quotation-${revisionId}.pdf`);
    },
    onSuccess: () =>
      void client.invalidateQueries({
        queryKey: ["quotation-document-history", revisionId],
      }),
  });
  const check = useMutation({
    mutationFn: () => gateway.check(revisionId),
    onSuccess: () =>
      void client.invalidateQueries({
        queryKey: ["quotation-document", revisionId],
      }),
  });
  const record = useMutation({
    mutationFn: (body: DeliveryInput) =>
      gateway.recordDelivery({
        revisionId,
        body,
        requestKey: deliveryKey.current.for({ revisionId, body }),
      }),
    onSuccess: () => {
      deliveryKey.current.settle();
      // A recorded delivery clears the form, so the next entry is a new record, not a resubmission.
      setDeliveryForm((n) => n + 1);
      void client.invalidateQueries({
        queryKey: ["quotation-deliveries", revisionId],
      });
    },
    onError: (e) => deliveryKey.current.settle(e),
  });
  const correct = useMutation({
    mutationFn: (correction: DeliveryCorrectionInput) =>
      gateway.correctDelivery({
        revisionId,
        correction,
        requestKey: correctionKey.current.for({ revisionId, correction }),
      }),
    onSuccess: () => {
      correctionKey.current.settle();
      setCorrecting(null);
      void client.invalidateQueries({
        queryKey: ["quotation-deliveries", revisionId],
      });
    },
    onError: (e) => correctionKey.current.settle(e),
  });
  const busy = generate.isPending || download.isPending || check.isPending;
  return (
    <DetailPageLayout>
      {access.documents &&
        (status.isError ? (
          <QuotationFailure
            error={status.error}
            retry={() => void status.refetch()}
          />
        ) : status.data ? (
          <QuotationDocumentStatusView
            status={status.data}
            access={access}
            busy={busy}
            onGenerate={() => generate.mutate()}
            onDownload={(intent) => download.mutate(intent)}
            onCheck={() => check.mutate()}
            checkOutcome={check.data}
            actionError={generate.error ?? download.error ?? check.error}
          />
        ) : (
          <div role="status">
            <Skeleton className="h-40 w-full" />
            <span className="sr-only">Loading document…</span>
          </div>
        ))}
      {access.documents &&
        (history.isError ? (
          <QuotationFailure
            error={history.error}
            retry={() => void history.refetch()}
          />
        ) : (
          history.data && (
            <QuotationDocumentHistoryView entries={history.data} />
          )
        ))}
      {access.deliveries &&
        (deliveries.isError ? (
          <QuotationFailure
            error={deliveries.error}
            retry={() => void deliveries.refetch()}
          />
        ) : deliveries.isPending ? (
          <div role="status">
            <Skeleton className="h-24 w-full" />
            <span className="sr-only">Loading deliveries…</span>
          </div>
        ) : (
          deliveries.data && (
            <QuotationDeliveriesView
              deliveries={deliveries.data}
              onCorrect={
                access.recordDelivery
                  ? (delivery) => {
                      correct.reset();
                      setCorrecting({ delivery, withdraw: false });
                    }
                  : undefined
              }
              onWithdraw={
                access.recordDelivery
                  ? (delivery) => {
                      correct.reset();
                      setCorrecting({ delivery, withdraw: true });
                    }
                  : undefined
              }
            />
          )
        ))}
      {access.recordDelivery && correcting && (
        <DeliveryCorrectionForm
          key={`${correcting.delivery.id}-${correcting.withdraw}`}
          delivery={correcting.delivery}
          withdraw={correcting.withdraw}
          onSubmit={(input) => correct.mutate(input)}
          onCancel={() => setCorrecting(null)}
          pending={correct.isPending}
          error={correct.error}
        />
      )}
      {access.recordDelivery && (
        <DeliveryForm
          key={deliveryForm}
          onSubmit={(body) => record.mutate(body)}
          pending={record.isPending}
          error={record.error}
        />
      )}
    </DetailPageLayout>
  );
}
