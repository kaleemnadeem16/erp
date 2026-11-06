import { useId, useState } from "react";
import type { UseFormReturn } from "react-hook-form";
import {
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { MoreOptionsToggle } from "../../components/ui/more-options-toggle";
import { Collapse } from "../../components/ui/collapse";
import { FamilyAccessLookup } from "./quotation-family-access-lookup";
import type {
  FamilyAccess,
  FamilyAccessGateway,
} from "./quotation-family-access-contract";
import type {
  AccessFormMode,
  AccessFormValues,
} from "./quotation-family-access-form-model";

export function FamilyAccessFields({
  form,
  snapshot,
  mode,
  gateway,
  pending,
}: {
  form: UseFormReturn<AccessFormValues>;
  snapshot: FamilyAccess;
  mode: AccessFormMode;
  gateway: FamilyAccessGateway;
  pending: boolean;
}) {
  const region = useId();
  const [advanced, setAdvanced] = useState(() =>
    Boolean(form.getValues("starts_at") || form.getValues("ends_at")),
  );
  const clearUnit = form.watch("clear_unit");
  const starts = form.watch("starts_at");
  const ends = form.watch("ends_at");
  const level = form.watch("level");
  const label =
    mode.operation === "owner"
      ? "Responsible owner"
      : mode.operation === "unit"
        ? "Access unit"
        : "Recipient";
  return (
    <div className="space-y-4">
      {mode.operation === "unit" && (
        <FormField
          control={form.control}
          name="clear_unit"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center gap-2 space-y-0">
              <FormControl>
                <Checkbox
                  checked={field.value}
                  onCheckedChange={(checked) =>
                    field.onChange(checked === true)
                  }
                  disabled={pending}
                />
              </FormControl>
              <FormLabel>No access unit</FormLabel>
              <FormMessage />
            </FormItem>
          )}
        />
      )}
      {mode.operation !== "revoke" &&
        !(mode.operation === "unit" && clearUnit) && (
          <FormField
            control={form.control}
            name="candidate"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{label}</FormLabel>
                <FormControl>
                  <FamilyAccessLookup
                    ref={field.ref}
                    familyId={snapshot.family_id}
                    gateway={gateway}
                    kind={
                      mode.operation === "grant" ? "recipient" : mode.operation
                    }
                    level={mode.operation === "grant" ? level : undefined}
                    value={field.value}
                    onChange={field.onChange}
                    label={label}
                    disabled={pending}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        )}
      {mode.operation === "grant" && (
        <>
          <FormField
            control={form.control}
            name="level"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Access level</FormLabel>
                <Select
                  value={field.value}
                  onValueChange={field.onChange}
                  disabled={pending}
                >
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value="VIEW">View only</SelectItem>
                    {snapshot.allowed_actions.delegate_prepare && (
                      <SelectItem value="PREPARE">View and prepare</SelectItem>
                    )}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          <p className="text-sm text-muted-foreground">
            The recipient needs their own quotation permissions and a compatible
            record scope. This does not grant issue, document or
            access-management rights.
          </p>
          <MoreOptionsToggle
            open={advanced}
            onOpenChange={setAdvanced}
            controls={region}
            label="Access period"
            openLabel="Hide access period"
            summary={starts || ends ? "Start or end date set" : undefined}
          />
          <Collapse open={advanced} id={region}>
            <div className="space-y-3">
              <p className="text-sm text-muted-foreground">
                Times use your browser's time zone (
                {Intl.DateTimeFormat().resolvedOptions().timeZone}). Leave start
                blank for now and end blank for no fixed expiry.
              </p>
              <FormField
                control={form.control}
                name="starts_at"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Access starts</FormLabel>
                    <FormControl>
                      <Input
                        type="datetime-local"
                        {...field}
                        disabled={pending}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="ends_at"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Access ends</FormLabel>
                    <FormControl>
                      <Input
                        type="datetime-local"
                        {...field}
                        disabled={pending}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
          </Collapse>
        </>
      )}
      <FormField
        control={form.control}
        name="reason"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Reason</FormLabel>
            <FormControl>
              <Textarea {...field} maxLength={1000} disabled={pending} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
    </div>
  );
}
