// Standalone fixture: never imported by application routes. Scripted in-memory gateways, no network.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ScreenTemplate } from "../../../../packages/core-ui/src/components/page/screen-template";
import { DiscountAuthoritySettingsScreen } from "../../../../packages/core-ui/src/features/discount-authority/discount-authority-settings-screen";
import { QuotationDiscountAuthorityPanel } from "../../../../packages/core-ui/src/features/discount-authority/quotation-discount-authority-panel";
import {
  mockAuthority,
  mockConfiguration,
  mockEvaluation,
  type AuthorityConfiguration,
  type DiscountAuthorityGateway,
} from "../../../../packages/core-ui/src/features/discount-authority/discount-authority-contract";
import { configureQuotationDevMode } from "../../../../packages/core-ui/src/lib/quotation-dev-mode";
import { useAuthStore } from "../../../../packages/core-ui/src/stores/auth-store";

export interface DaFixture {
  screen: "settings" | "panel";
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
  user: { id: 42, name: "Synthetic user", email: "u@fixture.test", roles: [], permissions: fixture.permissions },
});
let config: AuthorityConfiguration = fixture.empty
  ? mockConfiguration({ policies: [], bands: [], assignments: [], activations: [] })
  : mockConfiguration();
const gateway: DiscountAuthorityGateway = {
  read: async () => config,
  people: async () => [{ id: 21, name: "Demo Person" }, { id: 22, name: "Other Person" }],
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
  assign: async () => ({ id: 1 }),
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
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
createRoot(document.getElementById("root")!).render(
  createElement(QueryClientProvider, { client },
    createElement(MemoryRouter, null,
      fixture.screen === "settings"
        ? createElement(DiscountAuthoritySettingsScreen, { gateway })
        : createElement(ScreenTemplate, { kind: "detail", className: "space-y-4 p-4" },
            createElement("h1", { className: "text-xl font-semibold" }, "Quotation fixture"),
            createElement(QuotationDiscountAuthorityPanel, { revisionId: 12, gateway: { read: async () => evaluation } }))),
  ),
);
