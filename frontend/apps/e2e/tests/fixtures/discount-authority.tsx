// Standalone fixture: never imported by application routes. Scripted in-memory gateways, no network.
import { createElement, type ReactNode } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ScreenTemplate } from "../../../../packages/core-ui/src/components/page/screen-template";
import { DiscountAuthoritySettingsScreen } from "../../../../packages/core-ui/src/features/discount-authority/discount-authority-settings-screen";
import { QuotationDiscountAuthorityPanel } from "../../../../packages/core-ui/src/features/discount-authority/quotation-discount-authority-panel";
import { DiscountAuthorityReferencesScreen } from "../../../../packages/core-ui/src/features/discount-authority/discount-authority-references-screen";
import { QuotationAuthorityHistoryScreen } from "../../../../packages/core-ui/src/features/discount-authority/quotation-authority-history-screen";
import { BindQuotationReferencesAction } from "../../../../packages/core-ui/src/features/discount-authority/quotation-bind-references-action";
import {
  mockAuthority,
  mockConfiguration,
  mockEvaluation,
  mockHistoryPage,
  mockReferenceConfiguration,
  type AuthorityConfiguration,
  type DiscountAuthorityGateway,
  type QuotationAuthorityGateway,
  type ReferenceConfiguration,
  type ReferenceGateway,
} from "../../../../packages/core-ui/src/features/discount-authority/discount-authority-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface DaFixture {
  screen: "settings" | "panel" | "references" | "history" | "bind";
  /** Make the bind call refuse with this reason code. */
  bindRefusal?: string;
  /** Number of recorded results (history screen); more than 25 gives a second page. */
  historyTotal?: number;
  /** Role and unit lookups unavailable (no access): the form asks for reference numbers. */
  noLookups?: boolean;
  permissions: string[];
  empty?: boolean;
  panel?: "needs" | "within" | "off" | "none";
}
declare global {
  interface Window {
    __da: DaFixture;
    __daWrites: string[];
  }
}
const fixture = window.__da;
window.__daWrites = [];
configureQuotationDevMode({ development: true, flag: "1", hostname: "localhost" });
useAuthStore.setState({
  user: { id: 42, name: "Synthetic user", email: "u@fixture.test", must_change_password: false, permissions: fixture.permissions },
});
let config: AuthorityConfiguration = fixture.empty
  ? mockConfiguration({ policies: [], bands: [], assignments: [], activations: [] })
  : mockConfiguration();
