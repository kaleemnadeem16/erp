import { Checkbox } from "../../components/ui/checkbox";
import { Label } from "../../components/ui/label";

interface PermissionSelectionProps {
  groups: Record<string, string[]>;
  value: string[];
  onChange: (permissions: string[]) => void;
  disabled?: boolean;
}

function humanise(value: string) {
  return value
    .split(/[._-]/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

export function PermissionSelection({
  groups,
  value,
  onChange,
  disabled = false,
}: PermissionSelectionProps) {
  const selected = new Set(value);

  function toggle(permission: string, checked: boolean) {
    const next = new Set(selected);
    if (checked) next.add(permission);
    else next.delete(permission);
    onChange([...next].sort());
  }

  return (
    <div className="space-y-4">
      {Object.entries(groups).map(([owner, permissions]) => (
        <fieldset key={owner} className="rounded-md border border-border p-4">
          <legend className="px-1 text-sm font-semibold">
            {humanise(owner)}
          </legend>
          <div className="mt-1 grid gap-3 sm:grid-cols-2">
            {permissions.map((permission) => {
              const id = `permission-${permission}`;
              const label = permission
                .split(".")
                .slice(1)
                .map(humanise)
                .join(" · ");

              return (
                <div key={permission} className="flex items-start gap-2">
                  <Checkbox
                    id={id}
                    checked={selected.has(permission)}
                    disabled={disabled}
                    onCheckedChange={(checked) =>
                      toggle(permission, checked === true)
                    }
                  />
                  <Label
                    htmlFor={id}
                    className="cursor-pointer text-sm font-normal leading-4"
                  >
                    {label || humanise(permission)}
                    <span className="mt-1 block font-mono text-[11px] text-muted-foreground">
                      {permission}
                    </span>
                  </Label>
                </div>
              );
            })}
          </div>
        </fieldset>
      ))}
    </div>
  );
}
