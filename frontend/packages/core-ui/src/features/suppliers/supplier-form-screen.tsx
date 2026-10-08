import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
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
  type SupplierAccountResource,
} from "@erp/core-api";
import { History, RotateCcw } from "lucide-react";

import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { SectionStack } from "../../components/ui/section-stack";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import { RemoteLookupCombobox } from "../../components/ui/remote-lookup-combobox";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "../../components/ui/select";
import {
  AdvancedOptionsMenu,
  type AdvancedOption,
} from "../../components/ui/advanced-options-menu";
import { AdvancedOptionSection } from "../../components/ui/advanced-option-section";
import type { Presentation } from "../../lib/presentation";
import { usePermission } from "../../hooks/use-permission";
import { getApiErrorMessage, lookupState } from "../../lib/api-errors";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import {
  CustomFieldInputs,
  customFieldValues,
  type CustomFieldValues,
} from "../custom-fields/custom-field-inputs";
import { SupplierSiteDialog } from "../purchase-orders/supplier-site-form";

const DRAFT_NAME = "supplier";

export interface UnfinishedSupplier {
  newParty: boolean;
  partyId: string;
  partyCode: string;
  partyName: string;
  taxId: string;
  registration: string;
  website: string;
  partyFields: CustomFieldValues;
  code: string;
  terms: string;
  lead: string;
  account: string;
  currency: string;
  status: string;
  fields: CustomFieldValues;
  showLeadTime: boolean;
  showCurrency: boolean;
  showCustomFields: boolean;
}

function isPristine(values: Partial<UnfinishedSupplier>): boolean {
  return (
    !values.newParty &&
    !values.partyId &&
    !values.partyCode &&
    !values.partyName &&
    !values.taxId &&
    !values.registration &&
    !values.website &&
    (!values.partyFields || Object.keys(values.partyFields).length === 0) &&
    !values.code &&
    (!values.terms || values.terms === "0" || values.terms === "30") &&
    !values.lead &&
    !values.account &&
    !values.currency &&
    (!values.status || values.status === "ACTIVE") &&
    (!values.fields || Object.keys(values.fields).length === 0) &&
    !values.showLeadTime &&
    !values.showCurrency &&
    !values.showCustomFields
  );
}

export function readUnfinishedSupplier(): UnfinishedSupplier | null {
  const stored = readDraft<UnfinishedSupplier>(DRAFT_NAME)?.value;
  if (!stored) return null;
  if (isPristine(stored)) return null;
  return stored;
}

export const hasUnfinishedSupplier = () => readUnfinishedSupplier() !== null;
export const clearUnfinishedSupplier = () => clearDraft(DRAFT_NAME);

export interface SupplierFormScreenProps {
  presentation?: Presentation;
}

export function SupplierFormScreen({
  presentation = "sheet",
}: SupplierFormScreenProps) {
  const { id } = useParams<{ id: string }>();
  const supplierId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();

  const query = useSupplierAccountsShow(
    supplierId,
    { include: "party" },
    { query: { enabled: Number.isFinite(supplierId) && supplierId > 0 } }
  );
  const supplier =
    query.data?.status === 200 ? query.data.data.data : undefined;

  if (id && query.isLoading) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Loading supplier…"
          size="lg"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: "/suppliers", search })}
        >
          <div className="p-4">
            <p className="text-muted-foreground">Loading supplier details…</p>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form">
        <div className="p-4 md:p-6" role="status">
          <p className="text-muted-foreground">Loading supplier…</p>
        </div>
      </ScreenTemplate>
    );
  }

  if (id && !supplier) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Error"
          size="lg"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: "/suppliers", search })}
        >
          <div className="p-4">
            <p className="text-destructive">Couldn't load this supplier.</p>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form">
        <div className="p-4 md:p-6">
          <p className="text-destructive">Couldn't load this supplier.</p>
        </div>
      </ScreenTemplate>
    );
  }

  return (
    <SupplierEditor
      key={id ?? "new"}
      supplier={supplier}
      presentation={presentation}
    />
  );
}

