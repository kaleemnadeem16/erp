import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import { QUOTATION_VIEW } from "../../../packages/core-ui/src/features/quotations/quotation-access";
import {
  DOCUMENT_DOWNLOAD,
  DOCUMENT_GENERATE,
  DOCUMENT_VIEW,
  DELIVERY_RECORD,
  DELIVERY_VIEW,
} from "../../../packages/core-ui/src/features/quotations/quotation-milestone-access";
import {
  formatBytes,
  historyLabel,
  mockDelivery,
  mockStatus,
  readDeliveries,
  readDocumentStatus,
  readHistory,
} from "../../../packages/core-ui/src/features/quotations/quotation-document-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-document-screen") &
    typeof import("../../../packages/core-ui/src/stores/auth-store");
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-document-ssr",
  `export * from "./quotation-document-screen"; export { useAuthStore } from "../../stores/auth-store";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const {
  QuotationDocumentStatusView,
  QuotationDocumentHistoryView,
  QuotationDeliveriesView,
  DeliveryForm,
  QuotationDocumentScreen,
  documentRefusalMessage,
  useAuthStore,
} = loaded;
test.afterAll(cleanup);

const none = { generate: false, download: false, check: false };
const status = (
  props: Partial<Parameters<typeof QuotationDocumentStatusView>[0]> = {},
) =>
  renderToStaticMarkup(
    createElement(QuotationDocumentStatusView, {
      status: mockStatus(),
      access: { generate: true, download: true, check: true },
      busy: false,
      onGenerate: () => undefined,
      onDownload: () => undefined,
      onCheck: () => undefined,
      ...props,
    }),
  );

test("ready document shows metadata and only the actions the person holds", () => {
  const all = status();
  for (const text of [
    "Ready",
    "Pages",
    "Size",
    "51.1 KB",
    "Download PDF",
    "Print PDF",
    "Check stored file",
  ])
    expect(all).toContain(text);
  expect(all).not.toContain("Generate document");
  const readOnly = status({ access: none });
  expect(readOnly).toContain("Ready");
  for (const hidden of [
    "Download PDF",
    "Print PDF",
    "Check stored file",
    "Generate document",
  ])
    expect(readOnly).not.toContain(hidden);
  expect(
    status({ access: { generate: false, download: true, check: false } }),
  ).toContain("Download PDF");
});

test("download is offered only for an available stored file", () => {
  const unavailable = status({
    status: mockStatus({
      artefact: { ...mockStatus().artefact!, available: false },
    }),
  });
  expect(unavailable).toContain("not available right now");
  expect(unavailable).not.toContain("Download PDF");
});

test("pending, failed and generating states are explicit; failure hides error codes", () => {
  const pending = status({
    status: mockStatus({ state: "PENDING", artefact: null }),
  });
  expect(pending).toContain("Not generated yet");
  expect(pending).toContain("Generate document");
  const failed = status({
    status: mockStatus({
      state: "FAILED",
      error_code: "PDF_ENGINE_SECRET_PATH",
      artefact: null,
    }),
  });
  expect(failed).toContain("Generation failed");
  expect(failed).toContain("Try again");
  expect(failed).not.toContain("PDF_ENGINE_SECRET_PATH");
  expect(
    status({ status: mockStatus({ state: "GENERATING", artefact: null }) }),
  ).toContain("Generating");
  expect(
    status({
      status: mockStatus({ state: "WEIRD", artefact: null }),
      access: none,
    }),
  ).toContain("Status unavailable");
});

test("check outcomes and action errors use fixed wording", () => {
  expect(status({ checkOutcome: "corrupt" })).toContain(
    "does not match its record",
  );
  expect(status({ checkOutcome: "surprise" })).toContain("The check finished.");
  const err = Object.assign(new Error("db password leaked"), { status: 500 });
  const html = status({ actionError: err });
  expect(html).toContain("That could not be completed");
  expect(html).not.toContain("leaked");
  expect(
    documentRefusalMessage(
      Object.assign(new Error(), {
        status: 409,
        body: { reason_code: "DELIVERY_KEY_REUSED" },
      }),
    ),
  ).toContain("already used");
  expect(
    documentRefusalMessage(Object.assign(new Error(), { status: 404 })),
  ).toBe("This document is not available.");
  expect(
    documentRefusalMessage(Object.assign(new Error(), { status: 403 })),
  ).toContain("permission");
});

test("history and deliveries render labelled entries; manual record is not presented as receipt", () => {
  const history = renderToStaticMarkup(
    createElement(QuotationDocumentHistoryView, {
      entries: readHistory([
        {
          at: "2026-10-30T10:00:00Z",
          event: "DOWNLOADED",
          detail: { user_ip: "10.0.0.9" },
        },
        { at: "2026-10-30T11:00:00Z", event: "internal_thing", detail: {} },
      ]),
    }),
  );
  expect(history).toContain("Downloaded");
  expect(history).toContain("Document activity");
  expect(history).not.toContain("10.0.0.9");
  expect(history).not.toContain("internal_thing");
  expect(
    renderToStaticMarkup(
      createElement(QuotationDocumentHistoryView, { entries: [] }),
    ),
  ).toContain("No document activity yet.");
  const deliveries = renderToStaticMarkup(
    createElement(QuotationDeliveriesView, {
      deliveries: [
        mockDelivery(),
        mockDelivery({ id: 2, channel: "COURIER", corrects_id: 1 }),
      ],
    }),
  );
  for (const text of [
    "Handed over in person",
    "A. Customer",
    "Left at reception",
    "Courier",
    "Corrects an earlier record.",
    "not proof the customer received",
  ])
    expect(deliveries).toContain(text);
  expect(deliveries).not.toContain("Person reference");
  expect(
    renderToStaticMarkup(
      createElement(QuotationDeliveriesView, { deliveries: [] }),
    ),
  ).toContain("No deliveries recorded.");
});

test("delivery form has labelled controls and shows validation and server errors", () => {
  const form = renderToStaticMarkup(
    createElement(DeliveryForm, { onSubmit: () => undefined, pending: false }),
  );
  for (const label of [
    "How it was delivered",
    "Delivered on",
    "Received by",
    "Contact detail (optional)",
    "Note (optional)",
  ])
    expect(form).toContain(label);
  for (const id of [
    "delivery-channel",
    "delivery-date",
    "delivery-name",
    "delivery-detail",
    "delivery-note",
  ])
    expect(form).toContain(`for="${id}"`);
  const failed = renderToStaticMarkup(
    createElement(DeliveryForm, {
      onSubmit: () => undefined,
      pending: true,
      error: Object.assign(new Error("x"), { status: 422 }),
    }),
  );
  expect(failed).toContain("Check the details and try again.");
  expect(failed).toContain("Recording…");
});

test("contracts strip extras and reject malformed responses; no storage identity is parsed", () => {
  const parsed = readDocumentStatus({
    ...mockStatus(),
    storage_key: "s3://SECRET",
    hash: "SECRETHASH",
  });
  expect(JSON.stringify(parsed)).not.toMatch(/SECRET/);
  expect(() => readDocumentStatus({ revision_id: 1 })).toThrow();
  expect(
    readDeliveries([{ ...mockDelivery(), internal: "SECRET" }]).length,
  ).toBe(1);
  expect(
    JSON.stringify(readDeliveries([{ ...mockDelivery(), internal: "SECRET" }])),
  ).not.toContain("SECRET");
  expect(formatBytes(10)).toBe("10 bytes");
  expect(historyLabel("print")).toBe("Printed");
});

test("document screen is denied in production and without the document duties", () => {
  const set = (permissions: string[]) =>
    useAuthStore.setState({
      user: {
        id: 1,
        name: "T",
        email: "t@example.test",
        must_change_password: false,
        permissions,
      },
    });
  try {
    set([
      QUOTATION_VIEW,
      DOCUMENT_VIEW,
      DOCUMENT_GENERATE,
      DOCUMENT_DOWNLOAD,
      DELIVERY_VIEW,
      DELIVERY_RECORD,
    ]);
    expect(
      renderToStaticMarkup(
        createElement(QuotationDocumentScreen, { revisionId: 12 }),
      ),
    ).toContain("You don’t have access");
    set([DOCUMENT_VIEW]);
    expect(
      renderToStaticMarkup(
        createElement(QuotationDocumentScreen, { revisionId: 12 }),
      ),
    ).toContain("You don’t have access");
  } finally {
    useAuthStore.setState({ user: null });
  }
});
