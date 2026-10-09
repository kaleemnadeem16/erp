import { useMemo, useState } from "react";
import { useParams, useNavigate, Link, useLocation } from "react-router";
import { Archive, ArchiveRestore, Pencil, ArrowLeft } from "lucide-react";
import {
  useChartOfAccountsIndex,
  useFormulationsIndex,
  useItemsShow,
  useItemsUpdate,
  useUomsShow,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
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
import type { Presentation } from "../../lib/presentation";
import { ItemCustomFieldDisplay } from "../custom-fields/custom-field-inputs";
import { ItemPurchasingPanel } from "./item-purchasing-panel";
import { ItemPackageTrackingCard } from "../package-stock/item-package-tracking-card";
import { ItemQuantityUnitsListScreen } from "../item-quantity-units/item-quantity-units-list-screen";
import { usePermission } from "../../hooks/use-permission";
import { useRecipeAccess } from "../../hooks/use-recipe-access";
import { ItemStatusBadge } from "./items-columns";

export interface ItemDetailScreenProps {
  presentation?: Presentation;
}

export function ItemDetailScreen({
  presentation = "page",
}: ItemDetailScreenProps) {
  const mayManage = usePermission("inventory.item.manage");
  const mayViewAccounts = usePermission("accounting.account-mapping.view");
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
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

  const accountsQuery = useChartOfAccountsIndex(
    { per_page: 100, sort: "code" },
    { query: { enabled: Boolean(mayViewAccounts && item) } },
  );
  const accounts =
    accountsQuery.data?.status === 200 ? accountsQuery.data.data.data : [];
  const accountMap = useMemo(
    () => new Map(accounts.map((acc) => [acc.id, `${acc.code} — ${acc.name}`])),
    [accounts],
  );

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

  const handleClose = () => {
    navigate({ pathname: "/items", search });
  };

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
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Loading item…"
          size="xl"
          className="erp-record"
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

  if (itemQuery.isError || !item) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Item not found"
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">Couldn't load this item.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to items
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">Couldn't load this item.</p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate("/items")}
          >
            <ArrowLeft className="h-4 w-4 mr-1.5" />
            Back to items
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const printSections: DetailPrintSection[] = [
    {
      title: "General Specification",
      fields: [
        { label: "Code", value: item.code },
        { label: "Name", value: item.name },
        { label: "Type", value: item.type },
        { label: "Category", value: item.category?.name ?? "—" },
        { label: "Status", value: item.status },
        { label: "Description", value: item.description ?? "—" },
      ],
    },
    {
      title: "Stock & Valuation Policy",
      fields: [
        {
          label: "Stock unit of measure",
          value: uom ? `${uom.name} (${uom.code})` : "—",
        },
        {
          label: "Stock quantity increment",
          value: item.quantity_precision ?? "Unrestricted",
        },
        { label: "Tracking", value: item.tracking },
        { label: "Valuation method", value: item.valuation_method },
        { label: "AVCO pool scope", value: item.avco_pool_scope ?? "—" },
        { label: "Stocked", value: item.is_stocked ? "Yes" : "No" },
        { label: "Purchasable", value: item.is_purchasable ? "Yes" : "No" },
        { label: "Sellable", value: item.is_sellable ? "Yes" : "No" },
      ],
    },
    ...(mayViewAccounts
      ? [
          {
            title: "General Ledger Mappings",
            fields: [
              {
                label: "Income account",
                value: item.income_account_id
                  ? (accountMap.get(item.income_account_id) ??
                    `Account #${item.income_account_id}`)
                  : "Inheriting category default",
              },
              {
                label: "Expense account",
                value: item.expense_account_id
                  ? (accountMap.get(item.expense_account_id) ??
                    `Account #${item.expense_account_id}`)
                  : "Inheriting category default",
              },
              {
                label: "Inventory account",
                value: item.inventory_account_id
                  ? (accountMap.get(item.inventory_account_id) ??
                    `Account #${item.inventory_account_id}`)
                  : "Inheriting category default",
              },
            ],
          },
        ]
      : []),
  ];

  const headerActions = (
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
              <Archive className="h-4 w-4 mr-1.5" />
              Archive
            </>
          ) : (
            <>
              <ArchiveRestore className="h-4 w-4 mr-1.5" />
              Activate
            </>
          )}
        </Button>
      )}
      {mayManage && (
        <Button asChild size="sm">
          <Link to={{ pathname: `/items/${item.id}/edit`, search }}>
            <Pencil className="h-4 w-4 mr-1.5" />
            Edit
          </Link>
        </Button>
      )}
    </>
  );

  const detailBody = (
    <div className="space-y-4">
      {statusError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive font-medium">
          {statusError}
        </div>
      )}

      <DetailPageLayout>
        <DetailSection
          title="General specification"
          description="Catalog identity, item classification, and system status."
        >
          <DetailField label="Code">
            <span className="font-mono text-xs font-semibold">
              {item.code}
            </span>
          </DetailField>
          <DetailField label="Name">
            <span className="font-medium">{item.name}</span>
          </DetailField>
          <DetailField label="Type">
            <span className="capitalize">
              {item.type.toLowerCase().replace("_", " ")}
            </span>
          </DetailField>
          <DetailField label="Category">
            {item.category?.name ?? "—"}
          </DetailField>
          <DetailField label="Status">
            <ItemStatusBadge status={item.status} />
          </DetailField>
          <DetailField label="Description" fullWidth>
            {item.description || (
              <span className="text-muted-foreground italic text-xs">
                No description provided
              </span>
            )}
          </DetailField>
        </DetailSection>

        <DetailSection
          title="Stock &amp; valuation policy"
          description="Inventory unit, tracking rules, and cost calculation method."
        >
          <DetailField label="Stock unit of measure">
            {uom ? (
              `${uom.name} (${uom.code})`
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
          <DetailField label="Stock quantity increment">
            {item.quantity_precision ?? "Unrestricted"}
          </DetailField>
          <DetailField label="Tracking">{item.tracking}</DetailField>
          <DetailField label="Valuation method">
            {item.valuation_method}
          </DetailField>
          <DetailField label="AVCO pool scope">
            {item.avco_pool_scope ?? "—"}
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

        {mayViewAccounts && (
          <DetailSection
            title="General ledger mappings"
            description="Account assignments for automatic journal postings."
          >
            <DetailField label="Income account">
              {item.income_account_id ? (
                (accountMap.get(item.income_account_id) ??
                `Account #${item.income_account_id}`)
              ) : (
                <span className="text-muted-foreground italic text-xs">
                  Inheriting category default
                </span>
              )}
            </DetailField>
            <DetailField label="Expense account">
              {item.expense_account_id ? (
                (accountMap.get(item.expense_account_id) ??
                `Account #${item.expense_account_id}`)
              ) : (
                <span className="text-muted-foreground italic text-xs">
                  Inheriting category default
                </span>
              )}
            </DetailField>
            <DetailField label="Inventory account" fullWidth>
              {item.inventory_account_id ? (
                (accountMap.get(item.inventory_account_id) ??
                `Account #${item.inventory_account_id}`)
              ) : (
                <span className="text-muted-foreground italic text-xs">
                  Inheriting category default
                </span>
              )}
            </DetailField>
          </DetailSection>
        )}

        <ItemCustomFieldDisplay value={item.custom_fields} />

        {mayReadRecipes && formulations.length > 0 && (
          <DetailSection
            title="Formulations"
            description="Recipes that produce this item. Customer-specific formulas override company defaults when resolving estimates."
            fullWidth
          >
            <div className="col-span-full space-y-2">
              {formulations.map((formulation) => (
                <Link
                  key={formulation.id}
                  to={`/formulations/${formulation.id}/edit`}
                  className="flex items-center justify-between gap-3 rounded-md border border-border bg-card p-3 hover:bg-accent transition-colors"
                >
                  <div>
                    <span className="font-medium ui-order-link text-sm">
                      {formulation.code} — {formulation.name}
                    </span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      {formulation.customer?.code ??
                        "Company default formula"}
                    </span>
                  </div>
                  <ItemStatusBadge status={formulation.status} />
                </Link>
              ))}
            </div>
          </DetailSection>
        )}

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

        <DetailSection
          title="Packages and supplier offers"
          fullWidth
        >
          <div className="col-span-full">
            <ItemPurchasingPanel
              itemId={item.id}
              itemLabel={`${item.code} — ${item.name}`}
              stockUomId={item.stock_uom_id}
              stockUnit={uom?.code}
            />
          </div>
        </DetailSection>

        <DetailSection title="Package tracking" fullWidth>
          <div className="col-span-full">
            <ItemPackageTrackingCard
              itemId={item.id}
              itemLabel={`${item.code} — ${item.name}`}
              unit={uom?.code ?? ""}
              isStocked={item.is_stocked}
            />
          </div>
        </DetailSection>
      </DetailPageLayout>

      {/* Quick Print layout (always contains all sections for print) */}
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

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={item.name}
        description={item.code}
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
          title={item.name}
          description={item.code}
          breadcrumbs={[{ label: "Items", to: "/items" }, { label: item.code }]}
          backTo="/items"
          actions={headerActions}
        />
        {detailBody}
      </div>
    </ScreenTemplate>
  );
}
