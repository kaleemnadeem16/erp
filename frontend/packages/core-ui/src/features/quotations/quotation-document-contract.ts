import { z } from "zod";

/**
 * CONTRACT ADAPTER for the quotation document routes of agent-b/quotation-document-feature
 * (7d810db9; unmerged, so the generated client is not on develop):
 *   GET  /api/v1/quotations/{revision}/document            status
 *   POST /api/v1/quotations/{revision}/document            generate or retry (Idempotency-Key)
 *   GET  /api/v1/quotations/{revision}/document/download   PDF bytes (?intent=download|print)
 *   GET  /api/v1/quotations/{revision}/document/history
 *   POST /api/v1/quotations/{revision}/document/check      operator hash check
 *   GET  /api/v1/quotations/{revision}/deliveries
 *   POST /api/v1/quotations/{revision}/deliveries          manual record (Idempotency-Key)
 *   POST /api/v1/quotations/{revision}/deliveries/{delivery}/correction   correct or withdraw (Idempotency-Key)
 * SWAP: implement `QuotationDocumentGateway` with the generated quotationsDocument* and
 * quotationsDeliveries* functions; schemas and screens stay.
 */
const id = z.number().int().positive();
export const artefactSchema = z.object({
  id,
  pages: z.number().int().nonnegative(),
  size_bytes: z.number().int().nonnegative(),
  media_type: z.string(),
  created_at: z.string(),
  available: z.boolean(),
});
export const documentStatusSchema = z.object({
  revision_id: id,
  state: z.string(),
  error_code: z.string().nullable(),
  artefact: artefactSchema.nullable(),
});
export const historyEntrySchema = z.object({
  at: z.string(),
  event: z.string(),
});
export const DELIVERY_CHANNELS = ["HAND", "COURIER", "POST", "OTHER"] as const;
export const deliverySchema = z.object({
  id,
  revision_id: id,
  recorded_by: id,
  channel: z.string(),
  delivered_on: z.string(),
  recipient_name: z.string(),
  recipient_detail: z.string().nullable(),
  note: z.string().nullable(),
  corrects_id: id.nullable(),
  kind: z.enum(["RECORD", "CORRECTION", "WITHDRAWAL"]),
  correction_reason: z.string().nullable(),
  superseded_by_id: id.nullable(),
  is_current: z.boolean(),
  recorded_at: z.string(),
});
export const checkOutcomeSchema = z.object({ outcome: z.string() });
export type DocumentStatus = z.infer<typeof documentStatusSchema>;
export type DocumentHistoryEntry = z.infer<typeof historyEntrySchema>;
export type Delivery = z.infer<typeof deliverySchema>;
export type DeliveryChannel = (typeof DELIVERY_CHANNELS)[number];

export interface DeliveryInput {
  channel: DeliveryChannel;
  delivered_on: string;
  recipient_name: string;
  recipient_detail?: string | null;
  note?: string | null;
  corrects_id?: number | null;
}

/** A correction names the record it replaces and always says why. A withdrawal says the delivery did not happen as recorded; the server repeats the old values. */
export interface DeliveryCorrectionInput {
  deliveryId: number;
  body: DeliveryInput;
  reason: string;
  withdraw: boolean;
}

export const readDocumentStatus = (v: unknown) => documentStatusSchema.parse(v);
export const readHistory = (v: unknown) => z.array(historyEntrySchema).parse(v);
export const readDeliveries = (v: unknown) => z.array(deliverySchema).parse(v);
export const readDelivery = (v: unknown) => deliverySchema.parse(v);
export const readCheckOutcome = (v: unknown) =>
  checkOutcomeSchema.parse(v).outcome;

export interface QuotationDocumentGateway {
  status(revisionId: number): Promise<DocumentStatus>;
  generate(input: {
    revisionId: number;
    requestKey: string;
  }): Promise<DocumentStatus>;
  /** The authorised bytes only; the screen never builds or shows a link. */
  download(input: {
    revisionId: number;
    intent: "download" | "print";
  }): Promise<Blob>;
  history(revisionId: number): Promise<DocumentHistoryEntry[]>;
  check(revisionId: number): Promise<string>;
  deliveries(revisionId: number): Promise<Delivery[]>;
  recordDelivery(input: {
    revisionId: number;
    body: DeliveryInput;
    requestKey: string;
  }): Promise<Delivery>;
  /** Appends a correction or withdrawal; the earlier record is never changed. */
  correctDelivery(input: {
    revisionId: number;
    correction: DeliveryCorrectionInput;
    requestKey: string;
  }): Promise<Delivery>;
}