const gateway: DiscountAuthorityGateway = {
  read: async () => config,
  people: async () => [{ id: 21, name: "Demo Person" }, { id: 22, name: "Other Person" }],
  roles: async () => {
    if (fixture.noLookups) throw new Error("no access");
    return [{ id: 8, name: "Demo Sales role" }];
  },
  units: async () => {
    if (fixture.noLookups) throw new Error("no access");
    return [{ id: 9, name: "Demo Karachi unit" }];
  },
  createPolicy: async ({ policy, expectedGeneration }) => {
    window.__daWrites.push(`policy:${policy.code}:${expectedGeneration}`);
    config = { ...config, generation: config.generation + 1, policies: [...config.policies, { id: 90, code: policy.code, version_no: 1, priority: policy.priority, base_currency_id: 1, allow_higher_approver: false, operation: policy.operation, customer_id: null, unit_id: null, contains_item_id: null, enforcement: policy.enforcement, effective_from: policy.effective_from, effective_to: null, expiry_calendar_days: policy.expiry_calendar_days, created_by: 42, reason: "saved" }], bands: [...config.bands, ...policy.bands.map((b, i) => ({ id: 900 + i, policy_id: 90, rank: b.rank, label: b.label, exception_only: b.exception_only, line_percent: b.line_percent, line_base_amount: null, document_percent: b.document_percent, document_base_amount: null, input_percent: null, input_base_amount: null }))] };
    return { id: 90 };
  },
  activatePolicy: async ({ policyId }) => {
    window.__daWrites.push(`activate:${policyId}`);
    config = { ...config, generation: config.generation + 1, activations: [...config.activations, { id: 50, policy_id: policyId, actor_id: 42, reason: "r", recorded_at: "2026-11-10T08:00:00Z" }] };
    return { id: 50 };
  },
  assign: async ({ assignment, expectedGeneration }) => {
    window.__daWrites.push(`assign:${assignment.user_id}:${assignment.role_id}:${assignment.unit_id}:${expectedGeneration}`);
    return { id: 1 };
  },
  revoke: async ({ assignmentId }) => {
    window.__daWrites.push(`revoke:${assignmentId}`);
    config = { ...config, generation: config.generation + 1, revocations: [{ id: 3, assignment_id: assignmentId, actor_id: 42, reason: "r", recorded_at: "2026-11-10T08:00:00Z" }] };
    return { id: 3 };
  },
};
const evaluation = {
  needs: mockAuthority(),
  within: mockAuthority({ current: mockEvaluation({ result: "ROUTINE", reasons: [] }) }),
  off: mockAuthority({ mode: "OFF" }),
  none: mockAuthority({ current: mockEvaluation({ result: "UNEVALUABLE", policy_id: null, required_band: null, reasons: ["AUTHORITY_POLICY_MISSING"] }) }),
}[fixture.panel ?? "needs"];
let references: ReferenceConfiguration = mockReferenceConfiguration();
const referenceGateway: ReferenceGateway = {
  read: async () => references,
  people: async () => [{ id: 5, name: "Demo Proposer" }, { id: 6, name: "Demo Approver" }],
  currencies: async () => [{ id: 1, code: "PKR", name: "Pakistani rupee" }],
  propose: async ({ reference, expectedGeneration }) => {
    window.__daWrites.push(`propose:${reference.code}:${reference.manual_measure}:${reference.family_id}:${reference.unit_price}:${expectedGeneration}`);
    references = { ...references, generation: references.generation + 1, references: [{ id: 90, code: reference.code, version_no: 1, source_kind: reference.source_kind, source_key: reference.source_key, source_version: reference.source_version, family_id: reference.family_id, customer_id: null, item_id: null, manual_measure: reference.manual_measure, uom_id: null, currency_id: reference.currency_id, unit_price: reference.unit_price, effective_from: reference.effective_from, effective_to: null, priority: reference.priority, proposed_by: 42, reason: "saved" }, ...references.references] };
    return { id: 90 };
  },
  approve: async ({ referenceId, expectedGeneration }) => {
    window.__daWrites.push(`approve:${referenceId}:${expectedGeneration}`);
    references = { ...references, generation: references.generation + 1, approvals: [...references.approvals, { id: 60, reference_id: referenceId, approved_by: 42, reason: "ok", recorded_at: "2026-11-10T08:00:00Z" }] };
    return { id: 60 };
  },
};
const authorityGateway: QuotationAuthorityGateway = {
  read: async () => evaluation,
  history: async (_revisionId, page) => {
    const total = fixture.historyTotal ?? 2;
    const base = mockHistoryPage();
    const records = Array.from({ length: Math.min(25, Math.max(0, total - (page - 1) * 25)) }, (_, i) => ({
      ...base.records[i % 2]!,
      id: 1000 - ((page - 1) * 25 + i),
    }));
    return { ...base, page, per_page: 25, total, records };
  },
  bind: async ({ expectedGeneration }) => {
    window.__daWrites.push(`bind:${expectedGeneration}`);
    if (fixture.bindRefusal) {
      throw Object.assign(new Error("refused"), { status: 422, body: { reason_code: fixture.bindRefusal } });
    }
    return { family_id: 3, revision_id: 12, bound_lines: 2 };
  },
};
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
const page = (...children: ReactNode[]) =>
  createElement(ScreenTemplate, { kind: "detail", className: "space-y-4 p-4", children });
const screens = {
  settings: () => createElement(DiscountAuthoritySettingsScreen, { gateway }),
  references: () => createElement(DiscountAuthorityReferencesScreen, { gateway: referenceGateway }),
  history: () => createElement(QuotationAuthorityHistoryScreen, { revisionId: 12, gateway: authorityGateway }),
  panel: () =>
    page(
      createElement("h1", { key: "h", className: "text-xl font-semibold" }, "Quotation fixture"),
      createElement(QuotationDiscountAuthorityPanel, { key: "p", revisionId: 12, gateway: authorityGateway })),
  bind: () =>
    page(
      createElement("h1", { key: "h", className: "text-xl font-semibold" }, "Quotation fixture"),
      createElement(BindQuotationReferencesAction, { key: "b", revisionId: 12, expectedGeneration: 7, gateway: authorityGateway })),
};
createRoot(document.getElementById("root")!).render(
  createElement(QueryClientProvider, { client },
    createElement(MemoryRouter, null, screens[fixture.screen]())),
);
