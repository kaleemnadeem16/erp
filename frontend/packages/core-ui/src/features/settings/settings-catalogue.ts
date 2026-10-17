import {
  Building2,
  Calculator,
  Hash,
  ListChecks,
  Network,
  Palette,
  Percent,
  Printer,
  Scale,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  User,
  UserRoundCog,
  Users,
  type LucideIcon,
} from "lucide-react";

/**
 * How a Settings page behaves, so a person can tell at a glance whether they are about to change something:
 * - `policy`   — company-wide rules the screen lets an authorised person change (each keeps its own audit trail where its module has one);
 * - `personal` — this person's own preference; never affects anyone else or any stored business value;
 * - `reference`— read-only information (fixed limits and the rules behind them); there is nothing to change.
 */
export type SettingsKind = "policy" | "personal" | "reference";

export interface SettingsEntry {
  to: string;
  label: string;
  description: string;
  icon: LucideIcon;
  kind: SettingsKind;
  /** Same extra duty the top-bar menu requires; the route's own screen-access rule is always applied as well. */
  permission?: string;
}

export interface SettingsGroup {
  label: string;
  description: string;
  entries: SettingsEntry[];
}

/**
 * The one list of Settings pages, grouped by business area. It only *links* to module-owned screens — each of them keeps its own API, validation and
 * permission — so adding a page here never creates a setting, a table or a second source of truth (ADR 0141: no global precision engine).
 */
export const SETTINGS_GROUPS: SettingsGroup[] = [
  {
    label: "Company",
    description: "Who the company is and how its documents look and are numbered.",
    entries: [
      { to: "/settings/company", label: "Company profile", description: "Who the company is, and the settings every part of the system shares.", icon: Building2, kind: "policy" },
      { to: "/settings/document-numbering", label: "Document numbering", description: "How the numbers on your documents are built. Numbers already issued never change.", icon: Hash, kind: "policy" },
      { to: "/settings/custom-fields", label: "Custom fields", description: "Extra fields on records, defined once for the company.", icon: SlidersHorizontal, kind: "policy" },
      { to: "/settings/printing", label: "Printing", description: "Company details and colours used on every printed document, and the purchase order template.", icon: Printer, kind: "policy" },
    ],
  },
  {
    label: "Purchasing and inventory",
    description: "Rules for buying, receiving and holding stock.",
    entries: [
      { to: "/settings/purchasing", label: "Purchasing", description: "Over-receipt tolerance: how much more than ordered may be received, and under what authority.", icon: Settings, kind: "policy" },
      { to: "/settings/inventory", label: "Inventory", description: "Location structure and the default average-cost pool, with the history of changes.", icon: SlidersHorizontal, kind: "policy" },
    ],
  },
  {
    label: "Estimating",
    description: "How material needs are worked out and compared with what was used.",
    entries: [
      { to: "/settings/estimating", label: "Estimating", description: "The company default overhead policy for assemblies with no override of their own.", icon: Calculator, kind: "policy" },
      { to: "/settings/material-selection", label: "Material selection", description: "Which stock the Material Preview proposes first.", icon: ListChecks, kind: "policy" },
      { to: "/settings/material-variance", label: "Material variance", description: "Reasons and rules for planned material quantities.", icon: Scale, kind: "policy", permission: "estimating.material_variance_policy.manage" },
    ],
  },
  {
    label: "Numbers, money and rounding",
    description: "What the system fixes about decimals, currencies and rounding, and where each rule is governed.",
    entries: [
      { to: "/settings/numbers", label: "Numbers and rounding", description: "Fixed limits for quantities, prices and rates, and which rules can be changed where.", icon: Percent, kind: "reference" },
    ],
  },
  {
    label: "Approvals and access",
    description: "Who can do what, and what needs a second pair of eyes.",
    entries: [
      { to: "/settings/approvals", label: "Approvals", description: "Which actions need approval and from whom.", icon: ShieldCheck, kind: "policy", permission: "approvals.policy.manage" },
      { to: "/settings/members", label: "Members", description: "People in the company and their status.", icon: Users, kind: "policy", permission: "administration.member.view" },
      { to: "/settings/roles", label: "Roles and permissions", description: "What each role may do.", icon: UserRoundCog, kind: "policy", permission: "administration.role.view" },
      { to: "/settings/organisation", label: "Organisation", description: "Teams and reporting structure.", icon: Network, kind: "policy", permission: "administration.organisation.view" },
    ],
  },
  {
    label: "You",
    description: "Preferences that belong to you alone.",
    entries: [
      { to: "/settings/account", label: "Account", description: "Your password, devices and profile.", icon: User, kind: "personal" },
      { to: "/settings/appearance", label: "Appearance", description: "Light or dark theme and colour. Never changes any stored value.", icon: Palette, kind: "personal" },
    ],
  },
];

export const SETTINGS_KIND_LABEL: Record<SettingsKind, string> = {
  policy: "Company policy",
  personal: "Personal",
  reference: "Read-only",
};
