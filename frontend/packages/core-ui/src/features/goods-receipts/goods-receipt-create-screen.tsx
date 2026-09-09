import { useState } from "react";
import { useNavigate } from "react-router";
import { useGoodsReceiptsStore } from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { getApiErrorMessage } from "../../lib/api-errors";
import { GoodsReceiptForm } from "./goods-receipt-form";

export function GoodsReceiptCreateScreen() {
  const navigate = useNavigate();
  const create = useGoodsReceiptsStore();
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New goods receipt"
        description="Record what physically arrived before posting it into stock."
        breadcrumbs={[
          { label: "Goods Receipts", to: "/goods-receipts" },
          { label: "New" },
        ]}
      />
      <GoodsReceiptForm
        isSubmitting={create.isPending}
        formError={error}
        onSubmit={(data) => {
          setError(null);
          create.mutate(
            { data },
            {
              onSuccess: (response) => {
                if (response.status === 201)
                  navigate(`/goods-receipts/${response.data.data.id}`);
              },
              onError: (failure) => setError(getApiErrorMessage(failure)),
            },
          );
        }}
      />
    </div>
  );
}
