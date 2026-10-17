import { Link } from "react-router";

import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { DECIMAL_SPECS } from "../../lib/decimal";
import { canAccessScreen } from "../../routes/screen-access";
import { useAuthStore } from "../../stores/auth-store";

/**
 * Fixed limits come straight from {@link DECIMAL_SPECS} — the same table every input validates against — so this page cannot drift from what the forms
 * enforce. Nothing here is editable and nothing is stored: where a rule *is* a policy, the row says which module owns it and links there (ADR 0141).
 */
const FIXED_LIMITS: { name: string; usedFor: string; places: string; whole: string }[] = [
  { name: "Stock quantities", usedFor: "Posted stock, receipts, counts, transfers, package contents and increments.", places: String(DECIMAL_SPECS.stockLedger.scale), whole: String(DECIMAL_SPECS.stockLedger.maxInteger) },
  { name: "Money amounts", usedFor: "Stored document amounts and purchasing unit prices.", places: String(DECIMAL_SPECS.money.scale), whole: String(DECIMAL_SPECS.money.maxInteger) },
  { name: "Recipe and assembly quantities", usedFor: "Formulations, assemblies, application rates, resource and overhead rates.", places: String(DECIMAL_SPECS.assemblyTechnical.scale), whole: String(DECIMAL_SPECS.assemblyTechnical.maxInteger) },
  { name: "Conversion factors", usedFor: "Unit and package conversions, and the quantity a price is quoted for.", places: String(DECIMAL_SPECS.conversionFactor.scale), whole: String(DECIMAL_SPECS.conversionFactor.maxInteger) },
  { name: "Exchange rates", usedFor: "Manually entered rates on purchase orders and receipts.", places: String(DECIMAL_SPECS.fxRate.scale), whole: String(DECIMAL_SPECS.fxRate.maxInteger) },
  { name: "Percentages and margins", usedFor: "Discounts, tolerances, margins.", places: "4", whole: "—" },
];

const ALWAYS_TRUE = [
  "A quantity, price or rate is never rounded to make it fit. If it cannot be stored exactly, it is refused and you are told what to change.",
  "A converted quantity must be exact at the destination. For example, 7 cartons of 6.666666667 litres is 46.666666669 litres, which stock cannot hold at four places, so it is refused rather than stored as 46.6667.",
  "An exchange rate must be greater than zero.",
  "Amounts and quantities travel between screens and the server as exact decimal text, never as floating-point numbers.",
  "How a number is displayed is a presentation choice. It never changes what is stored or what is accepted.",
];

export function NumbersAndRoundingScreen() {
  const user = useAuthStore((state) => state.user);
  const permissions = new Set(
    Array.isArray(user?.permissions)
      ? user.permissions.filter(
          (permission): permission is string => typeof permission === "string",
        )
      : [],
  );
  // A link only appears for a screen the person may open; otherwise the row names the owner as plain text.
  const where = (to: string, label: string) =>
    canAccessScreen(to, permissions) ? (
      <Link to={to} className="font-medium text-primary underline-offset-2 hover:underline">
        {label}
      </Link>
    ) : (
      <span className="font-medium">{label}</span>
    );

  return (
    <div className="space-y-6" data-testid="numbers-and-rounding">
      <PageHeader
        backTo="/settings"
        title="Numbers and rounding"
        description="What the system fixes about decimals and money, and where each adjustable rule lives. Nothing on this page can be edited."
      />
      <DetailPageLayout>
        <DetailSection
          fullWidth
          title="Fixed limits"
          description="Set by how the data is stored. They apply to every company and cannot be changed in the app."
        >
          <div
            className="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            role="region"
            aria-label="Fixed limits table"
            tabIndex={0}
          >
            <table className="w-full min-w-[34rem] text-sm" data-testid="fixed-limits">
              <thead>
                <tr className="border-b border-border text-left text-muted-foreground">
                  <th scope="col" className="py-2 pr-4 font-medium">Value</th>
                  <th scope="col" className="py-2 pr-4 font-medium">Used for</th>
                  <th scope="col" className="py-2 pr-4 text-right font-medium">Decimal places</th>
                  <th scope="col" className="py-2 text-right font-medium">Whole digits</th>
                </tr>
              </thead>
              <tbody>
                {FIXED_LIMITS.map((row) => (
                  <tr key={row.name} className="border-b border-border last:border-0">
                    <th scope="row" className="py-2 pr-4 text-left font-medium">{row.name}</th>
                    <td className="py-2 pr-4 text-muted-foreground">{row.usedFor}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{row.places}</td>
                    <td className="py-2 text-right tabular-nums">{row.whole}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-sm text-muted-foreground">
            Each currency also has its own number of decimal places (0, 2, 3 or 4, from ISO 4217) that settled amounts follow.
          </p>
        </DetailSection>

        <DetailSection title="Always true" description="These hold on every screen and cannot be switched off.">
          <ul className="list-disc space-y-2 pl-5 text-sm">
            {ALWAYS_TRUE.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        </DetailSection>

        <DetailSection title="Where each adjustable rule lives" description="Each rule is owned by one module. This page only points to it.">
          <dl className="space-y-4 text-sm" data-testid="rule-owners">
            <div>
              <dt className="font-medium">Item ordering increments</dt>
              <dd className="text-muted-foreground">
                Set per item and per package on {where("/items", "Items")}, up to four places.
              </dd>
            </div>
            <div>
              <dt className="font-medium">Over-receipt tolerance</dt>
              <dd className="text-muted-foreground">
                Company policy on {where("/settings/purchasing", "Purchasing settings")}.
              </dd>
            </div>
            <div>
              <dt className="font-medium">Currency decimal places</dt>
              <dd className="text-muted-foreground">
                Global ISO 4217 reference data, synchronised when the system is deployed. A company does not edit it, and a currency already in use never changes its decimal places.
              </dd>
            </div>
            <div>
              <dt className="font-medium">Sales unit-price entry precision and rounding</dt>
              <dd className="text-muted-foreground">
                A versioned company policy owned by Sales: entry precision of two to four places (default four), applied to new work only, with issued quotations keeping the precision they were issued under. It cannot be changed from the app yet; it will be configured with the Sales financial policy once that is released.
              </dd>
            </div>
            <div>
              <dt className="font-medium">How numbers look on screen</dt>
              <dd className="text-muted-foreground">
                A presentation choice only; it never changes what is stored or accepted. Theme and colour are personal, under {where("/settings/appearance", "Appearance")}.
              </dd>
            </div>
          </dl>
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
