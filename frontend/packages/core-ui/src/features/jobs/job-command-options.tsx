import { useState, type ReactNode } from "react";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Textarea } from "../../components/ui/textarea";
import { MoreOptionsToggle } from "../../components/ui/more-options-toggle";
import type { JobDraft } from "./job-command-fields";
import type { JobOperation } from "./job-operation-contract";

export function JobCommandOptions({
  operation,
  draft,
  disabled,
  field,
  error,
}: {
  operation: JobOperation;
  draft: JobDraft;
  disabled: boolean;
  field: (key: Exclude<keyof JobDraft, "employee">, value: string) => void;
  error: (name: string) => ReactNode;
}) {
  const [advanced, setAdvanced] = useState(
    Boolean(
      draft.instructions ||
      draft.intendedFrom ||
      draft.intendedUntil ||
      draft.occurredAt ||
      draft.precision,
    ),
  );
  return (
    <>
      {operation !== "ASSESS_CLOSEOUT" && (
        <div className="space-y-3">
          <MoreOptionsToggle
            open={advanced}
            onOpenChange={(next) => {
              if (!disabled) setAdvanced(next);
            }}
            controls="job-command-options"
            label="Optional details"
            openLabel="Hide optional details"
            summary={[
              draft.instructions && "instructions",
              (draft.intendedFrom || draft.intendedUntil) && "intended dates",
              draft.occurredAt && "claimed occurrence",
              draft.precision && "precision",
            ]
              .filter(Boolean)
              .join(", ")}
          />
          {advanced && (
            <div
              id="job-command-options"
              className="space-y-4 rounded-md border border-border p-3"
            >
              {operation === "ASSIGN" && (
                <>
                  <div className="space-y-2">
                    <Label htmlFor="job-instructions">
                      Instructions (optional)
                    </Label>
                    <Textarea
                      id="job-instructions"
                      value={draft.instructions}
                      maxLength={2000}
                      disabled={disabled}
                      onChange={(e) => field("instructions", e.target.value)}
                    />
                    {error("instructions")}
                  </div>
                  {(["intendedFrom", "intendedUntil"] as const).map(
                    (key, i) => (
                      <div className="space-y-2" key={key}>
                        <Label htmlFor={`job-${key}`}>
                          Intended {i ? "until" : "from"} (optional, with
                          timezone offset)
                        </Label>
                        <Input
                          id={`job-${key}`}
                          value={draft[key]}
                          disabled={disabled}
                          placeholder="2026-11-24T09:00:00+05:00"
                          onChange={(e) => field(key, e.target.value)}
                        />
                        {error(i ? "intended_until" : "intended_from")}
                      </div>
                    ),
                  )}
                </>
              )}
              <div className="space-y-2">
                <Label htmlFor="job-occurred">
                  Claimed occurrence (optional, with timezone offset)
                </Label>
                <Input
                  id="job-occurred"
                  value={draft.occurredAt}
                  disabled={disabled}
                  placeholder="2026-11-24T09:00:00+05:00"
                  onChange={(e) => field("occurredAt", e.target.value)}
                />
                {error("occurred_at")}
              </div>
              <div className="space-y-2">
                <Label htmlFor="job-precision">
                  Occurrence precision (optional)
                </Label>
                <Input
                  id="job-precision"
                  maxLength={20}
                  value={draft.precision}
                  disabled={disabled}
                  onChange={(e) => field("precision", e.target.value)}
                />
                <p className="text-sm text-muted-foreground">
                  Describe the precision of your claimed time. Recorded time
                  comes from the server.
                </p>
                {error("occurrence_precision")}
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}