const pending = async (): Promise<never> => {
  throw new Error("The quotation document contract is not available yet.");
};
/** Fails closed until the generated client is on develop. */
export const pendingDocumentGateway: QuotationDocumentGateway = {
  status: pending,
  generate: pending,
  download: pending,
  history: pending,
  check: pending,
  deliveries: pending,
  recordDelivery: pending,
  correctDelivery: pending,
};

/**
 * The states `ReadQuotationDocumentStatus` returns: PENDING (nothing requested), RUNNING, FAILED (with
 * `error_code`) and READY; VERIFIED is the generation intent's own finished state and can surface for a
 * moment before the artefact head is read. Anything else is shown as unavailable, never guessed.
 */
export const STATE_LABELS: Record<string, string> = {
  PENDING: "Not generated yet",
  RUNNING: "Generating",
  VERIFIED: "Generated",
  READY: "Ready",
  FAILED: "Generation failed",
};
export function documentStateLabel(state: string): string {
  return STATE_LABELS[state] ?? "Status unavailable";
}
export const CHECK_LABELS: Record<string, string> = {
  ok: "The stored file matches its record.",
  missing: "The stored file is missing. It was marked, not regenerated.",
  corrupt:
    "The stored file does not match its record. It was marked, not regenerated.",
  unchanged: "Nothing changed since the last check.",
};
export const CHANNEL_LABELS: Record<string, string> = {
  HAND: "Handed over in person",
  COURIER: "Courier",
  POST: "Post",
  OTHER: "Other",
};
/**
 * The events `ReadDocumentArtefactHistory` returns: GENERATED, the access kind and outcome of every hand-off
 * (`DOWNLOAD_DELIVERED`, `PRINT_DELIVERED`, `*_UNAVAILABLE`) and the stored file's health changes. A hand-off is
 * never a receipt by the customer, so no label says "received" or "sent".
 */
export const HISTORY_LABELS: Record<string, string> = {
  GENERATED: "Generated",
  DOWNLOAD_DELIVERED: "Downloaded",
  PRINT_DELIVERED: "Printed",
  DOWNLOAD_UNAVAILABLE: "Download refused: the stored file was unavailable",
  PRINT_UNAVAILABLE: "Print refused: the stored file was unavailable",
  MISSING_DETECTED: "Stored file found missing",
  QUARANTINED: "Stored file quarantined",
  RESTORED: "Stored file restored",
};
export function historyLabel(event: string): string {
  return HISTORY_LABELS[event] ?? "Document activity";
}
export const DOCUMENT_REFUSALS: Record<string, string> = {
  DELIVERY_ALREADY_CORRECTED:
    "That record was already corrected. Reload and correct the latest record.",
  DELIVERY_WITHDRAWN:
    "That record was withdrawn and is final. Record the delivery again as a new record.",
  DELIVERY_KEY_REUSED:
    "This request was already used for a different record. Start again.",
  REQUEST_KEY_REQUIRED: "The request could not be identified. Try again.",
};

/** Human size without client arithmetic on money; bytes only. */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** MOCK DATA: tests and the unregistered preview only. */
export function mockStatus(
  overrides: Partial<DocumentStatus> = {},
): DocumentStatus {
  return {
    revision_id: 12,
    state: "READY",
    error_code: null,
    artefact: {
      id: 3,
      pages: 2,
      size_bytes: 52_300,
      media_type: "application/pdf",
      created_at: "2026-10-30T10:16:00Z",
      available: true,
    },
    ...overrides,
  };
}
export function mockDelivery(overrides: Partial<Delivery> = {}): Delivery {
  return {
    id: 1,
    revision_id: 12,
    recorded_by: 9,
    channel: "HAND",
    delivered_on: "2026-10-31",
    recipient_name: "A. Customer",
    recipient_detail: null,
    note: "Left at reception",
    corrects_id: null,
    kind: "RECORD",
    correction_reason: null,
    superseded_by_id: null,
    is_current: true,
    recorded_at: "2026-10-31T08:00:00Z",
    ...overrides,
  };
}
