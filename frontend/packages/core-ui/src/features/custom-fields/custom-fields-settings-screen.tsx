import { useState, type FormEvent } from "react";
import {
  useCustomFieldDefinitionsIndex,
  useCustomFieldDefinitionsStore,
  type StoreCustomFieldRequestEntityType,
  type StoreCustomFieldRequestDataType,
} from "@erp/core-api";
import { PageHeader } from "../../components/page/page-header";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import { Button } from "../../components/ui/button";
import { Label } from "../../components/ui/label";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "../../components/ui/select";
import { getApiErrorMessage } from "../../lib/api-errors";

const entities = {
  items: "Items",
  parties: "Trading partners",
  supplier_accounts: "Supplier accounts",
  customer_accounts: "Customer accounts",
};
export function CustomFieldsSettingsScreen() {
  const [entity, setEntity] =
    useState<StoreCustomFieldRequestEntityType>("items");
  const [code, setCode] = useState("");
  const [label, setLabel] = useState("");
  const [type, setType] = useState<StoreCustomFieldRequestDataType>("string");
  const [choices, setChoices] = useState("");
  const [required, setRequired] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const query = useCustomFieldDefinitionsIndex({ entity_type: entity });
  const create = useCustomFieldDefinitionsStore();
  const definitions = query.data?.status === 200 ? query.data.data.data : [];
  function submit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSaved(false);
    create.mutate(
      {
        data: {
          entity_type: entity,
          code: code.trim(),
          label: label.trim(),
          data_type: type,
          options: {
            choices:
              type === "select"
                ? choices
                    .split("\n")
                    .map((v) => v.trim())
                    .filter(Boolean)
                : [],
          },
          is_required: required,
          sort_order: definitions.length,
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) {
            setCode("");
            setLabel("");
            setChoices("");
            setRequired(false);
            setSaved(true);
            void query.refetch();
          }
        },
        onError: (failure) => setError(getApiErrorMessage(failure)),
      },
    );
  }
  return (
    <div className="space-y-5 p-4 md:p-6">
      <PageHeader
        title="Custom fields"
        description="Add company-specific information to supported records without changing the application."
      />
      <div className="max-w-sm space-y-2">
        <Label htmlFor="custom-entity">Record type</Label>
        <Select
          value={entity}
          onValueChange={(v) => {
            setEntity(v as StoreCustomFieldRequestEntityType);
            setSaved(false);
          }}
        >
          <SelectTrigger id="custom-entity">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {Object.entries(entities).map(([value, text]) => (
              <SelectItem key={value} value={value}>
                {text}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <DetailSection
        title="Defined fields"
        description="Codes and types stay fixed so existing values keep their meaning."
      >
        <div className="space-y-2 sm:col-span-2">
          {query.isLoading ? (
            <p role="status">Loading fields…</p>
          ) : query.isError ? (
            <p role="alert">
              Couldn't load fields.{" "}
              <button
                type="button"
                className="underline"
                onClick={() => void query.refetch()}
              >
                Retry
              </button>
            </p>
          ) : definitions.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No custom fields for this record type yet.
            </p>
          ) : (
            definitions.map((field) => (
              <div
                key={field.id}
                className="flex flex-wrap justify-between gap-2 rounded-md border bg-background p-3 text-sm"
              >
                <span className="font-medium">
                  {field.label}
                  {field.is_required ? " · Required" : ""}
                </span>
                <span className="text-muted-foreground">
                  {field.code} · {field.data_type}
                </span>
              </div>
            ))
          )}
        </div>
      </DetailSection>
      <form onSubmit={submit} className="space-y-4">
        <DetailSection
          title="Add field"
          description="Required fields apply when values are entered; existing records are not backfilled."
        >
          <div className="space-y-2">
            <Label htmlFor="custom-label">Label</Label>
            <Input
              id="custom-label"
              required
              maxLength={255}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="custom-code">Code</Label>
            <Input
              id="custom-code"
              required
              maxLength={100}
              pattern="[a-z][a-z0-9_]*"
              placeholder="e.g. manufacturer"
              value={code}
              onChange={(e) => setCode(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Lowercase letters, numbers and underscores. Start with a letter.
            </p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="custom-type">Type</Label>
            <Select
              value={type}
              onValueChange={(v) =>
                setType(v as StoreCustomFieldRequestDataType)
              }
            >
              <SelectTrigger id="custom-type">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {Object.entries({
                  string: "Text",
                  number: "Number",
                  date: "Date",
                  bool: "Yes / No",
                  select: "Choice",
                }).map(([value, text]) => (
                  <SelectItem key={value} value={value}>
                    {text}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex items-center gap-2">
            <Checkbox
              id="custom-required"
              checked={required}
              onCheckedChange={(v) => setRequired(v === true)}
            />
            <Label htmlFor="custom-required">Required</Label>
          </div>
          {type === "select" && (
            <div className="space-y-2 sm:col-span-2">
              <Label htmlFor="custom-choices">Choices (one per line)</Label>
              <Textarea
                id="custom-choices"
                required
                value={choices}
                onChange={(e) => setChoices(e.target.value)}
              />
            </div>
          )}
        </DetailSection>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        {saved && (
          <p role="status" className="text-sm text-primary">
            Field added. It is available on the supported record forms.
          </p>
        )}
        <Button
          disabled={create.isPending || query.isLoading || query.isError}
          type="submit"
        >
          {create.isPending ? "Adding…" : "Add field"}
        </Button>
      </form>
    </div>
  );
}
