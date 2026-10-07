import { ItemCustomFieldDisplay } from "../custom-fields/custom-field-inputs";
import { useState } from "react";
import { useParams, useNavigate, Link } from "react-router";
import { Archive, ArchiveRestore, Pencil, ArrowLeft } from "lucide-react";
import {
  useFormulationsIndex,
  useItemsShow,
  useItemsUpdate,
  useUomsShow,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";
import { Skeleton } from "../../components/ui/skeleton";
import { PageHeader } from "../../components/page/page-header";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPrintLayout,
  PrintTrigger,
  useQuickPrint,
  type DetailPrintSection,
} from "../../components/print";
import { getApiErrorMessage } from "../../lib/api-errors";
import { ItemPurchasingPanel } from "./item-purchasing-panel";
import { ItemPackageTrackingCard } from "../package-stock/item-package-tracking-card";
import { ItemQuantityUnitsListScreen } from "../item-quantity-units/item-quantity-units-list-screen";
import { usePermission } from "../../hooks/use-permission";
import { useRecipeAccess } from "../../hooks/use-recipe-access";

export function ItemDetailScreen() {
  const mayManage = usePermission("inventory.item.manage");
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const itemId = Number(params.id);
  const [statusError, setStatusError] = useState<string | null>(null);

  const itemQuery = useItemsShow(itemId, { include: "category" });
  const item =
    itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;

  const uomQuery = useUomsShow(item?.stock_uom_id ?? 0, {
    query: { enabled: !!item },
  });
  const uom =
    uomQuery.data?.status === 200 ? uomQuery.data.data.data : undefined;
  // Recipes are readable only with recipe access (ADR 0086); without it the card is simply absent, and no request is made.
  const mayReadRecipes = useRecipeAccess();
  const formulationsQuery = useFormulationsIndex(
    {
      "filter[output_item_id]": itemId,
      include: "customer",
      per_page: 100,
    },
    { query: { enabled: mayReadRecipes } },
  );
  const formulations =
    formulationsQuery.data?.status === 200
      ? formulationsQuery.data.data.data
      : [];

  const updateStatus = useItemsUpdate();

  function handleToggleStatus() {
    if (!item) return;
    setStatusError(null);
    const nextStatus = item.status === "ACTIVE" ? "ARCHIVED" : "ACTIVE";
    updateStatus.mutate(
      { item: itemId, data: { status: nextStatus } },
      {
        onSuccess: () => itemQuery.refetch(),
        onError: (error) => setStatusError(getApiErrorMessage(error)),
      },
    );
  }

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: item ? `Item ${item.code}` : "Item",
  });

  if (itemQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (itemQuery.isError || !item) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this item.</p>
        <Button variant="outline" size="sm" onClick={() => navigate("/items")}>
          <ArrowLeft className="h-4 w-4" />
          Back to items
        </Button>
      </div>
    );
  }

  const printSections: DetailPrintSection[] = [
    {
      title: "General",
      fields: [
        { label: "Code", value: item.code },
        { label: "Name", value: item.name },
        { label: "Type", value: item.type },
        { label: "Category", value: item.category?.name ?? "" },
        { label: "Status", value: item.status },
        { label: "Description", value: item.description ?? "" },
      ],
    },
    {
      title: "Stock & valuation",
      fields: [
        {
          label: "Stock unit of measure",
          value: uom ? `${uom.name} (${uom.code})` : "",
        },
        {
          label: "Stock quantity increment",
          value: item.quantity_precision ?? "Unrestricted",
        },
        { label: "Tracking", value: item.tracking },
        { label: "Valuation method", value: item.valuation_method },
        { label: "AVCO pool scope", value: item.avco_pool_scope ?? "" },
        { label: "Stocked", value: item.is_stocked ? "Yes" : "No" },
        { label: "Purchasable", value: item.is_purchasable ? "Yes" : "No" },
        { label: "Sellable", value: item.is_sellable ? "Yes" : "No" },
      ],
    },
  ];

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={item.name}
        description={item.code}
        breadcrumbs={[{ label: "Items", to: "/items" }, { label: item.code }]}
        backTo="/items"
        actions={
          <>
            <PrintTrigger onPrint={() => print()} />
            {mayManage && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleToggleStatus}
                disabled={updateStatus.isPending}
              >
                {item.status === "ACTIVE" ? (
                  <>
                    <Archive className="h-4 w-4" />
                    Archive
                  </>
                ) : (
                  <>
                    <ArchiveRestore className="h-4 w-4" />
                    Activate
                  </>
                )}
              </Button>
            )}
            {mayManage && (
              <Button asChild size="sm">
                <Link to={`/items/${item.id}/edit`}>
                  <Pencil className="h-4 w-4" />
                  Edit
                </Link>
              </Button>
            )}
          </>
        }
      />

      {statusError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {statusError}
        </div>
      )}

      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{item.code}</DetailField>
          <DetailField label="Name">{item.name}</DetailField>
          <DetailField label="Type">
            <span className="capitalize">
              {item.type.toLowerCase().replace("_", " ")}
            </span>
          </DetailField>
          <DetailField label="Category">{item.category?.name}</DetailField>
          <DetailField label="Status">
            <Badge variant={item.status === "ACTIVE" ? "success" : "secondary"}>
              {item.status}
            </Badge>
          </DetailField>
          <DetailField label="Description" fullWidth>
            {item.description}
          </DetailField>
        </DetailSection>

        <DetailSection title="Stock &amp; valuation">
          <DetailField label="Stock unit of measure">
            {uom ? `${uom.name} (${uom.code})` : undefined}
          </DetailField>
          <DetailField label="Stock quantity increment">
            {item.quantity_precision ?? "Unrestricted"}
          </DetailField>
          <DetailField label="Tracking">{item.tracking}</DetailField>
          <DetailField label="Valuation method">
            {item.valuation_method}
          </DetailField>
          <DetailField label="AVCO pool scope">
            {item.avco_pool_scope}
          </DetailField>
          <DetailField label="Stocked">
            {item.is_stocked ? "Yes" : "No"}
          </DetailField>
          <DetailField label="Purchasable">
            {item.is_purchasable ? "Yes" : "No"}
          </DetailField>
          <DetailField label="Sellable">
            {item.is_sellable ? "Yes" : "No"}
          </DetailField>
        </DetailSection>

        <ItemCustomFieldDisplay value={item.custom_fields} />

        {formulations.length > 0 && (
          <DetailSection
            title="Formulations"
            description="Recipes that produce this item. Customer-specific rows override the company default when resolving an estimate."
            fullWidth
          >
            <div className="space-y-2 sm:col-span-2">
              {formulations.map((formulation) => (
                <Link
                  key={formulation.id}
                  to={`/formulations/${formulation.id}/edit`}
                  className="flex items-center justify-between gap-3 rounded-md border p-3 hover:bg-accent"
                >
                  <span>
                    <span className="font-medium">
                      {formulation.code} — {formulation.name}
                    </span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      {formulation.customer?.code ?? "Company default"}
                    </span>
                  </span>
                  <Badge
                    variant={
                      formulation.status === "ACTIVE" ? "success" : "secondary"
                    }
                  >
                    {formulation.status}
                  </Badge>
                </Link>
              ))}
            </div>
          </DetailSection>
        )}
      </DetailPageLayout>

      <DetailSection
        title="Pack conversions"
        description="Alternate units for this item. These conversions describe quantity, not sealed packages in stock."
        fullWidth
      >
        <div className="col-span-full">
          <ItemQuantityUnitsListScreen
            key={item.id}
            itemId={item.id}
            stockUnit={uom?.code}
          />
        </div>
      </DetailSection>

      <DetailSection title="Packages and supplier offers" fullWidth>
        <div className="col-span-full">
          <ItemPurchasingPanel
            itemId={item.id}
            itemLabel={item.code + " — " + item.name}
            stockUomId={item.stock_uom_id}
            stockUnit={uom?.code}
          />
        </div>
      </DetailSection>

      <DetailSection title="Package tracking" fullWidth>
        <div className="col-span-full">
          <ItemPackageTrackingCard
            itemId={item.id}
            itemLabel={item.code + " — " + item.name}
            unit={uom?.code ?? ""}
            isStocked={item.is_stocked}
          />
        </div>
      </DetailSection>

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout
            title={`ITEM: ${item.code}`}
            printedAt={new Date()}
            sections={printSections}
          />
        </div>
      </div>
    </div>
  );
}
