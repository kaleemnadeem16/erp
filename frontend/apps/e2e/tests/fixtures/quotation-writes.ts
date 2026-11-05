// Standalone fixture only: never imported by the application or its route registry.
// Mounts the real gated Issue and Document screens with a scripted gateway, so a spec can drive the
// actual write and retry behaviour (request keys, expected_generation, downloads) with no backend.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";
import { QuotationIssueScreen } from "../../../../packages/core-ui/src/features/quotations/quotation-issue-screen";
import { QuotationDocumentScreen } from "../../../../packages/core-ui/src/features/quotations/quotation-document-screen";
import {
  mockReadiness,
  type QuotationIssueGateway,
} from "../../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
import {
  mockDelivery,
  mockStatus,
  type QuotationDocumentGateway,
} from "../../../../packages/core-ui/src/features/quotations/quotation-document-contract";

/** What the next call of an operation does: return a value, fail with an HTTP status, or fail on the network. */
export type Outcome =
  | { ok: unknown }
  | { status: number; reason_code?: string }
  | { network: true };
export interface WritesFixture {
  screen: "issue" | "document";
  /** false mounts as a production build would: the development exposure is never configured. */
  dev: boolean;
  permissions: string[];
  script: Record<string, Outcome[]>;
}
declare global {
  interface Window {
    __fixture: WritesFixture;
    __calls: { op: string; args: unknown }[];
  }
}

const fixture = window.__fixture;
window.__calls = [];
if (fixture.dev)
  configureQuotationDevMode({
    development: true,
    flag: "1",
    hostname: "localhost",
  });
useAuthStore.setState({
  user: {
    id: 7,
    name: "Fixture",
    email: "fixture@example.test",
    must_change_password: false,
    permissions: fixture.permissions,
  },
} as never);

function fail(outcome: Outcome): never {
  if ("network" in outcome) throw new TypeError("Failed to fetch");
  if ("status" in outcome)
    throw Object.assign(new Error(`HTTP ${outcome.status}`), {
      status: outcome.status,
      body: { message: "Refused.", reason_code: outcome.reason_code },
    });
  throw new Error("unreachable");
}
function call<T>(op: string, args: unknown, fallback: () => T): Promise<T> {
  window.__calls.push({ op, args: JSON.parse(JSON.stringify(args ?? null)) });
  const next = fixture.script[op]?.shift();
  if (next === undefined) return Promise.resolve(fallback());
  if ("ok" in next) {
    const value = next.ok as { __pdf?: string; __blob?: string } | T;
    if (value && typeof value === "object" && "__pdf" in value)
      return Promise.resolve(
        new Blob(["%PDF-1.7 fixture"], { type: "application/pdf" }) as T,
      );
    if (value && typeof value === "object" && "__blob" in value)
      return Promise.resolve(
        new Blob(["<html></html>"], { type: String(value.__blob) }) as T,
      );
    return Promise.resolve(value as T);
  }
  return Promise.reject(fail(next));
}

const issueGateway: QuotationIssueGateway = {
  readiness: (revisionId) =>
    call("readiness", { revisionId }, () => mockReadiness()),
  issue: (input) =>
    call("issue", input, () => ({
      family_id: 8,
      revision_id: input.revisionId,
      issue_id: 1,
      official_number: "QUO-2026-0007",
      issued_at: "2026-10-30T10:15:00Z",
      replayed: false,
    })),
  // Default: no issued record (a cancelled draft or a hidden revision answers 404 here).
  issued: (revisionId) =>
    call("issued", { revisionId }, () => {
      throw Object.assign(new Error("HTTP 404"), {
        status: 404,
        body: { message: "Not found." },
      });
    }),
};
const documentGateway: QuotationDocumentGateway = {
  status: (revisionId) => call("status", { revisionId }, () => mockStatus()),
  generate: (input) => call("generate", input, () => mockStatus()),
  download: (input) =>
    call(
      "download",
      input,
      () => new Blob(["%PDF-1.7 fixture"], { type: "application/pdf" }),
    ),
  history: (revisionId) => call("history", { revisionId }, () => []),
  check: (revisionId) => call("check", { revisionId }, () => "ok"),
  deliveries: (revisionId) => call("deliveries", { revisionId }, () => []),
  recordDelivery: (input) =>
    call("recordDelivery", input, () => mockDelivery()),
  correctDelivery: (input) =>
    call("correctDelivery", input, () =>
      mockDelivery({
        id: 2,
        corrects_id: input.correction.deliveryId,
        kind: input.correction.withdraw ? "WITHDRAWAL" : "CORRECTION",
        correction_reason: input.correction.reason,
      }),
    ),
};

const client = new QueryClient({
  defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
});
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client },
    createElement(
      MemoryRouter,
      null,
      fixture.screen === "issue"
        ? createElement(QuotationIssueScreen, {
            revisionId: 12,
            gateway: issueGateway,
          })
        : createElement(QuotationDocumentScreen, {
            revisionId: 12,
            gateway: documentGateway,
          }),
    ),
  ),
);
