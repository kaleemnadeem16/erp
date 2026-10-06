import { SupplierSiteDialog } from "../purchase-orders/supplier-site-form";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import { useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import {
  useSupplierAccountsSitesIndex,
  usePartiesIndex,
  usePartiesShow,
  usePartiesStore,
  useSupplierAccountsShow,
  useSupplierAccountsStore,
  useSupplierAccountsUpdate,
  useChartOfAccountsIndex,
  useChartOfAccountsShow,
  useCurrenciesIndex,
} from "@erp/core-api";
import { PageHeader } from "../../components/page/page-header";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "../../components/ui/select";
import { usePermission } from "../../hooks/use-permission";
import { getApiErrorMessage, lookupState } from "../../lib/api-errors";
import {
  CustomFieldInputs,
  customFieldValues,
  type CustomFieldValues,
} from "../custom-fields/custom-field-inputs";

export function SupplierFormScreen() {
  const { id } = useParams();
  const query = useSupplierAccountsShow(
    Number(id),
    { include: "party" },
    { query: { enabled: !!id } },
  );
  const supplier =
    query.data?.status === 200 ? query.data.data.data : undefined;
  if (id && query.isLoading)
    return (
      <p className="p-4" role="status">
        Loading supplier…
      </p>
    );
  if (id && !supplier)
    return <p className="p-4 text-destructive">Couldn't load this supplier.</p>;
  return <SupplierEditor key={id ?? "new"} supplier={supplier} />;
}

function SupplierEditor({
  supplier,
}: {
  supplier?: import("@erp/core-api").SupplierAccountResource;
}) {
  const navigate = useNavigate();
  const cache = useQueryClient();
  const mayManageSites = usePermission("parties.supplier-site.manage");
  const [siteOpen, setSiteOpen] = useState(false);
  const sitesQuery = useSupplierAccountsSitesIndex(
    supplier?.id ?? 0,
    undefined,
    { query: { enabled: !!supplier } },
  );
  const sites =
    sitesQuery.data?.status === 200 ? sitesQuery.data.data.data : [];
  const mayCreateParty = usePermission("parties.party.manage");
  const maySetTerms = usePermission("parties.payment-terms.manage");
  const mayMap = usePermission("accounting.account-mapping.manage");
  const [newParty, setNewParty] = useState(false);
  const [partyId, setPartyId] = useState(
    supplier ? String(supplier.party_id) : "",
  );
  const [partyCode, setPartyCode] = useState("");
  const [partyName, setPartyName] = useState("");
  const [taxId, setTaxId] = useState("");
  const [registration, setRegistration] = useState("");
  const [website, setWebsite] = useState("");
  const [partyFields, setPartyFields] = useState<CustomFieldValues>({});
  const [code, setCode] = useState(supplier?.code ?? "");
  const [terms, setTerms] = useState(String(supplier?.payment_terms_days ?? 0));
  const [lead, setLead] = useState(
    supplier?.lead_time_days == null ? "" : String(supplier.lead_time_days),
  );
  const [account, setAccount] = useState(
    supplier?.payable_account_id ? String(supplier.payable_account_id) : "",
  );
  const [currency, setCurrency] = useState(
    supplier?.default_currency_id ? String(supplier.default_currency_id) : "",
  );
  const [status, setStatus] = useState(supplier?.status ?? "ACTIVE");
  const [fields, setFields] = useState(
    customFieldValues(supplier?.custom_fields),
  );
  const [error, setError] = useState<string | null>(null);
  const [partySearch, setPartySearch] = useState("");
  const [accountSearch, setAccountSearch] = useState("");
  const parties = usePartiesIndex({
    "filter[search]": partySearch || undefined,
    per_page: 100,
    sort: "name",
  });
  const selectedParty = usePartiesShow(Number(partyId), undefined, {
    query: { enabled: !!partyId },
  });
  const accounts = useChartOfAccountsIndex(
    {
      "filter[search]": accountSearch || undefined,
      per_page: 100,
      sort: "code",
    },
    { query: { enabled: mayMap } },
  );
  const selectedAccountQuery = useChartOfAccountsShow(
    Number(account),
    undefined,
    { query: { enabled: mayMap && !!account } },
  );
  const selectedAccount =
    selectedAccountQuery.data?.status === 200
      ? selectedAccountQuery.data.data.data
      : undefined;
  const currencies = useCurrenciesIndex({ per_page: 100, sort: "code" });
  const createParty = usePartiesStore();
  const createSupplier = useSupplierAccountsStore();
  const updateSupplier = useSupplierAccountsUpdate();
  const busy =
    createParty.isPending ||
    createSupplier.isPending ||
    updateSupplier.isPending;
  const partyOptions = (
    parties.data?.status === 200 ? parties.data.data.data : []
  ).map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }));
  const selected =
    selectedParty.data?.status === 200
      ? selectedParty.data.data.data
      : supplier?.party;
  if (selected && !partyOptions.some((p) => p.value === String(selected.id)))
    partyOptions.push({
      value: String(selected.id),
      label: `${selected.code} — ${selected.name}`,
    });
  async function submit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      let resolvedParty = Number(partyId);
      if (newParty) {
        const result = await createParty.mutateAsync({
          data: {
            code: partyCode.trim(),
            name: partyName.trim(),
            tax_id: taxId || null,
            registration_no: registration || null,
            website: website || null,
            custom_fields: partyFields,
          },
        });
        if (result.status !== 201) return;
        resolvedParty = result.data.data.id;
        setPartyId(String(resolvedParty));
        setNewParty(false);
        // Keep this identity if the second save fails; retry never creates a duplicate partner.
        await cache.invalidateQueries();
      }
      const data = {
        code: code.trim(),
        lead_time_days: lead ? Number(lead) : null,
        default_currency_id: currency ? Number(currency) : null,
        status: status as "ACTIVE" | "ARCHIVED",
        custom_fields: fields,
        ...(maySetTerms ? { payment_terms_days: Number(terms) } : {}),
        ...(mayMap ? { payable_account_id: Number(account) } : {}),
      };
      const result = supplier
        ? await updateSupplier.mutateAsync({
            supplierAccount: supplier.id,
            data,
          })
        : await createSupplier.mutateAsync({
            data: {
              ...data,
              party_id: resolvedParty,
              payment_terms_days: Number(terms),
              payable_account_id: Number(account),
            },
          });
      if (result.status === 200 || result.status === 201) {
        await cache.invalidateQueries();
        navigate("/suppliers");
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }
  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={supplier ? `Edit supplier ${supplier.code}` : "New supplier"}
        backTo="/suppliers"
        description="A supplier account belongs to a shared trading partner. Reuse its identity if it already exists."
      />
      <form onSubmit={submit} className="space-y-5">
        <DetailSection title="Trading partner">
          {supplier ? (
            <p className="text-sm">
              {supplier.party?.code} — {supplier.party?.name}
            </p>
          ) : (
            <>
              {!newParty && (
                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="supplier-party">
                    Existing trading partner
                  </Label>
                  <RemoteLookupCombobox
                    label="Existing trading partner"
                    className="w-full"
                    search={partySearch}
                    selectedLabel={
                      selected
                        ? `${selected.code} — ${selected.name}`
                        : undefined
                    }
                    {...lookupState(parties, "trading partners")}
                    options={partyOptions}
                    value={partyId}
                    onValueChange={setPartyId}
                    onSearchChange={setPartySearch}
                  />
                </div>
              )}
              {mayCreateParty && (
                <Button
                  type="button"
                  variant="outline"
                  className="justify-self-start"
                  onClick={() => setNewParty(!newParty)}
                >
                  {newParty
                    ? "Use existing trading partner"
                    : "Register a new trading partner"}
                </Button>
              )}
              {newParty && (
                <>
                  <div className="space-y-2">
                    <Label htmlFor="party-code">Trading partner code</Label>
                    <Input
                      id="party-code"
                      required
                      value={partyCode}
                      onChange={(e) => setPartyCode(e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="party-name">Business name</Label>
                    <Input
                      id="party-name"
                      required
                      value={partyName}
                      onChange={(e) => setPartyName(e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="party-tax">Tax ID</Label>
                    <Input
                      id="party-tax"
                      value={taxId}
                      onChange={(e) => setTaxId(e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="party-registration">
                      Registration number
                    </Label>
                    <Input
                      id="party-registration"
                      value={registration}
                      onChange={(e) => setRegistration(e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="party-website">Website</Label>
                    <Input
                      id="party-website"
                      type="url"
                      value={website}
                      onChange={(e) => setWebsite(e.target.value)}
                    />
                  </div>
                </>
              )}
            </>
          )}
        </DetailSection>
        {newParty && (
          <CustomFieldInputs
            entity="parties"
            value={partyFields}
            onChange={setPartyFields}
          />
        )}
        <DetailSection title="Supplier account">
          <div className="space-y-2">
            <Label htmlFor="supplier-code">Supplier code</Label>
            <Input
              id="supplier-code"
              required
              value={code}
              onChange={(e) => setCode(e.target.value)}
            />
          </div>
          {maySetTerms && (
            <div className="space-y-2">
              <Label htmlFor="supplier-terms">Payment terms (days)</Label>
              <Input
                id="supplier-terms"
                type="number"
                min="0"
                max="32767"
                required
                value={terms}
                onChange={(e) => setTerms(e.target.value)}
              />
            </div>
          )}
          <div className="space-y-2">
            <Label htmlFor="supplier-lead">Lead time (days)</Label>
            <Input
              id="supplier-lead"
              type="number"
              min="0"
              max="32767"
              value={lead}
              onChange={(e) => setLead(e.target.value)}
            />
          </div>
          {mayMap && (
            <div className="space-y-2">
              <Label htmlFor="supplier-payable">Payable account</Label>
              <RemoteLookupCombobox
                label="Payable account"
                className="w-full"
                search={accountSearch}
                selectedLabel={
                  selectedAccount
                    ? `${selectedAccount.code} — ${selectedAccount.name}`
                    : undefined
                }
                {...lookupState(accounts, "accounts")}
                value={account}
                onValueChange={setAccount}
                onSearchChange={setAccountSearch}
                options={(accounts.data?.status === 200
                  ? accounts.data.data.data
                  : []
                ).map((a) => ({
                  value: String(a.id),
                  label: `${a.code} — ${a.name}`,
                }))}
              />
            </div>
          )}
          <div className="space-y-2">
            <Label htmlFor="supplier-currency">Default currency</Label>
            <Combobox
              id="supplier-currency"
              {...lookupState(currencies, "currencies")}
              value={currency}
              onValueChange={setCurrency}
              options={[
                { value: "", label: "Company default" },
                ...(currencies.data?.status === 200
                  ? currencies.data.data.data
                  : []
                ).map((c) => ({
                  value: String(c.id),
                  label: `${c.code} — ${c.name}`,
                })),
              ]}
              placeholder="Company default"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="supplier-status">Status</Label>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger id="supplier-status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ACTIVE">Active</SelectItem>
                <SelectItem value="ARCHIVED">Archived</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </DetailSection>
        <CustomFieldInputs
          entity="supplier_accounts"
          value={fields}
          onChange={setFields}
        />
        {error && (
          <p className="text-sm text-destructive" role="alert">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate("/suppliers")}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            disabled={
              busy ||
              (!supplier && !newParty && !partyId) ||
              (mayMap && !account)
            }
          >
            {busy ? "Saving…" : supplier ? "Save changes" : "Register supplier"}
          </Button>
        </div>
      </form>
      {supplier && (
        <DetailSection
          title="Supplier addresses"
          description="Sites are shared trading-partner addresses with supplier-specific roles."
        >
          <div className="space-y-3 sm:col-span-2">
            {sitesQuery.isError ? (
              <p role="alert">
                Couldn't load addresses.{" "}
                <button
                  type="button"
                  className="underline"
                  onClick={() => void sitesQuery.refetch()}
                >
                  Retry
                </button>
              </p>
            ) : sitesQuery.isLoading ? (
              <p role="status">Loading addresses…</p>
            ) : sites.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No supplier addresses yet.
              </p>
            ) : (
              sites.map((site) => (
                <div
                  className="rounded-md border bg-background p-3 text-sm"
                  key={site.id}
                >
                  <p className="font-medium">
                    {site.party_site?.name} · {site.role}
                  </p>
                  <p className="text-muted-foreground">
                    {[
                      site.party_site?.address_line1,
                      site.party_site?.city,
                      site.party_site?.country_code,
                    ]
                      .filter(Boolean)
                      .join(", ")}
                  </p>
                </div>
              ))
            )}
            {mayManageSites && (
              <Button
                type="button"
                variant="outline"
                onClick={() => setSiteOpen(true)}
              >
                Add address
              </Button>
            )}
          </div>
        </DetailSection>
      )}
      {supplier && mayManageSites && (
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
    </div>
  );
}
