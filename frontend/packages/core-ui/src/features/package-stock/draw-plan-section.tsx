import { Button } from "../../components/ui/button";
import { DecimalInput } from "../../components/ui/decimal-input";
import { PackageBreakdown } from "./package-breakdown";
import { PackageDrawEditor } from "./package-draw-editor";
import { packageLabel, trimQty } from "./quantity";
import type { PackageDrawPlan } from "./use-package-draw-plan";
import type { DrawLine, PackageBalance } from "./types";

function describeLine(line: DrawLine & { name?: string }, balance: PackageBalance, unit: string): string {
  if (line.kind === "LOOSE") return `Take ${trimQty(line.quantity)} ${unit} loose`;
  const row = balance.sealed.find((candidate) => candidate.item_packaging_id === line.packaging_id);
  const name = packageLabel(row ?? { name: line.name });
  if (line.kind === "SEALED") return `Take ${line.count} sealed ${name}`;

  return `Open ${line.count} ${name} and take ${trimQty(line.quantity)} ${unit} from it`;
}

/**
 * The wanted quantity, the proposed (and editable) physical draw at a location, and what that location is left with. Shared by issue and transfer.
 * Opening a package is one more line of the draw, never a separate step.
 */
export function DrawPlanSection({
  plan,
  balance,
  unit,
  canOpen,
  wantedLabel,
  wantedId,
}: {
  plan: PackageDrawPlan;
  balance: PackageBalance;
  unit: string;
  canOpen: boolean;
  wantedLabel: string;
  wantedId: string;
}) {
  return (
    <>
      <section className="space-y-2">
        <label htmlFor={wantedId} className="text-sm font-medium">
          {wantedLabel} ({unit})
        </label>
        <DecimalInput
          id={wantedId}
          rule={{ spec: "stockLedger", sign: "positive" }}
          className="w-40"
          value={plan.wanted}
          onChange={(next) => {
            plan.setAdjusting(false);
            plan.setWanted(next);
          }}
          placeholder="e.g. 12"
        />
      </section>

      {plan.lines.length > 0 && (
        <section className="space-y-2" data-testid="proposed-draw">
          <h3 className="text-sm font-medium">{plan.adjusting ? "What you are taking" : "Proposed"}</h3>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {plan.lines.map((line, index) => (
              <li key={index}>{describeLine(line, balance, unit)}</li>
            ))}
          </ul>
          <div className="text-sm" data-testid="leaves">
            <span className="text-muted-foreground">Leaves: </span>
            <PackageBreakdown total={plan.after.total} sealed={plan.after.sealed} loose={plan.after.loose} unit={unit} />
          </div>
          {!plan.matchesWanted && (
            <p className="text-sm text-status-warning">
              This takes {trimQty(plan.total)} {unit}, not the {trimQty(plan.wanted)} {unit} you asked for.
            </p>
          )}
          {plan.blockedByOpen && <p className="text-sm text-destructive">Opening a package needs permission you do not have; take whole packages or loose stock instead.</p>}
        </section>
      )}

      <div>
        <Button type="button" variant="outline" size="sm" onClick={() => plan.setAdjusting((value) => !value)}>
          {plan.adjusting ? "Use the proposal" : "Adjust what is taken"}
        </Button>
      </div>
      {plan.adjusting && <PackageDrawEditor sealed={balance.sealed} loose={balance.loose} unit={unit} value={plan.draw} onChange={plan.setDraw} canOpen={canOpen} />}
      {plan.requiresOpening && !plan.adjusting && !canOpen && <p className="text-sm text-status-warning">The proposal needs a package opened.</p>}
      {plan.message && (
        <p role="alert" className="text-sm text-destructive" data-testid="plan-message">
          {plan.message}
        </p>
      )}
    </>
  );
}
