import { useMemo, useState, type ReactNode } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import {
  useSupplierAccountsShow,
  useSupplierAccountsSitesIndex,
  useSupplierAccountsUpdate,
  useCurrenciesIndex,
  useChartOfAccountsShow,
} from "@erp/core-api";
import {
  ArrowLeft,
  Building2,
  ExternalLink,
  MapPin,
  Package,
  Pencil,
  Plus,
  ShoppingBag,
} from "lucide-react";

import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";
import { Skeleton } from "../../components/ui/skeleton";
import {
  DetailPrintLayout,
  PrintTrigger,
  useQuickPrint,
  type DetailPrintSection,
} from "../../components/print";
import type { Presentation } from "../../lib/presentation";
import { usePermission } from "../../hooks/use-permission";
import { SupplierStatusBadge } from "./suppliers-columns";
import { SupplierSiteDialog } from "../purchase-orders/supplier-site-form";

export interface SupplierDetailScreenProps {
  presentation?: Presentation;
}

export function SupplierDetailScreen({
  presentation = "sheet",
}: SupplierDetailScreenProps) {
  const { id } = useParams<{ id: string }>();
  const supplierId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();

  const mayManage = usePermission("parties.supplier-account.manage");
  const mayManageSites = usePermission("parties.supplier-site.manage");
  const mayViewAccounts = usePermission("accounting.account-mapping.view");

  const [siteOpen, setSiteOpen] = useState(false);

  const query = useSupplierAccountsShow(
    supplierId,
    { include: "party" },
    { query: { enabled: Number.isFinite(supplierId) && supplierId > 0 } }
  );

  const supplier = query.data?.status === 200 ? query.data.data.data : undefined;

  const sitesQuery = useSupplierAccountsSitesIndex(
    supplierId,
    undefined,
    { query: { enabled: !!supplier } }
  );
  const sites = sitesQuery.data?.status === 200 ? sitesQuery.data.data.data : [];

  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: "code" });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyMap = useMemo(
    () => new Map(currencies.map((c) => [c.id, `${c.code} — ${c.name}`])),
    [currencies]
  );

  const accountQuery = useChartOfAccountsShow(
    supplier?.payable_account_id ?? 0,
    undefined,
    { query: { enabled: Boolean(mayViewAccounts && supplier?.payable_account_id) } }
  );
  const payableAccount =
    accountQuery.data?.status === 200 ? accountQuery.data.data.data : undefined;

  const updateSupplier = useSupplierAccountsUpdate();

  const { contentRef, print } = useQuickPrint({
    documentTitle: supplier ? `Supplier ${supplier.code}` : "Supplier",
  });

  async function toggleStatus() {
    if (!supplier) return;
    const nextStatus = supplier.status === "ACTIVE" ? "ARCHIVED" : "ACTIVE";
    await updateSupplier.mutateAsync({
      supplierAccount: supplier.id,
      data: { status: nextStatus },
    });
    await queryClient.invalidateQueries();
  }

  function present(
    content: ReactNode,
    header?: {
      title: string;
      description?: string;
      actions?: ReactNode;
    }
  ) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title={header?.title ?? "Supplier detail"}
          description={header?.description}
          actions={header?.actions}
          size="xl"
          className="erp-record"
          onClose={() => navigate({ pathname: "/suppliers", search })}
        >
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          {header && (
            <PageHeader
              title={header.title}
              description={header.description}
              breadcrumbs={[
                { label: "Suppliers", to: "/suppliers" },
                { label: supplier?.code ?? "Detail" },
              ]}
              actions={header.actions}
            />
          )}
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return present(
      <div className="space-y-4 p-4">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  }

  if (query.isError || !supplier) {
    return present(
      <div className="space-y-4 p-4">
        <p className="text-sm text-destructive">Couldn't load this supplier.</p>
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: "/suppliers", search })}
        >
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to suppliers
        </Button>
      </div>
    );
  }

  const printSections: DetailPrintSection[] = [
    {
      title: "General Information",
      fields: [
        { label: "Supplier Code", value: supplier.code },
        { label: "Trading Partner", value: supplier.party?.name ?? "—" },
        { label: "Status", value: supplier.status === "ACTIVE" ? "Active" : "Archived" },
        {
          label: "Payment Terms",
          value: supplier.payment_terms_days != null ? `${supplier.payment_terms_days} days` : "—",
        },
        {
          label: "Lead Time",
          value: supplier.lead_time_days != null ? `${supplier.lead_time_days} days` : "Not specified",
        },
        {
          label: "Default Currency",
          value: supplier.default_currency_id
            ? (currencyMap.get(supplier.default_currency_id) ?? `Currency #${supplier.default_currency_id}`)
            : "Company default",
        },
      ],
    },
    {
      title: "Trading Partner Identity",
      fields: [
        { label: "Partner Code", value: supplier.party?.code ?? "—" },
        { label: "Tax ID", value: supplier.party?.tax_id ?? "—" },
        { label: "Registration No", value: supplier.party?.registration_no ?? "—" },
        { label: "Website", value: supplier.party?.website ?? "—" },
      ],
    },
    {
      title: "Addresses",
      fields: [
        {
          label: "Sites",
          value:
            sites.length > 0
              ? sites
                  .map(
                    (s) =>
                      `${s.party_site?.name ?? "Site"} (${s.role})${s.is_default ? " [Default]" : ""}: ` +
                      [s.party_site?.address_line1, s.party_site?.city, s.party_site?.country_code]
                        .filter(Boolean)
                        .join(", ")
                  )
                  .join("; ")
              : "No addresses registered",
        },
      ],
    },
  ];

  const currencyDisplay = supplier.default_currency_id
    ? (currencyMap.get(supplier.default_currency_id) ?? `Currency #${supplier.default_currency_id}`)
    : "Company default";

  const headerActions = (
    <div className="flex flex-wrap items-center gap-2">
      <PrintTrigger onPrint={() => print()} />
      {mayManage && (
        <>
          <Button
            variant="outline"
            size="sm"
            disabled={updateSupplier.isPending}
            onClick={() => void toggleStatus()}
          >
            {supplier.status === "ACTIVE" ? "Archive" : "Activate"}
          </Button>
          <Button asChild size="sm">
            <Link to={{ pathname: `/suppliers/${supplier.id}/edit`, search }}>
              <Pencil className="mr-1.5 h-4 w-4" />
              Edit
            </Link>
          </Button>
        </>
      )}
    </div>
  );

  return present(
    <>
      <DetailPageLayout>
        <DetailSection title="Supplier account">
          <DetailField label="Supplier code">{supplier.code}</DetailField>
          <DetailField label="Status">
            <SupplierStatusBadge status={supplier.status} />
          </DetailField>
          <DetailField label="Payment terms">
            {supplier.payment_terms_days != null ? (
              <span className="tabular-nums">{supplier.payment_terms_days} days</span>
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
          <DetailField label="Default lead time">
            {supplier.lead_time_days != null ? (
              <span className="tabular-nums">{supplier.lead_time_days} days</span>
            ) : (
              <span className="text-muted-foreground">Not specified</span>
            )}
          </DetailField>
          <DetailField label="Default currency">{currencyDisplay}</DetailField>
          {mayViewAccounts && (
            <DetailField label="Payable control account">
              {payableAccount ? (
                `${payableAccount.code} — ${payableAccount.name}`
              ) : supplier.payable_account_id ? (
                `Account #${supplier.payable_account_id}`
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </DetailField>
          )}
        </DetailSection>

        <DetailSection title="Trading partner identity">
          <DetailField label="Trading partner code">{supplier.party?.code ?? "—"}</DetailField>
          <DetailField label="Business name">{supplier.party?.name ?? "—"}</DetailField>
          <DetailField label="Tax identifier">{supplier.party?.tax_id ?? "—"}</DetailField>
          <DetailField label="Registration number">
            {supplier.party?.registration_no ?? "—"}
          </DetailField>
          <DetailField label="Website">
            {supplier.party?.website ? (
              <a
                href={supplier.party.website}
                target="_blank"
                rel="noreferrer"
                className="text-primary inline-flex items-center gap-1 hover:underline"
              >
                {supplier.party.website}
                <ExternalLink className="h-3 w-3" />
              </a>
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
        </DetailSection>

        <DetailSection
          title="Supplier addresses"
          description="Trading partner sites attached for ordering or delivery locations."
          fullWidth
        >
          <div className="space-y-3 sm:col-span-2">
            {sitesQuery.isLoading ? (
              <p className="text-sm text-muted-foreground">Loading addresses…</p>
            ) : sites.length === 0 ? (
              <p className="text-sm text-muted-foreground">No supplier addresses registered yet.</p>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2">
                {sites.map((site) => (
                  <div
                    key={site.id}
                    className="rounded-lg border bg-card p-3 text-sm text-card-foreground shadow-xs"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                        {site.party_site?.name ?? "Site"}
                      </span>
                      <div className="flex items-center gap-1">
                        <Badge variant="outline" className="text-xs">
                          {site.role}
                        </Badge>
                        {site.is_default && (
                          <Badge variant="secondary" className="text-xs">
                            Default
                          </Badge>
                        )}
                      </div>
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground leading-relaxed">
                      {[
                        site.party_site?.address_line1,
                        site.party_site?.address_line2,
                        site.party_site?.city,
                        site.party_site?.state,
                        site.party_site?.postal_code,
                        site.party_site?.country_code,
                      ]
                        .filter(Boolean)
                        .join(", ") || "No street address recorded"}
                    </p>
                  </div>
                ))}
              </div>
            )}
            {mayManageSites && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-2"
                onClick={() => setSiteOpen(true)}
              >
                <Plus className="mr-1.5 h-3.5 w-3.5" />
                Add address
              </Button>
            )}
          </div>
        </DetailSection>

        <DetailSection
          title="Related operations"
          description="Quick links to operational workflows committed with this supplier."
          fullWidth
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <Link
              to={`/purchase-orders?supplier_account_id=${supplier.id}`}
              className="flex items-center justify-between rounded-lg border bg-card p-4 transition-colors hover:bg-muted/50"
            >
              <div className="flex items-center gap-3">
                <div className="rounded-md bg-primary/10 p-2 text-primary">
                  <ShoppingBag className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-medium">Purchase Orders</h3>
                  <p className="text-xs text-muted-foreground">
                    View commitments and orders for this supplier.
                  </p>
                </div>
              </div>
              <ExternalLink className="h-4 w-4 text-muted-foreground" />
            </Link>

            <Link
              to={`/item-suppliers?supplier_account_id=${supplier.id}`}
              className="flex items-center justify-between rounded-lg border bg-card p-4 transition-colors hover:bg-muted/50"
            >
              <div className="flex items-center gap-3">
                <div className="rounded-md bg-primary/10 p-2 text-primary">
                  <Package className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-medium">Supplied Items</h3>
                  <p className="text-xs text-muted-foreground">
                    Inspect item sourcing terms and approved relationships.
                  </p>
                </div>
              </div>
              <ExternalLink className="h-4 w-4 text-muted-foreground" />
            </Link>
          </div>
        </DetailSection>
      </DetailPageLayout>

      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout
            title={`SUPPLIER: ${supplier.code}`}
            printedAt={new Date()}
            sections={printSections}
          />
        </div>
      </div>

      {mayManageSites && (
        <SupplierSiteDialog
          supplierAccountId={supplier.id}
          open={siteOpen}
          onOpenChange={setSiteOpen}
          onCreated={() => {
            setSiteOpen(false);
            void sitesQuery.refetch();
          }}
        />
      )}
    </>,
    {
      title: supplier.party?.name
        ? `${supplier.party.name} (${supplier.code})`
        : supplier.code,
      description: `Supplier account #${supplier.id}`,
      actions: headerActions,
    }
  );
}
