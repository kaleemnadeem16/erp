import { useState } from "react";
import {
  Archive,
  ArrowLeft,
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  Copy,
  ExternalLink,
  Package,
  Pencil,
  Power,
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
    { query: { enabled: mayViewCommercialCost } },
  );
  const currencies =
    currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyMap = new Map(currencies.map((c) => [c.id, c.code]));

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
          <div className="space-y-4 p-4">
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
          <div className="space-y-4 p-4">
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
  const purchaseUomLabel = row.purchase_uom
    ? `${row.purchase_uom.name} (${row.purchase_uom.code})`
    : "Use item stock unit";

  const printSections: DetailPrintSection[] = [
    {
      title: "Relationship",
      fields: [
        {
          label: "Item",
          value: `${row.item?.code ?? row.item_id} — ${itemTitle}`,
        },
        { label: "Supplier", value: supplierTitle },
        {
          label: "Supplier item code",
          value: row.supplier_item_code ?? "None",
        },
        {
          label: "Priority",
          value:
            row.priority === 1
              ? "#1 Preferred"
              : row.priority
                ? `#${row.priority}`
                : "None",
        },
        { label: "Status", value: row.active ? "Active" : "Inactive" },
      ],
    },
    {
      title: "Sourcing terms",
      fields: [
        {
          label: "Lead time override",
          value:
            row.lead_time_days == null
              ? "Supplier default"
              : `${row.lead_time_days} days`,
        },
        {
          label: "Resolved lead time",
          value: `${row.resolved_lead_time_days ?? "—"} days`,
        },
        {
          label: "Minimum order quantity",
          value: row.minimum_order_qty
            ? `${row.minimum_order_qty} ${row.purchase_uom?.code ?? ""}`
            : "None",
        },
        { label: "Purchase unit", value: purchaseUomLabel },
      ],
    },
    {
      title: "Record Information",
      fields: [
        { label: "Relationship ID", value: String(row.id) },
        { label: "Created at", value: formatDate(row.created_at) ?? "" },
        { label: "Updated at", value: formatDate(row.updated_at) ?? "" },
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
              Edit terms
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
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {actionError}
        </div>
      )}

      {!row.active && (
        <div className="flex items-center gap-3 rounded-lg border border-muted bg-muted/40 p-3 text-sm text-muted-foreground">
          <Archive className="h-4 w-4 shrink-0 text-muted-foreground" />
          <span>
            This relationship is currently <strong>archived (inactive)</strong>.
            It is preserved for purchase history but omitted from new order
            selection.
          </span>
        </div>
      )}

      <DetailPageLayout equalHeight>
        <DetailSection title="Relationship">
          <DetailField label="Item">
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold text-foreground">{itemTitle}</span>
              {row.item_id && (
                <Link
                  to={`/items/${row.item_id}`}
                  className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                >
                  View item
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
            {row.item?.code && (
              <span className="text-xs font-mono text-muted-foreground block mt-0.5">
                {row.item.code}
              </span>
            )}
          </DetailField>

          <DetailField label="Supplier">
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold text-foreground">
                {supplierTitle}
              </span>
              {row.supplier_account_id && (
                <Link
                  to={`/suppliers/${row.supplier_account_id}`}
                  className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                >
                  View supplier
                  <ExternalLink className="h-3 w-3" />
                </Link>
              )}
            </div>
            {row.supplier_account?.code && (
              <span className="text-xs font-mono text-muted-foreground block mt-0.5">
                Code: {row.supplier_account.code}
              </span>
            )}
          </DetailField>

          <DetailField label="Supplier item code (SKU)">
            {row.supplier_item_code ? (
              <div className="flex items-center gap-2">
                <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs font-semibold text-foreground">
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
              <span className="text-muted-foreground italic">
                None specified
              </span>
            )}
          </DetailField>

          <DetailField label="Priority">
            {row.priority === 1 ? (
              <Badge
                variant="outline"
                className="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 font-semibold gap-1 inline-flex items-center"
              >
                <Star className="h-3 w-3 fill-emerald-500 text-emerald-500" />
                #1 (Preferred supplier)
              </Badge>
            ) : row.priority != null ? (
              <span className="font-medium text-foreground">
                #{row.priority}
              </span>
            ) : (
              <span className="text-muted-foreground italic">
                No priority set
              </span>
            )}
          </DetailField>

          <DetailField label="Relationship status">
            <ItemSupplierStatusBadge active={row.active} />
          </DetailField>
        </DetailSection>

        <DetailSection title="Sourcing terms">
          <DetailField label="Purchase unit">
            <div className="font-medium text-foreground">
              {purchaseUomLabel}
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Unit of measure used when creating purchase orders for this
              supplier.
            </p>
          </DetailField>

          <DetailField label="Lead time override">
            {row.lead_time_days != null ? (
              <div className="inline-flex items-center gap-1.5 font-medium text-foreground">
                <Clock className="h-4 w-4 text-primary" />
                <span>{row.lead_time_days} days (custom override)</span>
              </div>
            ) : (
              <span className="text-muted-foreground italic">
                Uses supplier account default
              </span>
            )}
          </DetailField>

          <DetailField label="Resolved lead time">
            <div className="inline-flex items-center gap-1.5 font-medium text-foreground">
              <Calendar className="h-4 w-4 text-muted-foreground" />
              <span>
                {row.resolved_lead_time_days != null
                  ? `${row.resolved_lead_time_days} days expected`
                  : "Not specified"}
              </span>
            </div>
          </DetailField>

          <DetailField label="Minimum order quantity (MOQ)">
            {row.minimum_order_qty ? (
              <span className="tabular-nums font-semibold text-foreground">
                {Number(row.minimum_order_qty).toLocaleString("en-US", {
                  maximumFractionDigits: 4,
                })}
                {row.purchase_uom ? ` ${row.purchase_uom.code}` : ""}
              </span>
            ) : (
              <span className="text-muted-foreground italic">
                No minimum order quantity
              </span>
            )}
          </DetailField>
        </DetailSection>

        <DetailSection title="Record information">
          <DetailField label="Relationship ID">
            <span className="font-mono text-xs text-muted-foreground">
              #{row.id}
            </span>
          </DetailField>
          <DetailField label="Created at">
            <span className="text-sm text-foreground">
              {formatDateTime(row.created_at)}
            </span>
          </DetailField>
          <DetailField label="Last updated">
            <span className="text-sm text-foreground">
              {formatDateTime(row.updated_at)}
            </span>
          </DetailField>
        </DetailSection>
      </DetailPageLayout>

      {/* Commercial Offers / Pricing section */}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-foreground flex items-center gap-2">
              <Tag className="h-4 w-4 text-primary" />
              Supplier offers & pricing
            </h3>
            <p className="text-xs text-muted-foreground">
              Commercial quotations and tiered pricing on file for this item and
              supplier.
            </p>
          </div>
          <Badge variant="secondary" className="text-xs font-normal">
            {offers.length} {offers.length === 1 ? "offer" : "offers"}
          </Badge>
        </div>

        {offers.length === 0 ? (
          <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
            No active supplier quotes or contracted prices recorded yet.
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {offers.map((offer) => (
              <div
                key={offer.id}
                className="rounded-lg border bg-card p-3.5 shadow-xs space-y-2 text-sm"
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    {offer.preferred && (
                      <Badge
                        variant="outline"
                        className="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 text-[10px] py-0"
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
                    <span className="tabular-nums font-semibold text-foreground">
                      {currencyMap.get(offer.currency_id ?? 0) ?? ""}{" "}
                      {Number(offer.unit_price).toLocaleString("en-US", {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 4,
                      })}
                    </span>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground pt-1 border-t">
                  <div>
                    <span className="block font-medium text-foreground">
                      Min order:
                    </span>
                    <span>
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
                        : "Default"}
                    </span>
                  </div>
                  {(offer.valid_from || offer.valid_to) && (
                    <div className="col-span-2 text-[11px]">
                      <span>Valid: </span>
                      <span>{offer.valid_from ?? "—"}</span> to{" "}
                      <span>{offer.valid_to ?? "Open"}</span>
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
        <div className="p-4 pb-8">{detailBody}</div>
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
