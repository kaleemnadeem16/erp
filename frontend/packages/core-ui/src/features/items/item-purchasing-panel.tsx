import { useState } from "react";
import {
  useItemPackagingsIndex,
  useItemPackagingsStore,
  useItemPackagingsUpdate,
  useItemSuppliersIndex,
  useSupplierOffersIndex,
  useSupplierOffersStore,
  useSupplierOffersUpdate,
  useUomsIndex,
  useCurrenciesIndex,
} from "@erp/core-api";
import { usePermission } from "../../hooks/use-permission";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { getApiErrorMessage } from "../../lib/api-errors";
import { ItemSupplierPanel } from "./item-supplier-panel";

export function ItemPurchasingPanel({
  itemId,
  stockUomId,
  stockUnit,
  itemLabel,
}: {
  itemId: number;
  stockUomId: number;
  stockUnit?: string;
  itemLabel: string;
}) {
  const canManage = usePermission("inventory.item.manage");
  const canSupplier = usePermission("purchasing.item-supplier.manage");
  const canCreateOrder = usePermission("purchasing.purchase-order.create");
  const canReadSupplier =
    usePermission("purchasing.item-supplier.view") ||
    canSupplier ||
    canCreateOrder;
  const commercial = usePurchasingCommercialCost();
  const [page, setPage] = useState(1);
  const [offerPage, setOfferPage] = useState(1);
  const packages = useItemPackagingsIndex({
    "filter[item_id]": itemId,
    per_page: 100,
    page,
  });
  const offers = useSupplierOffersIndex(
    { "filter[item_id]": itemId, per_page: 100, page: offerPage },
    { query: { enabled: canReadSupplier } },
  );
  const suppliers = useItemSuppliersIndex(
    {
      "filter[item_id]": itemId,
      include: "supplierAccount",
      per_page: 100,
    },
    { query: { enabled: canReadSupplier } },
  );
  const units = useUomsIndex({ per_page: 100 });
  const currencies = useCurrenciesIndex({ per_page: 100 });
  const addPackage = useItemPackagingsStore();
  const editPackage = useItemPackagingsUpdate();
  const addOffer = useSupplierOffersStore();
  const editOffer = useSupplierOffersUpdate();
  const [error, setError] = useState<string | null>(null);
  const [p, setP] = useState({
    code: "",
    name: "",
    quantity: "",
    increment: "1",
    barcode: "",
    inner: "",
    count: "",
  });
  const [o, setO] = useState({
    preferred: false,
    relationship: "",
    package: "",
    unit: String(stockUomId),
    sku: "",
    price: "",
    currency: "",
    priceUnit: String(stockUomId),
    denominator: "1",
    minimum: "",
    multiple: "",
    lead: "",
    from: "",
    to: "",
  });
  const packRows = packages.data?.status === 200 ? packages.data.data.data : [];
  const offerRows = offers.data?.status === 200 ? offers.data.data.data : [];
  const relRows =
    suppliers.data?.status === 200
      ? suppliers.data.data.data.filter((row) => row.active)
      : [];
  const unitRows = units.data?.status === 200 ? units.data.data.data : [];
  const currencyRows =
    currencies.data?.status === 200 ? currencies.data.data.data : [];
  const fail = (e: unknown) => setError(getApiErrorMessage(e));
  const field = (label: string, value: string, change: (s: string) => void) => (
    <label className="space-y-1 text-sm">
      {label}
      <Input value={value} onChange={(e) => change(e.target.value)} />
    </label>
  );
  const select = (
    label: string,
    value: string,
    change: (s: string) => void,
    choices: { id: number; label: string }[],
    empty: string,
  ) => (
    <label className="space-y-1 text-sm">
      {label}
      <select
        aria-label={label}
        className="h-10 w-full rounded-md border bg-background px-3"
        value={value}
        onChange={(e) => change(e.target.value)}
      >
        <option value="">{empty}</option>
        {choices.map((c) => (
          <option key={c.id} value={c.id}>
            {c.label}
          </option>
        ))}
      </select>
    </label>
  );
  return (
    <div className="space-y-6">
      {error && (
        <p role="alert" className="text-destructive">
          {error}
        </p>
      )}
      <ItemSupplierPanel key={itemId} itemId={itemId} itemLabel={itemLabel} />
      <section className="space-y-3">
        <h3 className="font-medium">Purchasing packages</h3>
        <p className="text-sm text-muted-foreground">
          Fixed content in {stockUnit ?? "the stock unit"}. These definitions do
          not prove unopened stock remains.
        </p>
        {packages.isLoading ? (
          <p>Loading packages…</p>
        ) : packages.isError ? (
          <p role="alert">{getApiErrorMessage(packages.error)}</p>
        ) : packRows.length === 0 ? (
          <p>No packages defined.</p>
        ) : (
          packRows.map((row) => (
            <div
              key={row.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
            >
              <span>
                {row.code} — {row.name}: {row.stock_quantity} {stockUnit} ·{" "}
                {row.active ? "Active" : "Archived"}
              </span>
              {canManage && row.active && (
                <Button
                  variant="outline"
                  disabled={editPackage.isPending}
                  onClick={() =>
                    editPackage.mutate(
                      { itemPackaging: row.id, data: { active: false } },
                      { onSuccess: () => packages.refetch(), onError: fail },
                    )
                  }
                >
                  Archive
                </Button>
              )}
            </div>
          ))
        )}
        <div className="flex gap-2">
          <Button
            variant="outline"
            disabled={page === 1}
            onClick={() => setPage(page - 1)}
          >
            Previous packages
          </Button>
          <Button
            variant="outline"
            disabled={packRows.length < 100}
            onClick={() => setPage(page + 1)}
          >
            Next packages
          </Button>
        </div>
        {canManage && (
          <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(e) => {
              e.preventDefault();
              setError(null);
              addPackage.mutate(
                {
                  data: {
                    item_id: itemId,
                    code: p.code.toLowerCase(),
                    name: p.name,
                    stock_quantity: p.quantity,
                    quantity_precision: p.increment || null,
                    barcode: p.barcode || null,
                    inner_packaging_id: p.inner ? Number(p.inner) : null,
                    inner_count: p.count ? Number(p.count) : null,
                  },
                },
                {
                  onSuccess: () => {
                    packages.refetch();
                    setP({
                      code: "",
                      name: "",
                      quantity: "",
                      increment: "1",
                      barcode: "",
                      inner: "",
                      count: "",
                    });
                  },
                  onError: fail,
                },
              );
            }}
          >
            {field("Package code", p.code, (v) => setP({ ...p, code: v }))}
            {field("Package name", p.name, (v) => setP({ ...p, name: v }))}
            {field("Content in stock unit", p.quantity, (v) =>
              setP({ ...p, quantity: v }),
            )}
            {field(
              "Package increment (blank = unrestricted)",
              p.increment,
              (v) => setP({ ...p, increment: v }),
            )}
            {field("Barcode", p.barcode, (v) => setP({ ...p, barcode: v }))}
            {select(
              "Inner package",
              p.inner,
              (v) => setP({ ...p, inner: v }),
              packRows
                .filter((r) => r.active)
                .map((r) => ({ id: r.id, label: r.name })),
              "No inner package",
            )}
            {field("Inner count", p.count, (v) => setP({ ...p, count: v }))}
            <Button disabled={addPackage.isPending} type="submit">
              Add package
            </Button>
          </form>
        )}
      </section>
      {canReadSupplier && (
        <section className="space-y-3">
          <h3 className="font-medium">Supplier offers</h3>
          <p className="text-sm text-muted-foreground">
            Set up supplier relationships in Supplier items first. Offers hold
            each supplier’s terms.{" "}
            <a className="underline" href="/item-suppliers">
              Manage supplier relationships
            </a>
          </p>
          {offers.isLoading ? (
            <p>Loading offers…</p>
          ) : offers.isError ? (
            <p role="alert">{getApiErrorMessage(offers.error)}</p>
          ) : (
            offerRows.map((row) => (
              <div
                key={row.id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
              >
                <span>
                  Offer {row.id}
                  {row.preferred ? " (default)" : ""} ·{" "}
                  {row.supplier_item_code ?? "No SKU"} ·{" "}
                  {packRows.find((p) => p.id === row.item_packaging_id)?.name ??
                    "Loose"}{" "}
                  {commercial && (
                    <span>
                      {" "}
                      · {row.unit_price} per {row.price_quantity}{" "}
                      {unitRows.find((u) => u.id === row.price_uom_id)?.code ??
                        "packages"}
                    </span>
                  )}{" "}
                  · {row.active ? "Active" : "Archived"}
                </span>
                {canSupplier && commercial && row.active && (
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      disabled={editOffer.isPending || row.preferred}
                      onClick={() =>
                        editOffer.mutate(
                          { supplierOffer: row.id, data: { preferred: true } },
                          { onSuccess: () => offers.refetch(), onError: fail },
                        )
                      }
                    >
                      Make default
                    </Button>
                    <Button
                      variant="outline"
                      disabled={editOffer.isPending}
                      onClick={() => {
                        const price = window.prompt(
                          "New price for future orders",
                          row.unit_price,
                        );
                        if (price !== null)
                          editOffer.mutate(
                            {
                              supplierOffer: row.id,
                              data: { unit_price: price },
                            },
                            {
                              onSuccess: () => offers.refetch(),
                              onError: fail,
                            },
                          );
                      }}
                    >
                      Change price
                    </Button>
                    <Button
                      variant="outline"
                      disabled={editOffer.isPending}
                      onClick={() =>
                        editOffer.mutate(
                          { supplierOffer: row.id, data: { active: false } },
                          { onSuccess: () => offers.refetch(), onError: fail },
                        )
                      }
                    >
                      Archive
                    </Button>
                  </div>
                )}
              </div>
            ))
          )}
          <div className="flex gap-2">
            <Button
              variant="outline"
              disabled={offerPage === 1}
              onClick={() => setOfferPage(offerPage - 1)}
            >
              Previous offers
            </Button>
            <Button
              variant="outline"
              disabled={offerRows.length < 100}
              onClick={() => setOfferPage(offerPage + 1)}
            >
              Next offers
            </Button>
          </div>
          {canSupplier && commercial && (
            <form
              className="grid gap-3 sm:grid-cols-3"
              onSubmit={(e) => {
                e.preventDefault();
                setError(null);
                addOffer.mutate(
                  {
                    data: {
                      preferred: o.preferred,
                      item_supplier_id: Number(o.relationship),
                      item_packaging_id: o.package ? Number(o.package) : null,
                      purchase_uom_id: o.package ? stockUomId : Number(o.unit),
                      supplier_item_code: o.sku || null,
                      unit_price: o.price,
                      currency_id: Number(o.currency),
                      price_uom_id: o.priceUnit ? Number(o.priceUnit) : null,
                      price_quantity: o.denominator,
                      minimum_order_qty: o.minimum || null,
                      order_multiple: o.multiple || null,
                      lead_time_days: o.lead ? Number(o.lead) : null,
                      valid_from: o.from || null,
                      valid_to: o.to || null,
                    },
                  },
                  { onSuccess: () => offers.refetch(), onError: fail },
                );
              }}
            >
              {select(
                "Supplier relationship",
                o.relationship,
                (v) => setO({ ...o, relationship: v }),
                relRows.map((r) => ({
                  id: r.id,
                  label:
                    r.supplier_account?.code ??
                    "Supplier " + r.supplier_account_id,
                })),
                "Select supplier",
              )}
              {select(
                "Package or loose",
                o.package,
                (v) => setO({ ...o, package: v }),
                packRows
                  .filter((r) => r.active)
                  .map((r) => ({ id: r.id, label: r.name })),
                "Loose quantity",
              )}
              {!o.package &&
                select(
                  "Order unit",
                  o.unit,
                  (v) => setO({ ...o, unit: v }),
                  unitRows.map((r) => ({ id: r.id, label: r.code })),
                  "Select unit",
                )}
              {field("Supplier SKU", o.sku, (v) => setO({ ...o, sku: v }))}
              {field("Price", o.price, (v) => setO({ ...o, price: v }))}
              {select(
                "Currency",
                o.currency,
                (v) => setO({ ...o, currency: v }),
                currencyRows.map((r) => ({ id: r.id, label: r.code })),
                "Select currency",
              )}
              {select(
                "Price basis",
                o.priceUnit,
                (v) => setO({ ...o, priceUnit: v }),
                unitRows.map((r) => ({ id: r.id, label: r.code })),
                "Per selected package",
              )}
              {field("Price per quantity", o.denominator, (v) =>
                setO({ ...o, denominator: v }),
              )}
              {field("Minimum order (in order basis)", o.minimum, (v) =>
                setO({ ...o, minimum: v }),
              )}
              {field("Order multiple", o.multiple, (v) =>
                setO({ ...o, multiple: v }),
              )}
              {field("Lead time days", o.lead, (v) => setO({ ...o, lead: v }))}
              {field("Valid from (YYYY-MM-DD)", o.from, (v) =>
                setO({ ...o, from: v }),
              )}
              {field("Valid to (YYYY-MM-DD)", o.to, (v) =>
                setO({ ...o, to: v }),
              )}
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={o.preferred}
                  onChange={(e) => setO({ ...o, preferred: e.target.checked })}
                />
                Default buying option for this supplier
              </label>
              <Button disabled={addOffer.isPending} type="submit">
                Add supplier offer
              </Button>
            </form>
          )}
        </section>
      )}
    </div>
  );
}
