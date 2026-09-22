import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  useSupplierAccountsSitesStore,
  type SupplierSiteResource,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { Input } from "../../components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { getApiErrorMessage } from "../../lib/api-errors";

const schema = z.object({
  name: z.string().trim().min(1, "Name is required").max(255),
  role: z.enum(["ORDER_FROM", "SHIP_FROM"]),
  address_line1: z.string().trim().max(255),
  address_line2: z.string().trim().max(255),
  city: z.string().trim().max(255),
  state: z.string().trim().max(255),
  postal_code: z.string().trim().max(20),
  country_code: z
    .string()
    .trim()
    .regex(/^([A-Za-z]{2})?$/, "Use a 2-letter country code, e.g. PK"),
  is_default: z.boolean(),
});
type SupplierSiteFormValues = z.infer<typeof schema>;

const defaultValues: SupplierSiteFormValues = {
  name: "",
  role: "ORDER_FROM",
  address_line1: "",
  address_line2: "",
  city: "",
  state: "",
  postal_code: "",
  country_code: "",
  is_default: false,
};

/**
 * A quick "add an address" side-task, started without leaving the purchase
 * order form it's needed from — see components/ui/dialog.tsx for why this is
 * a centered dialog rather than another side sheet. Creates the supplier's
 * new site and hands the finished record back; the caller decides what to do
 * with it (select it on the order being drafted).
 */
export function SupplierSiteDialog({
  supplierAccountId,
  open,
  onOpenChange,
  onCreated,
}: {
  supplierAccountId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (site: SupplierSiteResource) => void;
}) {
  const form = useForm<SupplierSiteFormValues>({
    resolver: zodResolver(schema),
    defaultValues,
  });
  const create = useSupplierAccountsSitesStore();

  useEffect(() => {
    if (open) form.reset(defaultValues);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  function handleSubmit(values: SupplierSiteFormValues) {
    create.mutate(
      {
        supplierAccount: supplierAccountId,
        data: {
          name: values.name,
          role: values.role,
          address_line1: values.address_line1 || undefined,
          address_line2: values.address_line2 || undefined,
          city: values.city || undefined,
          state: values.state || undefined,
          postal_code: values.postal_code || undefined,
          country_code: values.country_code || undefined,
          is_default: values.is_default,
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 201) {
            onCreated(result.data.data);
            onOpenChange(false);
          }
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a supplier site</DialogTitle>
          <DialogDescription>
            An address this supplier orders from or ships from — used on purchase orders and their print.
          </DialogDescription>
        </DialogHeader>
        <Form {...form}>
          <form className="space-y-4" onSubmit={form.handleSubmit(handleSubmit)}>
            {create.error && (
              <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                {getApiErrorMessage(create.error)}
              </div>
            )}
            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem className="col-span-2">
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="Main warehouse" autoFocus />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="role"
                render={({ field }) => (
                  <FormItem className="col-span-2">
                    <FormLabel>Used for</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="ORDER_FROM">Ordering from (billing/order address)</SelectItem>
                        <SelectItem value="SHIP_FROM">Shipping from (dispatch address)</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="address_line1"
                render={({ field }) => (
                  <FormItem className="col-span-2">
                    <FormLabel>Address line 1</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="address_line2"
                render={({ field }) => (
                  <FormItem className="col-span-2">
                    <FormLabel>Address line 2</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="city"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>City</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="state"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>State / province</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="postal_code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Postal code</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="country_code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Country code</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="PK" maxLength={2} className="uppercase" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
            <FormField
              control={form.control}
              name="is_default"
              render={({ field }) => (
                <FormItem className="flex items-center gap-2 space-y-0">
                  <FormControl>
                    <Checkbox checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} />
                  </FormControl>
                  <FormLabel className="!mt-0">Make this the default for its role</FormLabel>
                </FormItem>
              )}
            />
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending ? "Adding…" : "Add site"}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
