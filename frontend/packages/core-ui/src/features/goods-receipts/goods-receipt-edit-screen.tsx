import { useState } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import { useQueryClient, useQueries } from "@tanstack/react-query";
import {
  useGoodsReceiptsShow,
  useGoodsReceiptsUpdate,
  getPurchaseOrdersShowQueryOptions,
} from "@erp/core-api";
import {
  GoodsReceiptForm,
  type GoodsReceiptFormValues,
} from "./goods-receipt-form";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
import { Button } from "../../components/ui/button";
import { getApiErrorMessage } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";

export interface GoodsReceiptEditScreenProps {
  presentation?: Presentation;
}

export function GoodsReceiptEditScreen({
  presentation = "sheet",
}: GoodsReceiptEditScreenProps) {
  const { id } = useParams();
  const navigate = useNavigate();
  const { search } = useLocation();
  const cache = useQueryClient();
  const query = useGoodsReceiptsShow(Number(id));
  const update = useGoodsReceiptsUpdate();
  const [error, setError] = useState<string | null>(null);
  const receipt = query.data?.status === 200 ? query.data.data.data : undefined;
  const orderIds = [
    ...new Set(
      (receipt?.lines ?? [])
        .map((line) => line.purchase_order_id)
        .filter((value): value is number => typeof value === "number"),
    ),
  ];
  const orderQueries = useQueries({
    queries: orderIds.map((orderId) =>
      getPurchaseOrdersShowQueryOptions(orderId, { include: "lines" }),
    ),
  });
  const pinnedOrders = orderQueries.flatMap((result) =>
    result.data?.status === 200 ? [result.data.data.data] : [],
  );

  function wrap(
    content: React.ReactNode,
    title: string = `Edit draft receipt #${id}`,
  ) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title={title}
          description="Correct the arrival details and lines before posting into stock."
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() =>
            navigate({ pathname: `/goods-receipts/${id}`, search })
          }
        >
          <div className="space-y-4 p-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <PageHeader
            title={title}
            backTo={`/goods-receipts/${id}`}
            description="Correct the arrival details and lines before posting into stock."
          />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (query.isLoading)
    return wrap(
      <div className="text-muted-foreground" role="status">
        Loading receipt…
      </div>,
    );
  if (!receipt)
    return wrap(
      <div className="text-destructive">Couldn't load this receipt.</div>,
    );
  if (receipt.status !== "DRAFT")
    return wrap(
      <div className="space-y-3">
        <p>Only draft receipts can be edited.</p>
        <Button
          onClick={() =>
            navigate({ pathname: `/goods-receipts/${id}`, search })
          }
        >
          Back to receipt
        </Button>
      </div>,
    );
  if (orderQueries.some((result) => result.isPending))
    return wrap(
      <div className="text-muted-foreground" role="status">
        Loading saved purchase orders…
      </div>,
    );
  if (orderQueries.some((result) => result.isError))
    return wrap(
      <div className="space-y-3">
        <p role="alert" className="text-destructive">
          Couldn't load the saved purchase orders.
        </p>
        <Button
          variant="outline"
          onClick={() =>
            orderQueries.forEach((result) => void result.refetch())
          }
        >
          Retry
        </Button>
      </div>,
    );

  const arrival = new Date(receipt.physical_arrival_at);
  const defaults: Partial<GoodsReceiptFormValues> = {
    supplier_account_id: String(receipt.supplier_account_id),
    physical_arrival_at: new Date(
      arrival.getTime() - arrival.getTimezoneOffset() * 60000,
    )
      .toISOString()
      .slice(0, 16),
    posting_date: receipt.posting_date.slice(0, 10),
    lines: (receipt.lines ?? []).map((line) => ({
      receipt_line_id: String(line.id),
      purchase_order_line_id: String(line.purchase_order_line_id),
      item_packaging_id: line.item_packaging_id
        ? String(line.item_packaging_id)
        : "",
      location_id: String(line.location_id),
      received_qty: line.received_qty,
      received_uom_id: String(line.received_uom_id),
      provisional_unit_cost: line.provisional_unit_cost ?? "",
      batch_no: line.batch_no ?? "",
      serial_no: line.serial_no ?? "",
    })),
  };

  const formElement = (
    <GoodsReceiptForm
      pinnedOrders={pinnedOrders}
      preserveCommercialSnapshot
      key={receipt.id}
      defaultValues={defaults}
      isSubmitting={update.isPending}
      formError={error}
      onSubmit={(data) => {
        setError(null);
        update.mutate(
          { goodsReceipt: receipt.id, data },
          {
            onSuccess: async (result) => {
              if (result.status === 200) {
                await cache.invalidateQueries();
                navigate({ pathname: `/goods-receipts/${id}`, search });
              }
            },
            onError: (failure) => setError(getApiErrorMessage(failure)),
          },
        );
      }}
    />
  );

  return wrap(formElement);
}
