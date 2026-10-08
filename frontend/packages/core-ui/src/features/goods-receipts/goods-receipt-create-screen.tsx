import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { useGoodsReceiptsStore } from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
import { getApiErrorMessage } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";
import {
  GoodsReceiptForm,
  clearUnfinishedGoodsReceipt,
} from "./goods-receipt-form";

export interface GoodsReceiptCreateScreenProps {
  presentation?: Presentation;
}

export function GoodsReceiptCreateScreen({
  presentation = "sheet",
}: GoodsReceiptCreateScreenProps) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const create = useGoodsReceiptsStore();
  const [error, setError] = useState<string | null>(null);

  const formElement = (
    <GoodsReceiptForm
      isSubmitting={create.isPending}
      formError={error}
      onSubmit={(data) => {
        setError(null);
        create.mutate(
          { data },
          {
            onSuccess: (response) => {
              if (response.status === 201) {
                clearUnfinishedGoodsReceipt();
                navigate({
                  pathname: `/goods-receipts/${response.data.data.id}`,
                  search,
                });
              }
            },
            onError: (failure) => setError(getApiErrorMessage(failure)),
          },
        );
      }}
    />
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title="New goods receipt"
        description="Record what physically arrived before posting it into stock."
        size="xl"
        className="erp-record"
        dismissOnOutsideClick={false}
        onClose={() => navigate({ pathname: "/goods-receipts", search })}
      >
        <div className="space-y-4 p-4 pb-6">{formElement}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title="New goods receipt"
          description="Record what physically arrived before posting it into stock."
          backTo="/goods-receipts"
          breadcrumbs={[
            { label: "Goods Receipts", to: "/goods-receipts" },
            { label: "New" },
          ]}
        />
        {formElement}
      </div>
    </ScreenTemplate>
  );
}
