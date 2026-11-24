/** The ways a role can be limited when given to someone (ADR 0089, 0192). */
export const LIMIT_CHOICES: { value: string; label: string }[] = [
  { value: "MY_UNITS", label: "Only my units" },
  { value: "OWN", label: "Only records I created" },
  { value: "ASSIGNED", label: "Only records assigned to me" },
];

interface LimitableRole {
  id: number;
  name: string;
  /** Decided by the server: "MY_UNITS" always, "OWN" / "ASSIGNED" only where every duty of the role supports them. */
  limit_modes?: string[];
}

/** Which choices a role offers: only what the server says it supports, and "only my units" only where the company has units (or it is already chosen). */
export function limitChoicesFor(role: LimitableRole, current: string, companyHasUnits: boolean) {
  const modes = role.limit_modes ?? ["MY_UNITS"];
  return LIMIT_CHOICES.filter((choice) => modes.includes(choice.value) && (choice.value !== "MY_UNITS" || companyHasUnits || current === "MY_UNITS"));
}

/** One selector per given role. A limit set elsewhere that has no wording here (shared quotations) is kept, shown as it is. */
export function RoleLimitChoices({
  roles,
  limits,
  onChange,
  companyHasUnits,
  disabled,
}: {
  roles: LimitableRole[];
  limits: Record<number, string>;
  onChange: (next: Record<number, string>) => void;
  companyHasUnits: boolean;
  disabled: boolean;
}) {
  const known = LIMIT_CHOICES.map((choice) => choice.value);

  return (
    <>
      {roles.map((role) => {
        const current = limits[role.id] ?? "";
        return (
          <label key={role.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="min-w-32">{role.name}:</span>
            <select
              className="rounded-md border bg-background px-2 py-1 text-sm"
              value={current}
              disabled={disabled}
              aria-label={`Where ${role.name} applies`}
              onChange={(event) => {
                const next = { ...limits };
                if (event.target.value === "") delete next[role.id];
                else next[role.id] = event.target.value;
                onChange(next);
              }}
            >
              <option value="">Company-wide</option>
              {limitChoicesFor(role, current, companyHasUnits).map((choice) => (
                <option key={choice.value} value={choice.value}>
                  {choice.label}
                </option>
              ))}
              {current !== "" && !known.includes(current) && (
                <option value={current}>Limited ({current.toLowerCase().replace(/_/g, " ")})</option>
              )}
            </select>
          </label>
        );
      })}
    </>
  );
}
