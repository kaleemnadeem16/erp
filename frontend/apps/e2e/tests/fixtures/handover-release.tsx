// Standalone fixture: never imported by application routes. Scripted in-memory gateway, no network.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes, useLocation } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { HandoverReleaseScreen } from "../../../../packages/core-ui/src/features/handover-release/handover-release-screen";
import type {
  Conditions,
  HandoverReleaseGateway,
  ReleasePreview,
  ReleaseStatus,
} from "../../../../packages/core-ui/src/features/handover-release/handover-release-contract";
import type { Observation } from "../../../../packages/core-ui/src/features/customer-decisions/customer-decision-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { TooltipProvider } from "../../../../packages/core-ui/src/components/ui/tooltip";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface HrFixture {
  permissions: string[];
  /** No basis recorded yet; recording one makes the preview ready (unless `unproven`). */
  noBasis?: boolean;
  /** The recorded basis leaves source applicability unproven (the issued-line default). */
  unproven?: boolean;
  /** Extra blockers the server reports. */
  blockers?: string[];
  /** The case has no other written message. */
  noMessages?: boolean;
  /** Preview fails on the first call (a lost connection). */
  previewFlaky?: boolean;
  /** The release call is refused with this status and reason (e.g. 403, 409 PREVIEW_TOKEN_STALE, 409 RELEASE_ALREADY_RECORDED). */
  releaseRefusal?: { status: number; reason?: string };
  /** The release reports an exact replay. */
  replay?: boolean;
  /** The stage was already released when the page loaded; the Job reference is visible, withheld for this person, or unavailable. */
  released?: "visible" | "denied" | "unavailable";
  /** Someone else releases the stage after the page loaded and before this person confirms. */
  releasedBeforeConfirm?: boolean;
  /** The customer message behind the recorded conditions was corrected. */
  evidenceCorrected?: boolean;
  /** The recorded conditions cannot be read (a failed request). */
  conditionsFail?: boolean;
  /** After the first conditions read, someone records a new version elsewhere (a stale review). */
  basisMovesOn?: boolean;
  /** The plan read fails with this status. */
  planFailure?: number;
}
declare global {
  interface Window {
    __hr: HrFixture;
    __hrCalls: string[];
  }
}
const fixture = window.__hr;
window.__hrCalls = [];
configureQuotationDevMode({ development: true, flag: "1", hostname: "localhost" });
useAuthStore.setState({
  user: {
    id: 42,
    name: "Synthetic user",
    email: "u@fixture.test",
    must_change_password: false,
    permissions: fixture.permissions,
  },
});

const ISSUED = "Fixed service: on-site commissioning of the supplied unit.";
const obs = (over: Partial<Observation>): Observation => ({
  id: 1,
  predecessor_id: null,
  is_current: true,
  source_kind: "EMAIL",
  external_reference: null,
  source_version: null,
  customer_representative_snapshot: "Buyer One",
  authority_verification_basis: "DOCUMENT_ON_FILE",
  occurrence_precision: "UNKNOWN",
  occurred_at: null,
  occurred_until: null,
  source_timezone: null,
  factual_description: "The buyer agrees to the full issued service unchanged.",
  observation_state: "OBSERVED",
  recorded_by: 21,
  recorded_by_name: "Demo Recorder",
  recorded_at: "2026-11-10T08:00:00+05:00",
  ...over,
});
const MESSAGES: Observation[] = fixture.noMessages
  ? [obs({ id: 1 })]
  : [
      obs({ id: 1 }),
      obs({
        id: 2,
        factual_description:
          "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: 101\nDELIVERABLE_SHA256: " +
          "a".repeat(64),
        recorded_at: "2026-11-12T08:00:00+05:00",
      }),
      obs({
        id: 3,
        source_kind: "VERBAL",
        factual_description: "Phoned to add a site visit.",
        recorded_at: "2026-11-13T08:00:00+05:00",
      }),
    ];

let releasedNow: HrFixture["released"] | undefined = fixture.released;
let conditionsCalls = 0;
let headMoved = false;
let basisVersion = fixture.noBasis ? null : 1;
let basisId = fixture.noBasis ? null : 70;
let previewCalls = 0;
const previewNow = (): ReleasePreview => {
  const blockers = [...(fixture.blockers ?? [])];
  if (basisId === null) blockers.unshift("COMPLETION_BASIS_MISSING");
  else if (fixture.unproven) blockers.push("SOURCE_APPLICABILITY_UNPROVEN");
  return {
    ready: blockers.length === 0,
    blockers,
    next_actions: blockers.map((b) => ({
      fact: b,
      owner: b === "SOURCE_APPLICABILITY_UNPROVEN" ? "Inventory" : "Sales",
      action: "Resolve " + b.toLowerCase().replaceAll("_", " ") + ".",
    })),
    cost: { required: false, status: "UNASSESSED" },
    applicability: {
      status: blockers.length === 0 ? "NOT_APPLICABLE" : "UNKNOWN",
      materials: fixture.unproven || basisId === null ? "UNSTATED" : "EXCLUDED",
      subcontract: fixture.unproven || basisId === null ? "UNSTATED" : "EXCLUDED",
    },
    basis_id: headMoved ? 99 : basisId,
    basis_version: headMoved ? 2 : basisVersion,
    fingerprint: `fp-${headMoved ? 2 : (basisVersion ?? 0)}-${blockers.length}`,
  };
};

