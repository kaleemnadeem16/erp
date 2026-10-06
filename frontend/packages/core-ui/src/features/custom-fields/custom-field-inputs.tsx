import { usePermission } from "../../hooks/use-permission";
import { DetailField } from "../../components/detail/detail-field";
import {
  useCustomFieldDefinitionsIndex,
  type StoreCustomFieldRequestEntityType,
} from "@erp/core-api";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Input } from "../../components/ui/input";
import { Checkbox } from "../../components/ui/checkbox";
import { DateField } from "../../components/ui/date-field";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "../../components/ui/select";
import { Label } from "../../components/ui/label";

export type CustomFieldValues = Record<string, string | number | boolean>;

/** The same definitions drive all implemented custom-field entry points. */
export function CustomFieldInputs({
  entity,
  value,
  onChange,
}: {
  entity: StoreCustomFieldRequestEntityType;
  value: CustomFieldValues;
  onChange: (value: CustomFieldValues) => void;
}) {
  const query = useCustomFieldDefinitionsIndex({ entity_type: entity });
  const definitions = query.data?.status === 200 ? query.data.data.data : [];
  if (query.isError)
    return (
      <p role="alert" className="text-sm text-destructive">
        Couldn't load custom fields. Try again before saving.{" "}
        <button
          type="button"
          className="underline"
          onClick={() => void query.refetch()}
        >
          Retry
        </button>
      </p>
    );
  if (!definitions.length) return null;
  return (
    <DetailSection title="Custom fields">
      {definitions.map((field) => {
        const id = `custom-${entity}-${field.code}`;
        const set = (next: string | number | boolean) => {
          const copy = { ...value };
          if (next === "" && !field.is_required) delete copy[field.code];
          else copy[field.code] = next;
          onChange(copy);
        };
        const current = value[field.code];
        return (
          <div key={field.id} className="space-y-2">
            <Label htmlFor={id}>
              {field.label}
              {field.is_required ? " *" : ""}
            </Label>
            {field.data_type === "bool" ? (
              <div className="flex items-center gap-2">
                <Checkbox
                  id={id}
                  checked={current === true}
                  onCheckedChange={(checked) => set(checked === true)}
                />
                <span className="text-sm">
                  {current === true ? "Yes" : "No"}
                </span>
                {field.is_required && current === undefined && (
                  <button
                    className="text-sm underline"
                    type="button"
                    onClick={() => set(false)}
                  >
                    Set to No
                  </button>
                )}
              </div>
            ) : field.data_type === "select" ? (
              <Select
                value={typeof current === "string" ? current : ""}
                onValueChange={set}
              >
                <SelectTrigger id={id}>
                  <SelectValue placeholder="Select an option" />
                </SelectTrigger>
                <SelectContent>
                  {(Array.isArray(field.choices) ? field.choices : []).map(
                    (choice) => (
                      <SelectItem key={choice} value={choice}>
                        {choice}
                      </SelectItem>
                    ),
                  )}
                </SelectContent>
              </Select>
            ) : field.data_type === "date" ? (
              <DateField
                id={id}
                value={typeof current === "string" ? current : ""}
                onChange={set}
              />
            ) : (
              <Input
                id={id}
                required={field.is_required}
                type={field.data_type === "number" ? "number" : "text"}
                step="any"
                value={current === undefined ? "" : String(current)}
                onChange={(event) =>
                  set(
                    field.data_type === "number" && event.target.value !== ""
                      ? Number(event.target.value)
                      : event.target.value,
                  )
                }
              />
            )}
          </div>
        );
      })}
    </DetailSection>
  );
}

export function customFieldValues(value: unknown): CustomFieldValues {
  if (!value || typeof value !== "object" || Array.isArray(value)) return {};
  return Object.fromEntries(
    Object.entries(value).filter(
      (entry): entry is [string, string | number | boolean] =>
        ["string", "number", "boolean"].includes(typeof entry[1]),
    ),
  );
}

export function ItemCustomFieldDisplay({ value }: { value: unknown }) {
  const mayManageItem = usePermission("inventory.item.manage");
  const mayConfigure = usePermission("administration.custom-field.manage");
  const query = useCustomFieldDefinitionsIndex(
    { entity_type: "items" },
    { query: { enabled: mayManageItem || mayConfigure } },
  );
  const definitions = query.data?.status === 200 ? query.data.data.data : [];
  const fields = customFieldValues(value);
  if (!Object.keys(fields).length) return null;
  return (
    <DetailSection title="Custom fields">
      {Object.entries(fields).map(([code, content]) => (
        <DetailField
          key={code}
          label={
            definitions.find((field) => field.code === code)?.label ?? code
          }
        >
          {typeof content === "boolean"
            ? content
              ? "Yes"
              : "No"
            : String(content)}
        </DetailField>
      ))}
    </DetailSection>
  );
}
