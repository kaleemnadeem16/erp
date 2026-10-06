import { useState } from "react";
import { Link, useNavigate } from "react-router";
import { useSupplierAccountsIndex } from "@erp/core-api";
import { PageHeader } from "../../components/page/page-header";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { usePermission } from "../../hooks/use-permission";

export function SuppliersListScreen() {
  const navigate = useNavigate();
  const mayManage = usePermission("parties.supplier-account.manage");
  const maySetTerms = usePermission("parties.payment-terms.manage");
  const mayMap = usePermission("accounting.account-mapping.manage");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const query = useSupplierAccountsIndex({
    include: "party",
    "filter[search]": search || undefined,
    sort: "code",
    page,
    per_page: 25,
  });
  const response = query.data?.status === 200 ? query.data.data : undefined;
  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Suppliers"
        description="Register trading partners as suppliers for purchasing."
        primaryAction={
          mayManage && maySetTerms && mayMap
            ? {
                label: "New supplier",
                onClick: () => navigate("/suppliers/new"),
              }
            : undefined
        }
      />
      <Input
        aria-label="Search suppliers"
        placeholder="Search supplier code or name…"
        value={search}
        onChange={(event) => {
          setSearch(event.target.value);
          setPage(1);
        }}
      />
      {query.isLoading ? (
        <p role="status">Loading suppliers…</p>
      ) : query.isError ? (
        <p role="alert">
          Couldn't load suppliers.{" "}
          <Button variant="outline" onClick={() => void query.refetch()}>
            Retry
          </Button>
        </p>
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {response?.data.map((supplier) => (
            <article
              key={supplier.id}
              className="space-y-2 rounded-lg border bg-card p-4"
            >
              <h2 className="font-semibold">
                {mayManage ? (
                  <Link
                    className="text-primary underline"
                    to={`/suppliers/${supplier.id}/edit`}
                  >
                    {supplier.code} — {supplier.party?.name}
                  </Link>
                ) : (
                  `${supplier.code} — ${supplier.party?.name ?? ""}`
                )}
              </h2>
              <p className="text-sm text-muted-foreground">
                {supplier.status} · Payment terms: {supplier.payment_terms_days}{" "}
                days · Lead time: {supplier.lead_time_days ?? "—"} days
              </p>
            </article>
          ))}
          {response?.data.length === 0 && (
            <p>No suppliers match this search.</p>
          )}
        </div>
      )}
      <div className="flex items-center justify-between gap-2">
        <Button
          variant="outline"
          disabled={page <= 1}
          onClick={() => setPage(page - 1)}
        >
          Previous
        </Button>
        <span className="text-sm">
          Page {page} of {response?.meta.last_page ?? 1}
        </span>
        <Button
          variant="outline"
          disabled={!response || page >= response.meta.last_page}
          onClick={() => setPage(page + 1)}
        >
          Next
        </Button>
      </div>
    </div>
  );
}