const gateway: HandoverReleaseGateway = {
  plan: async (planId) => {
    window.__hrCalls.push(`plan:${planId}`);
    if (fixture.planFailure)
      throw Object.assign(new Error("failed"), { status: fixture.planFailure, body: {} });
    if (fixture.releasedBeforeConfirm && window.__hrCalls.some((c) => c.startsWith("preview:")) && window.__hrCalls.filter((c) => c.startsWith("plan:")).length > 1)
      releasedNow = "visible";
    const release: ReleaseStatus | null =
      releasedNow === undefined
        ? null
        : {
            release_event_id: 31,
            released_at: "2026-11-20T10:00:00+05:00",
            job_access: releasedNow === "visible" ? "VISIBLE" : releasedNow === "denied" ? "NOT_PERMITTED" : "UNAVAILABLE",
            job: releasedNow === "visible" ? { id: 88, code: "JOB-00007" } : null,
          };
    return {
      id: planId,
      acceptance_id: 55,
      stage_id: 9,
      recorded_at: "2026-11-11T09:00:00+05:00",
      state: "PREPARED",
      release_performed: release !== null,
      release,
      operational: {
        deliverable_identity: "issued-scope:101",
        description: ISSUED,
        location_kind: "ON_SITE",
        instructions: "Call the site contact first.",
        contact: { id: 4, name: "Site Contact", email: "c@example.invalid", phone: null },
        site: { id: 6, name: "Main Works", address_line1: "1 Mill Road", city: "Lahore", country_code: "PK" },
      },
    };
  },
  acceptance: async (acceptanceId) => {
    window.__hrCalls.push(`acceptance:${acceptanceId}`);
    return { caseId: 7, consentEvidenceId: 1, issuedDescription: ISSUED };
  },
  evidence: async (caseId) => {
    window.__hrCalls.push(`evidence:${caseId}`);
    return MESSAGES;
  },
  conditions: async (acceptanceId) => {
    conditionsCalls += 1;
    window.__hrCalls.push(`conditions:${acceptanceId}`);
    if (fixture.conditionsFail)
      throw Object.assign(new Error("failed"), { status: 500, body: {} });
    const moved = fixture.basisMovesOn && conditionsCalls > 1;
    if (moved) headMoved = true;
    const result: Conditions = {
      acceptance_id: acceptanceId,
      current:
        basisId === null
          ? null
          : {
              basis_id: moved ? 99 : basisId,
              version: moved ? 2 : (basisVersion ?? 1),
              terms_source: fixture.unproven ? "ISSUED_LINE" : "EVIDENCE_TEXT",
              agreed_text: fixture.unproven ? ISSUED : "MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: 101\nDELIVERABLE_SHA256: " + "a".repeat(64),
              materials_scope: fixture.unproven ? "UNSTATED" : "EXCLUDED",
              subcontract_scope: fixture.unproven ? "UNSTATED" : "EXCLUDED",
              evidence_current: !fixture.evidenceCorrected,
              recorded_at: "2026-11-12T09:00:00+05:00",
            },
    };
    return result;
  },
  preview: async (stageId) => {
    previewCalls += 1;
    window.__hrCalls.push(`preview:${stageId}`);
    if (fixture.previewFlaky && previewCalls === 1)
      throw Object.assign(new Error("denied"), { status: 403, body: {} });
    return previewNow();
  },
  recordBasis: async ({ acceptanceId, agreedText, evidenceId, requestKey }) => {
    window.__hrCalls.push(
      `basis:${acceptanceId}:${evidenceId}:${JSON.stringify(agreedText)}:${requestKey ? "key" : "nokey"}`,
    );
    basisVersion = (basisVersion ?? 0) + 1;
    basisId = 70 + basisVersion;
    return { basis_id: basisId, version: basisVersion, replayed: false };
  },
  release: async ({ stageId, basisId: b, fingerprint, requestKey }) => {
    window.__hrCalls.push(`release:${stageId}:${b}:${fingerprint}:${requestKey ? "key" : "nokey"}`);
    const r = fixture.releaseRefusal;
    if (r)
      throw Object.assign(new Error("refused"), {
        status: r.status,
        body: r.reason ? { reason_code: r.reason } : {},
      });
    return { release_event_id: 31, job_id: 88, job_code: "JOB-00007", replayed: fixture.replay ?? false };
  },
};

function JobPage() {
  return createElement("p", { id: "job-page" }, `Job page ${useLocation().pathname}`);
}
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
createRoot(document.getElementById("root")!).render(
  createElement(
    QueryClientProvider,
    { client },
    createElement(
      TooltipProvider,
      null,
      createElement(
        MemoryRouter,
        { initialEntries: ["/handover-plans/12"] },
        createElement(
          Routes,
          null,
          createElement(Route, {
            path: "/handover-plans/:plan",
            element: createElement(HandoverReleaseScreen, { planId: 12, gateway }),
          }),
          createElement(Route, { path: "/jobs/:id", element: createElement(JobPage) }),
          createElement(Route, { path: "/customer-acceptances/:id", element: createElement(JobPage) }),
        ),
      ),
    ),
  ),
);