function SupplierEditor({
  supplier,
  presentation = "sheet",
}: {
  supplier?: SupplierAccountResource;
  presentation?: Presentation;
}) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();

  const isCreate = !supplier;
  const [unfinished] = useState(() => (isCreate ? readUnfinishedSupplier() : null));
  const [resumed, setResumed] = useState(unfinished !== null);
  const [confirmingStartOver, setConfirmingStartOver] = useState(false);

  const mayManageSites = usePermission("parties.supplier-site.manage");
  const mayCreateParty = usePermission("parties.party.manage");
  const maySetTerms = usePermission("parties.payment-terms.manage");
  const mayMap = usePermission("accounting.account-mapping.manage");

  const [siteOpen, setSiteOpen] = useState(false);
  const sitesQuery = useSupplierAccountsSitesIndex(
    supplier?.id ?? 0,
    undefined,
    { query: { enabled: !!supplier } }
  );
  const sites =
    sitesQuery.data?.status === 200 ? sitesQuery.data.data.data : [];

  const [newParty, setNewParty] = useState(
    supplier ? false : (unfinished?.newParty ?? false)
  );
  const [partyId, setPartyId] = useState(
    supplier ? String(supplier.party_id) : (unfinished?.partyId ?? "")
  );
  const [partyCode, setPartyCode] = useState(
    supplier ? "" : (unfinished?.partyCode ?? "")
  );
  const [partyName, setPartyName] = useState(
    supplier ? "" : (unfinished?.partyName ?? "")
  );
  const [taxId, setTaxId] = useState(
    supplier ? "" : (unfinished?.taxId ?? "")
  );
  const [registration, setRegistration] = useState(
    supplier ? "" : (unfinished?.registration ?? "")
  );
  const [website, setWebsite] = useState(
    supplier ? "" : (unfinished?.website ?? "")
  );
  const [partyFields, setPartyFields] = useState<CustomFieldValues>(
    supplier ? {} : (unfinished?.partyFields ?? {})
  );

  const [code, setCode] = useState(
    supplier ? supplier.code : (unfinished?.code ?? "")
  );
  const [terms, setTerms] = useState(
    supplier
      ? String(supplier.payment_terms_days ?? 0)
      : (unfinished?.terms ?? "30")
  );
  const [lead, setLead] = useState(
    supplier
      ? (supplier.lead_time_days == null ? "" : String(supplier.lead_time_days))
      : (unfinished?.lead ?? "")
  );
  const [account, setAccount] = useState(
    supplier
      ? (supplier.payable_account_id ? String(supplier.payable_account_id) : "")
      : (unfinished?.account ?? "")
  );
  const [currency, setCurrency] = useState(
    supplier
      ? (supplier.default_currency_id ? String(supplier.default_currency_id) : "")
      : (unfinished?.currency ?? "")
  );
  const [status, setStatus] = useState(
    supplier ? supplier.status : (unfinished?.status ?? "ACTIVE")
  );
  const [fields, setFields] = useState<CustomFieldValues>(
    supplier
      ? customFieldValues(supplier.custom_fields)
      : (unfinished?.fields ?? {})
  );
  const [error, setError] = useState<string | null>(null);

  // Advanced options: active by default if data is already set
  const [showLeadTime, setShowLeadTime] = useState(
    Boolean(
      supplier
        ? supplier.lead_time_days != null
        : unfinished?.showLeadTime
    )
  );
  const [showCurrency, setShowCurrency] = useState(
    Boolean(
      supplier
        ? supplier.default_currency_id
        : unfinished?.showCurrency
    )
  );
  const [showCustomFields, setShowCustomFields] = useState(
    Boolean(
      supplier
        ? supplier.custom_fields && Object.keys(supplier.custom_fields).length > 0
        : unfinished?.showCustomFields
    )
  );

  useEffect(() => {
    if (!isCreate) return;
    const currentValues: UnfinishedSupplier = {
      newParty,
      partyId,
      partyCode,
      partyName,
      taxId,
      registration,
      website,
      partyFields,
      code,
      terms,
      lead,
      account,
      currency,
      status,
      fields,
      showLeadTime,
      showCurrency,
      showCustomFields,
    };
    if (isPristine(currentValues)) {
      clearDraft(DRAFT_NAME);
    } else {
      writeDraft(DRAFT_NAME, currentValues);
    }
  }, [
    isCreate,
    newParty,
    partyId,
    partyCode,
    partyName,
    taxId,
    registration,
    website,
    partyFields,
    code,
    terms,
    lead,
    account,
    currency,
    status,
    fields,
    showLeadTime,
    showCurrency,
    showCustomFields,
  ]);

  function startOver() {
    setNewParty(false);
    setPartyId("");
    setPartyCode("");
    setPartyName("");
    setTaxId("");
    setRegistration("");
    setWebsite("");
    setPartyFields({});
    setCode("");
    setTerms("30");
    setLead("");
    setAccount("");
    setCurrency("");
    setStatus("ACTIVE");
    setFields({});
    setShowLeadTime(false);
    setShowCurrency(false);
    setShowCustomFields(false);
    setResumed(false);
    setConfirmingStartOver(false);
    clearDraft(DRAFT_NAME);
  }

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
    { query: { enabled: mayMap } }
  );
  const selectedAccountQuery = useChartOfAccountsShow(
    Number(account),
    undefined,
    { query: { enabled: mayMap && !!account } }
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

  const advancedOptions: AdvancedOption[] = [
    {
      id: "lead-time",
      label: "Default lead time",
      checked: showLeadTime,
      onCheckedChange: (checked) => {
        setShowLeadTime(checked);
        if (!checked) setLead("");
      },
    },
    {
      id: "currency",
      label: "Default currency",
      checked: showCurrency,
      onCheckedChange: (checked) => {
        setShowCurrency(checked);
        if (!checked) setCurrency("");
      },
    },
    {
      id: "custom-fields",
      label: "Custom fields",
      checked: showCustomFields,
      onCheckedChange: (checked) => {
        setShowCustomFields(checked);
        if (!checked) setFields({});
      },
    },
  ];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);

    try {
      let resolvedParty = Number(partyId);

      if (!supplier && newParty) {
        if (!partyName.trim()) {
          setError("Trading partner name is required.");
          return;
        }
        const resolvedPartyCode =
          partyCode.trim() ||
          (partyName.trim().toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 8) || "PTY") +
            "-" +
            Date.now().toString().slice(-4);
        const createdParty = await createParty.mutateAsync({
          data: {
            name: partyName.trim(),
            code: resolvedPartyCode,
            ...(taxId.trim() ? { tax_id: taxId.trim() } : {}),
            ...(registration.trim()
              ? { registration_no: registration.trim() }
              : {}),
            ...(website.trim() ? { website: website.trim() } : {}),
            custom_fields: partyFields,
          },
        });
        if (createdParty.status !== 201) {
          setError("Couldn't create trading partner.");
          return;
        }
        resolvedParty = createdParty.data.data.id;
      }

      if (!supplier && !resolvedParty) {
        setError("Choose a trading partner or register a new one.");
        return;
      }

      const data = {
        code: code.trim(),
        lead_time_days:
          showLeadTime && lead.trim() ? Number(lead) : null,
        default_currency_id:
          showCurrency && currency ? Number(currency) : null,
        status: status as "ACTIVE" | "ARCHIVED",
        custom_fields: showCustomFields ? fields : {},
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
        clearDraft(DRAFT_NAME);
        await queryClient.invalidateQueries();
        navigate({ pathname: "/suppliers", search });
      }
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  const formBody = (
    <form onSubmit={submit} className="space-y-6">
      {isCreate && resumed && (
        <div
          role="status"
          className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-primary-soft-foreground"
        >
          <div className="flex min-w-0 items-start gap-3">
            <History aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 text-sm">
              <p className="font-medium">
                {confirmingStartOver
                  ? "Discard everything you've entered?"
                  : "Continuing your unfinished supplier registration"}
              </p>
              <p className="text-xs opacity-80">
                {confirmingStartOver
                  ? "What you entered will be cleared and cannot be brought back."
                  : "What you entered last time is still here."}
              </p>
            </div>
          </div>
          {confirmingStartOver ? (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                size="sm"
                variant="destructive"
                onClick={startOver}
              >
                <RotateCcw className="mr-1 h-3.5 w-3.5" />
                Yes, start over
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                autoFocus
                onClick={() => setConfirmingStartOver(false)}
              >
                Keep it
              </Button>
            </div>
          ) : (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setConfirmingStartOver(true)}
            >
              <RotateCcw className="mr-1 h-3.5 w-3.5" />
              Start over
            </Button>
          )}
        </div>
      )}

      <SectionStack>
        <DetailSection title="Trading partner">
          {supplier ? (
            <div className="space-y-1">
              <p className="font-medium text-foreground">
                {supplier.party?.name}
              </p>
              <p className="text-sm text-muted-foreground">
                Partner code: {supplier.party?.code}
              </p>
            </div>
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
                      selectedParty.data?.status === 200
                        ? `${selectedParty.data.data.data.code} — ${selectedParty.data.data.data.name}`
                        : undefined
                    }
                    {...lookupState(parties, "trading partners")}
                    value={partyId}
                    onValueChange={setPartyId}
                    onSearchChange={setPartySearch}
                    options={(parties.data?.status === 200
                      ? parties.data.data.data
                      : []
                    ).map((p) => ({
                      value: String(p.id),
                      label: `${p.code} — ${p.name}`,
                    }))}
                  />
                  {mayCreateParty && (
                    <p className="text-xs text-muted-foreground">
                      Or{" "}
                      <button
                        type="button"
                        className="text-primary underline hover:opacity-80"
                        onClick={() => {
                          setNewParty(true);
                          setPartyId("");
                        }}
                      >
                        register a new trading partner
                      </button>{" "}
                      if one doesn't exist yet.
                    </p>
                  )}
                </div>
              )}

              {newParty && (
                <>
                  <div className="space-y-2">
                    <Label htmlFor="party-name">Trading partner name</Label>
                    <Input
                      id="party-name"
                      required
                      value={partyName}
                      onChange={(e) => setPartyName(e.target.value)}
                      placeholder="e.g. Acme Industrial Supplies Ltd"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="party-code">
                      Partner code{" "}
                      <span className="text-xs text-muted-foreground">
                        (optional)
                      </span>
                    </Label>
                    <Input
                      id="party-code"
                      value={partyCode}
                      onChange={(e) => setPartyCode(e.target.value)}
                      placeholder="Auto-generated if empty"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="party-tax-id">
                      Tax identification number (TIN)
                    </Label>
                    <Input
                      id="party-tax-id"
                      value={taxId}
                      onChange={(e) => setTaxId(e.target.value)}
                      placeholder="e.g. GB123456789"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="party-reg">Company registration no.</Label>
                    <Input
                      id="party-reg"
                      value={registration}
                      onChange={(e) => setRegistration(e.target.value)}
                      placeholder="e.g. 01234567"
                    />
                  </div>

                  <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor="party-web">Website</Label>
                    <Input
                      id="party-web"
                      type="url"
                      value={website}
                      onChange={(e) => setWebsite(e.target.value)}
                      placeholder="https://example.com"
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <CustomFieldInputs
                      entity="parties"
                      value={partyFields}
                      onChange={setPartyFields}
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setNewParty(false)}
                    >
                      ← Back to select existing trading partner
                    </Button>
                  </div>
                </>
              )}
            </>
          )}
        </DetailSection>

        <DetailSection title="Supplier profile">
          <div className="space-y-2">
            <Label htmlFor="supplier-code">Supplier code</Label>
            <Input
              id="supplier-code"
              required
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="e.g. SUP-0001"
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

          {mayMap && (
            <div className="space-y-2">
              <Label htmlFor="supplier-payable">Payable control account</Label>
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

        <AdvancedOptionSection expanded={showLeadTime}>
          <DetailSection
            title="Lead time"
            description="Default expected lead time for goods receipt."
          >
            <div className="space-y-2">
              <Label htmlFor="supplier-lead">Default lead time (days)</Label>
              <Input
                id="supplier-lead"
                type="number"
                min="0"
                max="32767"
                value={lead}
                onChange={(e) => setLead(e.target.value)}
                placeholder="e.g. 14"
              />
            </div>
          </DetailSection>
        </AdvancedOptionSection>

        <AdvancedOptionSection expanded={showCurrency}>
          <DetailSection
            title="Currency"
            description="Default settlement currency for purchase orders."
          >
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
          </DetailSection>
        </AdvancedOptionSection>

        <AdvancedOptionSection expanded={showCustomFields}>
          <CustomFieldInputs
            entity="supplier_accounts"
            value={fields}
            onChange={setFields}
          />
        </AdvancedOptionSection>

        {supplier && (
          <DetailSection
            title="Supplier addresses"
            description="Sites are shared trading-partner addresses with supplier-specific roles."
          >
            <div className="space-y-3 sm:col-span-2">
              {sitesQuery.isError ? (
                <p role="alert" className="text-sm text-destructive">
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
                <p role="status" className="text-sm text-muted-foreground">
                  Loading addresses…
                </p>
              ) : sites.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  No supplier addresses yet.
                </p>
              ) : (
                <div className="grid gap-2 sm:grid-cols-2">
                  {sites.map((site) => (
                    <div
                      className="rounded-md border bg-card p-3 text-sm"
                      key={site.id}
                    >
                      <p className="font-medium">
                        {site.party_site?.name} · {site.role}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {[
                          site.party_site?.address_line1,
                          site.party_site?.city,
                          site.party_site?.country_code,
                        ]
                          .filter(Boolean)
                          .join(", ")}
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
                  onClick={() => setSiteOpen(true)}
                >
                  Add address
                </Button>
              )}
            </div>
          </DetailSection>
        )}
      </SectionStack>

      {error && (
        <p className="text-sm text-destructive" role="alert">
          {error}
        </p>
      )}

      <div className="flex items-center justify-end gap-3 pt-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => navigate({ pathname: "/suppliers", search })}
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
  );

  const title = supplier
    ? `Edit supplier ${supplier.code}`
    : "New supplier";
  const description =
    "A supplier account belongs to a shared trading partner. Reuse its identity if it already exists.";

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={title}
        description={description}
        actions={
          <AdvancedOptionsMenu
            options={advancedOptions}
            label="Advanced options"
          />
        }
        size="lg"
        className="erp-record"
        dismissOnOutsideClick={false}
        onClose={() => navigate({ pathname: "/suppliers", search })}
      >
        <div className="space-y-4 pb-6">{formBody}</div>

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
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form">
      <div className="space-y-4">
        <PageHeader
          title={title}
          backTo="/suppliers"
          description={description}
          actions={
            <AdvancedOptionsMenu
              options={advancedOptions}
              label="Advanced options"
            />
          }
        />
        {formBody}

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
    </ScreenTemplate>
  );
}
