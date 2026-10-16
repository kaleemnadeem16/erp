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
import { formatDecimal } from "../../lib/decimal";

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
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 pb-4">
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
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 pb-4">
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
    <div className="space-y-4">
      {actionError && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive font-medium">
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

      {/* 4 EVEN CARDS GRID (2x2 on desktop with equal height) + Offers */}
      <DetailPageLayout equalHeight>
        {/* BOX 1: Item Details */}
        <DetailSection
          title="Item details"
          description="Catalog item specification, inventory unit, and tracking properties."
        >
          <DetailField label="Item name" fullWidth>
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold">{itemTitle}</span>
              {row.item_id && (
                <Link
                  to={`/items/${row.item_id}`}
                  className="ui-order-link inline-flex items-center gap-1 text-xs shrink-0"
                >
                  View item
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
          </DetailField>

          <DetailField label="Item code">
            <span className="font-mono text-xs font-medium">
              {row.item?.code ?? String(row.item_id)}
            </span>
          </DetailField>

          <DetailField label="Item category">
            {row.item?.category?.name ?? "Uncategorized"}
          </DetailField>

          <DetailField label="Inventory stock unit">
            {stockUom ? `${stockUom.name} (${stockUom.code})` : "Stock unit"}
          </DetailField>

          <DetailField label="Item classification">
            <span className="capitalize">
              {row.item?.type
                ? `${row.item.type.toLowerCase().replace(/_/g, " ")}`
                : "Standard"}
            </span>
          </DetailField>

          <DetailField label="Inventory tracking">
            <span className="capitalize">
              {row.item?.tracking
                ? `${row.item.tracking.toLowerCase()} tracking`
                : "Standard quantity"}
            </span>
          </DetailField>

          <DetailField label="Valuation method">
            {row.item?.valuation_method ?? "AVCO"}
          </DetailField>
        </DetailSection>

        {/* BOX 2: Supplier Account Details */}
        <DetailSection
          title="Supplier details"
          description="Vendor commercial profile, standard lead times, and terms."
        >
          <DetailField label="Supplier name" fullWidth>
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold">
                {row.supplier_account?.party?.name ?? supplierTitle}
              </span>
              {row.supplier_account_id && (
                <Link
                  to={`/suppliers/${row.supplier_account_id}`}
                  className="ui-order-link inline-flex items-center gap-1 text-xs shrink-0"
                >
                  View supplier
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
          </DetailField>

          <DetailField label="Account code">
            <span className="font-mono text-xs font-medium">
              {row.supplier_account?.code ?? String(row.supplier_account_id)}
            </span>
          </DetailField>

          <DetailField label="Supplier status">
            <span className="capitalize">
              {row.supplier_account?.status
                ? row.supplier_account.status.toLowerCase()
                : "Active"}
            </span>
          </DetailField>

          <DetailField label="Payment terms">
            {row.supplier_account?.payment_terms_days != null
              ? `${row.supplier_account.payment_terms_days} days net`
              : "Standard terms"}
          </DetailField>

          <DetailField label="Account default lead time">
            {row.supplier_account?.lead_time_days != null
              ? `${row.supplier_account.lead_time_days} days`
              : "Not specified"}
          </DetailField>

          <DetailField label="Default currency">
            {defaultCurrencyCode ? `${defaultCurrencyCode}` : "Base currency"}
          </DetailField>

          <DetailField label="Vendor account ID">
            <span className="text-xs font-mono text-muted-foreground">
              #{row.supplier_account_id}
            </span>
          </DetailField>
        </DetailSection>

        {/* BOX 3: Sourcing Terms & Procurement Parameters */}
        <DetailSection
          title="Sourcing terms"
          description="Supplier catalog SKU, purchase units, priority ranking, and order quantities."
        >
          <DetailField label="Supplier item code (SKU)" fullWidth>
            {row.supplier_item_code ? (
              <div className="flex items-center gap-2">
                <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs font-semibold">
                  {row.supplier_item_code}
                </code>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  onClick={copySku}
                  title="Copy supplier SKU"
                >
                  {copiedSku ? (
                    <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                  ) : (
                    <Copy className="h-3.5 w-3.5 text-muted-foreground" />
                  )}
                </Button>
              </div>
            ) : (
              <span className="text-muted-foreground italic text-xs">
                No vendor SKU specified
              </span>
            )}
          </DetailField>

          <DetailField label="Sourcing priority">
            {row.priority === 1 ? (
              <Badge
                variant="outline"
                className="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 font-semibold gap-1 inline-flex items-center"
              >
                <Star className="h-3.5 w-3.5 fill-emerald-500 text-emerald-500" />
                #1 Preferred supplier
              </Badge>
            ) : row.priority != null ? (
              <span className="font-medium">#{row.priority} Alternative</span>
            ) : (
              <span className="text-muted-foreground italic text-xs">
                Unranked alternative
              </span>
            )}
          </DetailField>

          <DetailField label="Purchase unit">{purchaseUomLabel}</DetailField>

          <DetailField label="Lead time override">
            {row.lead_time_days != null ? (
              `${row.lead_time_days} days (Custom override)`
            ) : (
              <span className="text-muted-foreground text-xs italic">
                Inheriting account default
              </span>
            )}
          </DetailField>

          <DetailField label="Resolved expected lead time">
            {row.resolved_lead_time_days != null
              ? `${row.resolved_lead_time_days} days expected`
              : "Not specified"}
          </DetailField>

          <DetailField label="Minimum order quantity (MOQ)">
            {row.minimum_order_qty ? (
              <span className="tabular-nums font-semibold">
                {formatDecimal(row.minimum_order_qty)}
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

          <DetailField label="Relationship status">
            <ItemSupplierStatusBadge active={row.active} />
          </DetailField>
        </DetailSection>

        {/* BOX 4: Record Governance & Audit */}
        <DetailSection
          title="Record governance"
          description="System identifiers, audit timestamps, and automated replenishment policy."
        >
          <DetailField label="Relationship ID">
            <span className="font-mono text-xs font-semibold text-muted-foreground bg-muted/60 px-2 py-0.5 rounded">
              #{row.id}
            </span>
          </DetailField>

          <DetailField label="Designation role">
            {row.priority === 1
              ? "Primary preferred source"
              : "Approved alternative"}
          </DetailField>

          <DetailField label="Created at">
            {formatDateTime(row.created_at)}
          </DetailField>

          <DetailField label="Last modified">
            {formatDateTime(row.updated_at)}
          </DetailField>

          <DetailField label="Procurement policy summary" fullWidth>
            <p className="text-xs text-muted-foreground leading-relaxed bg-muted/30 p-2.5 rounded border border-border/50">
              {row.priority === 1
                ? "Automated reorder triggers and manual purchase orders default to this supplier. Changing the preferred supplier for this item will reassign priority #1."
                : "This supplier is recorded as an approved alternative. It can be selected manually when raising purchase orders or when the primary vendor is unavailable."}
            </p>
          </DetailField>
        </DetailSection>

        {/* BOX 5: Supplier Offers & Contracted Pricing */}
        <DetailSection
          title="Supplier offers & contracted pricing"
          description="Quotations, contracted tier pricing, and active price validity on record for this relationship."
          fullWidth
        >
          <div className="sm:col-span-2 space-y-3">
            {offers.length === 0 ? (
              <div className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground leading-relaxed">
                No contracted pricing offers on record for this relationship.
                Orders will use negotiated unit prices entered at order time.
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {offers.map((offer) => (
                  <div
                    key={offer.id}
                    className="rounded-lg border bg-card p-3 shadow-xs space-y-2 text-sm"
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
                        <span className="tabular-nums font-semibold text-sm">
                          {currencyMap.get(offer.currency_id ?? 0) ?? ""}{" "}
                          {formatDecimal(String(offer.unit_price), { minFraction: 2 })}
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
                            ? formatDecimal(offer.minimum_order_qty)
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
                          <span className="text-muted-foreground">
                            Validity:{" "}
                          </span>
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
          </div>
        </DetailSection>
      </DetailPageLayout>

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
        size="xl"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{detailBody}</div>
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
