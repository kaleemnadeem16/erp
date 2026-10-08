import { useMemo, useState } from "react";
import {
  Archive,
  ArrowLeft,
  Boxes,
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  Copy,
  ExternalLink,
  Layers,
  Pencil,
  Power,
  ShieldCheck,
  Star,
  Tag,
} from "lucide-react";
import { Link, useLocation, useNavigate, useParams } from "react-router";
import {
  useCurrenciesIndex,
  useItemSuppliersDestroy,
  useItemSuppliersShow,
  useItemSuppliersUpdate,
  useSupplierOffersIndex,
  useUomsIndex,
  type SupplierOfferResource,
} from "@erp/core-api";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import {
  DetailPrintLayout,
  PrintTrigger,
  useQuickPrint,
  type DetailPrintSection,
} from "../../components/print";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { usePermission } from "../../hooks/use-permission";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";
import { formatDate, formatDateTime } from "../../lib/format-date";
import type { Presentation } from "../../lib/presentation";
import { ItemSupplierStatusBadge } from "./item-suppliers-columns";

export interface ItemSupplierDetailScreenProps {
  presentation?: Presentation;
}

export function ItemSupplierDetailScreen({
  presentation = "sheet",
}: ItemSupplierDetailScreenProps) {
  const mayManage = usePermission("purchasing.item-supplier.manage");
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const { id } = useParams<{ id: string }>();
  const relationshipId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();

  const query = useItemSuppliersShow(relationshipId, {
    include: "item,supplierAccount,purchaseUom",
  });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;

  const offersQuery = useSupplierOffersIndex(
    {
      "filter[item_id]": row?.item_id,
      "filter[supplier_account_id]": row?.supplier_account_id,
      per_page: 20,
    },
    { query: { enabled: !!row } },
  );
  const offers: SupplierOfferResource[] =
    offersQuery.data?.status === 200 ? offersQuery.data.data.data : [];

  const currenciesQuery = useCurrenciesIndex(
    { per_page: 100 },
    {
      query: {
        enabled:
          mayViewCommercialCost || !!row?.supplier_account?.default_currency_id,
      },
    },
  );
  const currencies =
    currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyMap = useMemo(
    () => new Map(currencies.map((c) => [c.id, c.code])),
    [currencies],
  );

  const uomsQuery = useUomsIndex({ per_page: 100 });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomMap = useMemo(() => new Map(uoms.map((u) => [u.id, u])), [uoms]);

  const updateMutation = useItemSuppliersUpdate();
  const destroyMutation = useItemSuppliersDestroy();
  const [copiedSku, setCopiedSku] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { contentRef, print } = useQuickPrint({
    documentTitle: row
      ? `Item Supplier - ${row.item?.code ?? row.item_id} - ${row.supplier_account?.code ?? row.supplier_account_id}`
      : "Item Supplier",
  });

  const handleClose = () => {
    navigate({ pathname: "/item-suppliers", search });
  };

  const handleToggleActive = () => {
    if (!row) return;
    setActionError(null);
    if (row.active) {
      destroyMutation.mutate(
        { itemSupplier: row.id },
        {
          onSuccess: () => {
            void query.refetch();
          },
          onError: () => {
            setActionError("Failed to archive relationship.");
          },
        },
      );
    } else {
      updateMutation.mutate(
        { itemSupplier: row.id, data: { active: true } },
        {
          onSuccess: () => {
            void query.refetch();
          },
          onError: () => {
            setActionError("Failed to activate relationship.");
          },
        },
      );
    }
  };

  const copySku = () => {
    if (!row?.supplier_item_code) return;
    navigator.clipboard?.writeText(row.supplier_item_code);
    setCopiedSku(true);
    setTimeout(() => setCopiedSku(false), 2000);
  };

  if (query.isLoading) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Loading relationship…"
          size="lg"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 p-5">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-48 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-48 w-full" />
        </div>
      </ScreenTemplate>
    );
  }

  if (!row || query.isError) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Relationship not found"
          size="lg"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 p-5">
            <p className="text-sm text-destructive">
              Couldn't find this item-supplier relationship.
            </p>
            <Button variant="outline" onClick={handleClose}>
              Back to item suppliers
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">
            Couldn't find this item-supplier relationship.
          </p>
          <Button
            variant="outline"
            onClick={() => navigate({ pathname: "/item-suppliers", search })}
          >
            <ArrowLeft className="h-4 w-4 mr-1.5" />
            Back to item suppliers
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const itemTitle = row.item?.name ?? `Item #${row.item_id}`;
  const supplierTitle = row.supplier_account?.party?.name
    ? `${row.supplier_account.code} — ${row.supplier_account.party.name}`
    : (row.supplier_account?.code ?? `Supplier #${row.supplier_account_id}`);

  const stockUom = row.item?.stock_uom_id
    ? uomMap.get(row.item.stock_uom_id)
    : undefined;
  const purchaseUomLabel = row.purchase_uom
    ? `${row.purchase_uom.name} (${row.purchase_uom.code})`
    : stockUom
      ? `Item stock unit (${stockUom.code})`
      : "Item stock unit";

  const defaultCurrencyCode = row.supplier_account?.default_currency_id
    ? currencyMap.get(row.supplier_account.default_currency_id)
    : undefined;

  const printSections: DetailPrintSection[] = [
    {
      title: "Item Details",
      fields: [
        { label: "Item Name", value: itemTitle },
        { label: "Item Code", value: row.item?.code ?? String(row.item_id) },
        { label: "Category", value: row.item?.category?.name ?? "None" },
        {
          label: "Stock Unit",
          value: stockUom ? `${stockUom.name} (${stockUom.code})` : "None",
        },
        { label: "Item Type", value: row.item?.type ?? "Standard" },
      ],
    },
    {
      title: "Supplier Account Details",
      fields: [
        {
          label: "Supplier Name",
          value: row.supplier_account?.party?.name ?? supplierTitle,
        },
        {
          label: "Supplier Code",
          value: row.supplier_account?.code ?? String(row.supplier_account_id),
        },
        {
          label: "Payment Terms",
          value:
            row.supplier_account?.payment_terms_days != null
              ? `${row.supplier_account.payment_terms_days} days`
              : "Standard",
        },
        {
          label: "Default Lead Time",
          value:
            row.supplier_account?.lead_time_days != null
              ? `${row.supplier_account.lead_time_days} days`
              : "Not set",
        },
        {
          label: "Default Currency",
          value: defaultCurrencyCode ?? "Base currency",
        },
      ],
    },
    {
      title: "Sourcing Terms",
      fields: [
        {
          label: "Supplier Item Code (SKU)",
          value: row.supplier_item_code ?? "None",
        },
        {
          label: "Priority Ranking",
          value:
            row.priority === 1
              ? "#1 (Preferred supplier)"
              : row.priority
                ? `#${row.priority}`
                : "None",
        },
        {
          label: "Lead Time Override",
          value:
            row.lead_time_days == null
              ? "Using supplier default"
              : `${row.lead_time_days} days`,
        },
        {
          label: "Resolved Lead Time",
          value: `${row.resolved_lead_time_days ?? "—"} days`,
        },
        {
          label: "Minimum Order Quantity",
          value: row.minimum_order_qty
            ? `${row.minimum_order_qty} ${row.purchase_uom?.code ?? stockUom?.code ?? ""}`
            : "None",
        },
        { label: "Purchase Unit", value: purchaseUomLabel },
        { label: "Status", value: row.active ? "Active" : "Inactive" },
      ],
    },
    {
      title: "Record Governance & Audit",
      fields: [
        { label: "Relationship ID", value: String(row.id) },
        { label: "Created at", value: formatDate(row.created_at) ?? "" },
        { label: "Updated at", value: formatDate(row.updated_at) ?? "" },
        {
          label: "Policy Note",
          value:
            row.priority === 1
              ? "Designated as primary preferred supplier for automated purchasing suggestions."
              : "Alternative vendor on file.",
        },
      ],
    },
  ];

  const headerActions = (
    <div className="flex items-center gap-2">
      <PrintTrigger onPrint={print} />
      {mayManage && (
        <>
          <Button asChild size="sm" variant="outline">
            <Link to={`/item-suppliers/${relationshipId}/edit${search}`}>
              <Pencil className="h-4 w-4 mr-1.5" />
              Edit sourcing terms
            </Link>
          </Button>
          <Button
            size="sm"
            variant={row.active ? "outline" : "default"}
            className={
              row.active
                ? "text-destructive hover:bg-destructive/10 hover:text-destructive"
                : ""
            }
            onClick={handleToggleActive}
            disabled={updateMutation.isPending || destroyMutation.isPending}
          >
            {row.active ? (
              <>
                <Archive className="h-4 w-4 mr-1.5" />
                Archive
              </>
            ) : (
              <>
                <Power className="h-4 w-4 mr-1.5" />
                Activate
              </>
            )}
          </Button>
        </>
      )}
    </div>
  );

  const detailBody = (
    <div className="space-y-6">
      {actionError && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-3.5 text-sm text-destructive font-medium">
          {actionError}
        </div>
      )}

      {!row.active && (
        <div className="flex items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-900 dark:text-amber-200">
          <Archive className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
          <div className="leading-relaxed">
            <span className="font-semibold">
              This relationship is archived (inactive).
            </span>{" "}
            It remains in the system for historical audit and reporting, but is
            hidden from active purchase order line selection.
          </div>
        </div>
      )}

      {/* 4 EVEN CARDS GRID (2x2 on desktop with equal height) */}
      <DetailPageLayout equalHeight>
        {/* BOX 1: Item Details */}
        <DetailSection
          title="Item details"
          description="Catalog item specification, inventory unit, and tracking properties."
          className="p-5 space-y-4"
        >
          <DetailField label="Item name" fullWidth className="space-y-1">
            <div className="flex items-center justify-between gap-2">
              <span className="font-bold text-foreground text-base tracking-tight leading-snug">
                {itemTitle}
              </span>
              {row.item_id && (
                <Link
                  to={`/items/${row.item_id}`}
                  className="inline-flex items-center gap-1 text-xs text-primary hover:underline font-medium shrink-0"
                >
                  View item
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
          </DetailField>

          <DetailField label="Item code" className="space-y-1">
            <span className="text-xs font-mono font-medium text-foreground bg-muted/60 px-2 py-0.5 rounded">
              {row.item?.code ?? String(row.item_id)}
            </span>
          </DetailField>

          <DetailField label="Item category" className="space-y-1">
            <span className="font-medium text-foreground">
              {row.item?.category?.name ?? "Uncategorized"}
            </span>
          </DetailField>

          <DetailField label="Inventory stock unit" className="space-y-1">
            <div className="font-medium text-foreground">
              {stockUom ? `${stockUom.name} (${stockUom.code})` : "Stock unit"}
            </div>
          </DetailField>

          <DetailField label="Item classification" className="space-y-1">
            <span className="font-medium text-foreground capitalize">
              {row.item?.type
                ? `${row.item.type.toLowerCase().replace(/_/g, " ")}`
                : "Standard"}
            </span>
          </DetailField>

          <DetailField label="Inventory tracking" className="space-y-1">
            <span className="font-medium text-foreground capitalize">
              {row.item?.tracking
                ? `${row.item.tracking.toLowerCase()} tracking`
                : "Standard quantity"}
            </span>
          </DetailField>

          <DetailField label="Valuation method" className="space-y-1">
            <span className="font-medium text-foreground">
              {row.item?.valuation_method ?? "AVCO"}
            </span>
          </DetailField>
        </DetailSection>

        {/* BOX 2: Supplier Account Details */}
        <DetailSection
          title="Supplier details"
          description="Vendor commercial profile, standard lead times, and terms."
          className="p-5 space-y-4"
        >
          <DetailField label="Supplier name" fullWidth className="space-y-1">
            <div className="flex items-center justify-between gap-2">
              <span className="font-bold text-foreground text-base tracking-tight leading-snug">
                {row.supplier_account?.party?.name ?? supplierTitle}
              </span>
              {row.supplier_account_id && (
                <Link
                  to={`/suppliers/${row.supplier_account_id}`}
                  className="inline-flex items-center gap-1 text-xs text-primary hover:underline font-medium shrink-0"
                >
                  View supplier
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
          </DetailField>

          <DetailField label="Account code" className="space-y-1">
            <span className="text-xs font-mono font-medium text-foreground bg-muted/60 px-2 py-0.5 rounded">
              {row.supplier_account?.code ?? String(row.supplier_account_id)}
            </span>
          </DetailField>

          <DetailField label="Supplier status" className="space-y-1">
            <span className="font-medium text-foreground capitalize">
              {row.supplier_account?.status
                ? row.supplier_account.status.toLowerCase()
                : "Active"}
            </span>
          </DetailField>

          <DetailField label="Payment terms" className="space-y-1">
            <span className="font-medium text-foreground">
              {row.supplier_account?.payment_terms_days != null
                ? `${row.supplier_account.payment_terms_days} days net`
                : "Standard terms"}
            </span>
          </DetailField>

          <DetailField label="Account default lead time" className="space-y-1">
            <div className="inline-flex items-center gap-1.5 font-medium text-foreground">
              <Clock className="h-3.5 w-3.5 text-muted-foreground" />
              <span>
                {row.supplier_account?.lead_time_days != null
                  ? `${row.supplier_account.lead_time_days} days`
                  : "Not specified"}
              </span>
            </div>
          </DetailField>

          <DetailField label="Default currency" className="space-y-1">
            <span className="font-medium text-foreground">
              {defaultCurrencyCode ? `${defaultCurrencyCode}` : "Base currency"}
            </span>
          </DetailField>

          <DetailField label="Vendor account ID" className="space-y-1">
            <span className="text-xs font-mono text-muted-foreground">
              #{row.supplier_account_id}
            </span>
          </DetailField>
        </DetailSection>

        {/* BOX 3: Sourcing Terms & Procurement Parameters */}
        <DetailSection
          title="Sourcing terms"
          description="Supplier catalog SKU, purchase units, priority ranking, and order quantities."
          className="p-5 space-y-4"
        >
          <DetailField
            label="Supplier item code (SKU)"
            fullWidth
            className="space-y-1"
          >
            {row.supplier_item_code ? (
              <div className="flex items-center gap-2">
                <code className="rounded bg-muted px-2.5 py-1 font-mono text-xs font-semibold text-foreground">
                  {row.supplier_item_code}
                </code>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7"
                  onClick={copySku}
                  title="Copy supplier SKU"
                >
                  {copiedSku ? (
                    <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                  ) : (
                    <Copy className="h-4 w-4 text-muted-foreground" />
                  )}
                </Button>
              </div>
            ) : (
              <span className="text-muted-foreground italic text-xs">
                No vendor SKU specified
              </span>
            )}
          </DetailField>

          <DetailField label="Sourcing priority" className="space-y-1">
            {row.priority === 1 ? (
              <Badge
                variant="outline"
                className="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 font-semibold gap-1 inline-flex items-center py-1"
              >
                <Star className="h-3.5 w-3.5 fill-emerald-500 text-emerald-500" />
                #1 Preferred supplier
              </Badge>
            ) : row.priority != null ? (
              <span className="font-semibold text-foreground">
                #{row.priority} Alternative
              </span>
            ) : (
              <span className="text-muted-foreground italic text-xs">
                Unranked alternative
              </span>
            )}
          </DetailField>

          <DetailField label="Purchase unit" className="space-y-1">
            <span className="font-medium text-foreground">
              {purchaseUomLabel}
            </span>
          </DetailField>

          <DetailField label="Lead time override" className="space-y-1">
            {row.lead_time_days != null ? (
              <div className="inline-flex items-center gap-1.5 font-medium text-foreground">
                <Clock className="h-3.5 w-3.5 text-primary" />
                <span>{row.lead_time_days} days (Custom override)</span>
              </div>
            ) : (
              <span className="text-muted-foreground text-xs italic">
                Inheriting account default
              </span>
            )}
          </DetailField>

          <DetailField
            label="Resolved expected lead time"
            className="space-y-1"
          >
            <div className="inline-flex items-center gap-1.5 font-semibold text-foreground">
              <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
              <span>
                {row.resolved_lead_time_days != null
                  ? `${row.resolved_lead_time_days} days expected`
                  : "Not specified"}
              </span>
            </div>
          </DetailField>

          <DetailField
            label="Minimum order quantity (MOQ)"
            className="space-y-1"
          >
            {row.minimum_order_qty ? (
              <span className="tabular-nums font-semibold text-foreground">
                {Number(row.minimum_order_qty).toLocaleString("en-US", {
                  maximumFractionDigits: 4,
                })}
                {row.purchase_uom
                  ? ` ${row.purchase_uom.code}`
                  : stockUom
                    ? ` ${stockUom.code}`
                    : ""}
              </span>
            ) : (
              <span className="text-muted-foreground italic text-xs">
                No MOQ required
              </span>
            )}
          </DetailField>

          <DetailField label="Relationship status" className="space-y-1">
            <ItemSupplierStatusBadge active={row.active} />
          </DetailField>
        </DetailSection>

        {/* BOX 4: Record Governance & Audit */}
        <DetailSection
          title="Record governance"
          description="System identifiers, audit timestamps, and automated replenishment policy."
          className="p-5 space-y-4"
        >
          <DetailField label="Relationship ID" className="space-y-1">
            <span className="font-mono text-xs font-semibold text-muted-foreground bg-muted/60 px-2 py-0.5 rounded">
              #{row.id}
            </span>
          </DetailField>

          <DetailField label="Designation role" className="space-y-1">
            <div className="flex items-center gap-1.5 font-medium text-foreground">
              <ShieldCheck className="h-3.5 w-3.5 text-primary" />
              <span>
                {row.priority === 1
                  ? "Primary preferred source"
                  : "Approved alternative"}
              </span>
            </div>
          </DetailField>

          <DetailField label="Created at" className="space-y-1">
            <span className="text-sm text-foreground font-medium">
              {formatDateTime(row.created_at)}
            </span>
          </DetailField>

          <DetailField label="Last modified" className="space-y-1">
            <span className="text-sm text-foreground font-medium">
              {formatDateTime(row.updated_at)}
            </span>
          </DetailField>

          <DetailField
            label="Procurement policy summary"
            fullWidth
            className="space-y-1"
          >
            <p className="text-xs text-muted-foreground leading-relaxed bg-muted/30 p-3 rounded-lg border border-border/50">
              {row.priority === 1
                ? "Automated reorder triggers and manual purchase orders default to this supplier. Changing the preferred supplier for this item will reassign priority #1."
                : "This supplier is recorded as an approved alternative. It can be selected manually when raising purchase orders or when the primary vendor is unavailable."}
            </p>
          </DetailField>
        </DetailSection>
      </DetailPageLayout>

      {/* Commercial Offers / Pricing section */}
      <section className="space-y-3 pt-2">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
              <Tag className="h-4 w-4 text-primary" />
              Supplier offers & contracted pricing
            </h3>
            <p className="text-xs text-muted-foreground leading-normal mt-0.5">
              Quotations, contracted tier pricing, and active price validity on
              record for this relationship.
            </p>
          </div>
          <Badge variant="secondary" className="text-xs font-normal">
            {offers.length} {offers.length === 1 ? "offer" : "offers"}
          </Badge>
        </div>

        {offers.length === 0 ? (
          <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground leading-relaxed">
            No contracted pricing offers on record for this relationship. Orders
            will use negotiated unit prices entered at order time.
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {offers.map((offer) => (
              <div
                key={offer.id}
                className="rounded-lg border bg-card p-4 shadow-xs space-y-2.5 text-sm"
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    {offer.preferred && (
                      <Badge
                        variant="outline"
                        className="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 text-[10px] py-0 font-medium"
                      >
                        Preferred offer
                      </Badge>
                    )}
                    <Badge
                      variant={offer.active ? "success" : "secondary"}
                      className="text-[10px] py-0"
                    >
                      {offer.active ? "Active" : "Inactive"}
                    </Badge>
                  </div>
                  {mayViewCommercialCost && offer.unit_price != null && (
                    <span className="tabular-nums font-bold text-foreground text-sm">
                      {currencyMap.get(offer.currency_id ?? 0) ?? ""}{" "}
                      {Number(offer.unit_price).toLocaleString("en-US", {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 4,
                      })}
                    </span>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground pt-1.5 border-t">
                  <div>
                    <span className="block font-medium text-foreground">
                      Min quantity:
                    </span>
                    <span className="tabular-nums">
                      {offer.minimum_order_qty
                        ? Number(offer.minimum_order_qty).toLocaleString(
                            "en-US",
                          )
                        : "None"}
                    </span>
                  </div>
                  <div>
                    <span className="block font-medium text-foreground">
                      Lead time:
                    </span>
                    <span>
                      {offer.lead_time_days != null
                        ? `${offer.lead_time_days} days`
                        : "Account default"}
                    </span>
                  </div>
                  {(offer.valid_from || offer.valid_to) && (
                    <div className="col-span-2 text-[11px] pt-0.5">
                      <span className="text-muted-foreground">Validity: </span>
                      <span className="font-medium text-foreground">
                        {offer.valid_from ?? "Inception"}
                      </span>{" "}
                      to{" "}
                      <span className="font-medium text-foreground">
                        {offer.valid_to ?? "Open"}
                      </span>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Quick Print layout */}
      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout
            title="ITEM SUPPLIER RELATIONSHIP"
            printedAt={new Date()}
            sections={printSections}
          />
        </div>
      </div>
    </div>
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={itemTitle}
        description={supplierTitle}
        actions={headerActions}
        size="lg"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="p-5 pb-8">{detailBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="detail" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title={itemTitle}
          description={supplierTitle}
          breadcrumbs={[
            { label: "Item suppliers", to: "/item-suppliers" },
            { label: String(row.id) },
          ]}
          actions={headerActions}
        />
        {detailBody}
      </div>
    </ScreenTemplate>
  );
}
