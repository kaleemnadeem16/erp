import { usePermission } from "../../hooks/use-permission";
import { customFieldValues } from "../custom-fields/custom-field-inputs";
import { useState } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import type { UseFormReturn } from "react-hook-form";
import {
  useItemsShow,
  useItemsUpdate,
  type AvcoPoolScope,
  type ItemType,
  type ValuationMethod,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
import { applyApiErrorsToForm } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";
import {
  DEFAULT_OPTION,
  ITEM_FORM_FIELDS,
  ItemForm,
  toItemPayload,
  type ItemFormValues,
} from "./item-form";

export interface ItemEditScreenProps {
  presentation?: Presentation;
}

export function ItemEditScreen({ presentation = "page" }: ItemEditScreenProps) {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayManageMappings = usePermission("accounting.account-mapping.manage");
  const itemId = Number(params.id);
  const [formError, setFormError] = useState<string | null>(null);

  const itemQuery = useItemsShow(itemId);
  const item =
    itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;

  const updateItem = useItemsUpdate();

  const handleClose = () => {
    navigate({ pathname: `/items/${itemId}`, search });
  };

  function handleSubmit(
    values: ItemFormValues,
    form: UseFormReturn<ItemFormValues>,
  ) {
    setFormError(null);
    updateItem.mutate(
      {
        item: itemId,
        data: {
          ...toItemPayload(values, mayManageMappings),
          // UpdateItemRequest.valuation_method doesn't accept null (unlike StoreItemRequest's) —
          // the form only offers the "category default" sentinel in create mode, so this is
          // always a concrete ValuationMethod here; narrow the type to match.
          valuation_method:
            values.valuation_method === DEFAULT_OPTION
              ? undefined
              : values.valuation_method,
        },
      },
      {
        onSuccess: (result) => {
          if (result.status === 200) {
            navigate({ pathname: `/items/${result.data.data.id}`, search });
          }
        },
        onError: (error) => {
          setFormError(
            applyApiErrorsToForm(error, form.setError, ITEM_FORM_FIELDS),
          );
        },
      },
    );
  }

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
            <Skeleton className="h-64 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-64 w-full" />
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
          onClose={() => navigate({ pathname: "/items", search })}
        >
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">Couldn't load this item.</p>
            <Button
              variant="outline"
              size="sm"
              onClick={() => navigate({ pathname: "/items", search })}
            >
              Back to items
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">Couldn't load this item.</p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate({ pathname: "/items", search })}
          >
            Back to items
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const defaultValues: Partial<ItemFormValues> = {
    income_account_id: item.income_account_id
      ? String(item.income_account_id)
      : "",
    expense_account_id: item.expense_account_id
      ? String(item.expense_account_id)
      : "",
    inventory_account_id: item.inventory_account_id
      ? String(item.inventory_account_id)
      : "",
    custom_fields: customFieldValues(item.custom_fields),
    code: item.code,
    name: item.name,
    description: item.description ?? "",
    type: item.type as ItemType,
    stock_uom_id: String(item.stock_uom_id),
    quantity_precision: item.quantity_precision ?? "",
    category_id: String(item.category_id),
    is_stocked: item.is_stocked,
    is_purchasable: item.is_purchasable,
    is_sellable: item.is_sellable,
    valuation_method: item.valuation_method as ValuationMethod,
    avco_pool_scope:
      (item.avco_pool_scope as AvcoPoolScope | null) ?? DEFAULT_OPTION,
    tracking: item.tracking as ItemFormValues["tracking"],
    status: item.status as ItemFormValues["status"],
  };

  const formBody = (
    <ItemForm
      mode="edit"
      defaultValues={defaultValues}
      onSubmit={handleSubmit}
      onCancel={handleClose}
      isSubmitting={updateItem.isPending}
      formError={formError}
    />
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={`Edit ${item.name}`}
        description={item.code}
        size="xl"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{formBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title={`Edit ${item.name}`}
          description={item.code}
          breadcrumbs={[
            { label: "Items", to: "/items" },
            { label: item.code, to: `/items/${item.id}` },
            { label: "Edit" },
          ]}
          backTo={`/items/${item.id}`}
        />
        {formBody}
      </div>
    </ScreenTemplate>
  );
}
