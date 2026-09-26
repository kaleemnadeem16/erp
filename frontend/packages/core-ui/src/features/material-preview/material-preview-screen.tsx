import { useMemo, useState } from 'react';
import {
  useAssembliesIndex,
  useAssembliesMaterialPreview,
  useCustomerAccountsIndex,
  useRateDimensionsIndex,
  useUomsIndex,
  type AssemblyResource,
} from '@erp/core-api';
import { AlertTriangle, Calculator } from 'lucide-react';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { PageHeader } from '../../components/page/page-header';
import { getApiErrorMessage } from '../../lib/api-errors';
import { useAuthStore } from '../../stores/auth-store';

const NONE = 'NONE';
const ACTIVE = 'ACTIVE';

interface PreviewData {
  context: {
    assembly: { id: number; code: string; name: string };
    assembly_version: { id: number; version_no: number; status: string };
    requested_work_quantity: string;
    requested_work_uom: UomSummary;
    resolved_work_quantity: string;
    assembly_work_uom: UomSummary;
    customer_id: number | null;
  };
  material_components: Array<{
    component_id: number;
    item: { id: number; code: string; name: string };
    standard_quantity: string;
    uom: UomSummary;
    wastage_pct: string;
    multiplier: string;
    application_rate: null | {
      resolved_id: number;
      method: string;
      qty_per_work_unit: string;
      quantity_uom: UomSummary;
      work_uom: UomSummary;
    };
    formulation: null | { id: number; code: string; version_id: number; version_no: number; customer_id: number | null };
  }>;
  leaf_requirements: Array<{
    item: { id: number; code: string; name: string };
    standard_quantity: string;
    uom: UomSummary;
    contributions: Array<{ component_id: number; quantity: string; path: string[] }>;
  }>;
  cost_breakdown: null | {
    material_cost: string;
    labour_cost: string;
    plant_cost: string;
    subcontract_cost: string;
    direct_cost: string;
    overhead: { method: string; driver_type: string | null; rate: string | null; amount: string };
    total_cost: string;
    non_material_components: Array<{
      component_id: number;
      kind: string;
      resolved_quantity: string;
      uom: UomSummary;
      line_cost: string;
      cost_source: string;
    }>;
  };
  warnings: string[];
  limitations: string[];
  evidence_fingerprint: string;
}

interface UomSummary {
  id: number;
  code: string;
  name: string;
}

export function MaterialPreviewScreen() {
  const user = useAuthStore((state) => state.user);
  const permissions = Array.isArray(user?.permissions) ? user.permissions : [];
  const mayRun = permissions.includes('estimating.material_preview.run');
  const assembliesQuery = useAssembliesIndex({ per_page: 100, sort: 'code', include: 'workUom,versions' });
  const assemblies = assembliesQuery.data?.status === 200 ? assembliesQuery.data.data.data : [];
  const uomsQuery = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const customersQuery = useCustomerAccountsIndex({ per_page: 100, sort: 'code' });
  const customers = customersQuery.data?.status === 200 ? customersQuery.data.data.data : [];
  const dimensionsQuery = useRateDimensionsIndex({ per_page: 100, sort: 'sort_order', include: 'values' });
  const dimensions = dimensionsQuery.data?.status === 200 ? dimensionsQuery.data.data.data : [];

  const [assemblyId, setAssemblyId] = useState('');
  const [versionId, setVersionId] = useState(ACTIVE);
  const [workQuantity, setWorkQuantity] = useState('1');
  const [workUomId, setWorkUomId] = useState('');
  const [customerId, setCustomerId] = useState(NONE);
  const [dimensionValues, setDimensionValues] = useState<Record<number, string>>({});
  const [preview, setPreview] = useState<PreviewData | null>(null);
  const mutation = useAssembliesMaterialPreview();

  const assembly = useMemo(
    () => assemblies.find((candidate) => candidate.id === Number(assemblyId)),
    [assemblies, assemblyId],
  );

  const selectAssembly = (value: string) => {
    setAssemblyId(value);
    setVersionId(ACTIVE);
    const selected = assemblies.find((candidate) => candidate.id === Number(value));
    setWorkUomId(selected ? String(selected.work_uom_id) : '');
    setPreview(null);
  };

  const submit = () => {
    if (!assembly || !workUomId || !workQuantity) return;
    mutation.mutate(
      {
        assembly: assembly.id,
        data: {
          assembly_version_id: versionId === ACTIVE ? null : Number(versionId),
          work_quantity: workQuantity,
          work_uom_id: Number(workUomId),
          customer_id: customerId === NONE ? null : Number(customerId),
          dimension_value_ids: Object.values(dimensionValues).filter((value) => value !== NONE).map(Number),
        },
      },
      {
        onSuccess: (response) => {
          if (response.status === 200) setPreview(response.data.data as unknown as PreviewData);
        },
      },
    );
  };

  if (!mayRun) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <PageHeader title="Material Preview" description="Resolve the technical material requirement for a hypothetical quantity of work." />
        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
          You do not have permission to run material previews. Ask an administrator for <code>estimating.material_preview.run</code>.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 p-4 pb-24 md:p-6">
      <PageHeader
        title="Material Preview"
        description="See what an Assembly technically requires for a customer, quantity and rate context. This preview does not reserve stock."
      />

      <section className="space-y-4 rounded-lg border p-4" aria-labelledby="preview-inputs-heading">
        <div>
          <h2 id="preview-inputs-heading" className="font-semibold">Work context</h2>
          <p className="text-sm text-muted-foreground">Every field below is used by the resolver; leave optional context unspecified only when it genuinely does not apply.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Assembly">
            <Combobox
              aria-label="Assembly"
              value={assemblyId}
              onValueChange={selectAssembly}
              options={assemblies.map((row) => ({ value: String(row.id), label: `${row.code} — ${row.name}` }))}
              placeholder="Select assembly"
              searchPlaceholder="Search assemblies…"
              emptyText="No assemblies found."
            />
          </Field>
          <Field label="Assembly version">
            <Combobox
              aria-label="Assembly version"
              value={versionId}
              onValueChange={(value) => { setVersionId(value); setPreview(null); }}
              options={versionOptions(assembly)}
              placeholder="Effective active version"
              searchPlaceholder="Search versions…"
              emptyText="No versions found."
              disabled={!assembly}
            />
          </Field>
          <Field label="Customer (optional)">
            <Combobox
              aria-label="Customer"
              value={customerId}
              onValueChange={(value) => { setCustomerId(value); setPreview(null); }}
              options={[
                { value: NONE, label: 'Company-default formulation' },
                ...customers.map((row) => ({ value: String(row.id), label: `${row.code} — ${row.party?.name ?? 'Customer'}` })),
              ]}
              searchPlaceholder="Search customers…"
              emptyText="No customers found."
            />
          </Field>
          <Field label="Work quantity">
            <Input
              value={workQuantity}
              onChange={(event) => { setWorkQuantity(event.target.value); setPreview(null); }}
              inputMode="decimal"
              aria-label="Work quantity"
            />
          </Field>
          <Field label="Work unit">
            <Combobox
              aria-label="Work unit"
              value={workUomId}
              onValueChange={(value) => { setWorkUomId(value); setPreview(null); }}
              options={uoms.map((row) => ({ value: String(row.id), label: `${row.name} (${row.code})` }))}
              placeholder="Select unit"
              searchPlaceholder="Search units…"
              emptyText="No units found."
            />
          </Field>
          {dimensions.map((dimension) => (
            <Field key={dimension.id} label={`${dimension.name}${dimension.is_required ? ' (required for a specific match)' : ' (optional)'}`}>
              <Combobox
                aria-label={dimension.name}
                value={dimensionValues[dimension.id] ?? NONE}
                onValueChange={(value) => {
                  setDimensionValues((current) => ({ ...current, [dimension.id]: value }));
                  setPreview(null);
                }}
                options={[
                  { value: NONE, label: 'Not specified' },
                  ...(dimension.values ?? []).map((value) => ({ value: String(value.id), label: value.name })),
                ]}
                searchPlaceholder={`Search ${dimension.name.toLowerCase()}…`}
                emptyText="No values found."
              />
            </Field>
          ))}
        </div>
        <Button type="button" onClick={submit} disabled={!assembly || !workUomId || !workQuantity || mutation.isPending}>
          <Calculator className="size-4" />
          {mutation.isPending ? 'Calculating…' : 'Calculate preview'}
        </Button>
        {mutation.isError && (
          <p className="text-sm text-destructive">{getApiErrorMessage(mutation.error) ?? 'The material preview could not be calculated.'}</p>
        )}
      </section>

      {preview && <PreviewResult data={preview} />}
    </div>
  );
}

function PreviewResult({ data }: { data: PreviewData }) {
  return (
    <div className="space-y-5">
      <section className="rounded-lg border p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-semibold">Resolved requirement</h2>
            <p className="text-sm text-muted-foreground">
              {data.context.resolved_work_quantity} {data.context.assembly_work_uom.code} using Assembly version {data.context.assembly_version.version_no}
            </p>
          </div>
          <Badge variant="secondary">Advisory — not reserved</Badge>
        </div>
        {data.warnings.length > 0 && (
          <div className="mt-4 space-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
            {data.warnings.map((warning) => <p key={warning}><AlertTriangle className="mr-1 inline size-4" />{warning}</p>)}
          </div>
        )}
      </section>

      <section className="space-y-3 rounded-lg border p-4">
        <h2 className="font-semibold">Leaf materials</h2>
        {data.leaf_requirements.length === 0 && <p className="text-sm text-muted-foreground">This Assembly has no material requirement.</p>}
        {data.leaf_requirements.map((leaf) => (
          <div key={leaf.item.id} className="rounded-md border p-3">
            <div className="flex flex-wrap justify-between gap-2">
              <div className="font-medium">{leaf.item.code} — {leaf.item.name}</div>
              <div className="font-semibold">{leaf.standard_quantity} {leaf.uom.code}</div>
            </div>
            <div className="mt-2 space-y-1 text-xs text-muted-foreground">
              {leaf.contributions.map((contribution, index) => (
                <p key={`${contribution.component_id}-${index}`}>{contribution.quantity} {leaf.uom.code} · {contribution.path.join(' → ')}</p>
              ))}
            </div>
          </div>
        ))}
      </section>

      <section className="space-y-3 rounded-lg border p-4">
        <h2 className="font-semibold">Resolution evidence</h2>
        {data.material_components.map((component) => (
          <div key={component.component_id} className="rounded-md bg-muted/50 p-3 text-sm">
            <p className="font-medium">{component.item.code} — {component.standard_quantity} {component.uom.code}</p>
            <p className="text-muted-foreground">
              {component.application_rate
                ? `Rate #${component.application_rate.resolved_id}: ${component.application_rate.qty_per_work_unit} ${component.application_rate.quantity_uom.code}/${component.application_rate.work_uom.code} (${methodLabel(component.application_rate.method)})`
                : 'Fixed Assembly component quantity'}
              {component.formulation ? ` · Formulation ${component.formulation.code} v${component.formulation.version_no}` : ' · Direct leaf item'}
            </p>
          </div>
        ))}
      </section>

      {data.cost_breakdown ? (
        <section className="space-y-3 rounded-lg border p-4">
          <h2 className="font-semibold">Internal cost for this quantity</h2>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Figure label="Material" value={data.cost_breakdown.material_cost} />
            <Figure label="Labour" value={data.cost_breakdown.labour_cost} />
            <Figure label="Plant" value={data.cost_breakdown.plant_cost} />
            <Figure label="Subcontract" value={data.cost_breakdown.subcontract_cost} />
            <Figure label="Overhead" value={data.cost_breakdown.overhead.amount} />
            <Figure label="Total cost" value={data.cost_breakdown.total_cost} />
          </div>
        </section>
      ) : (
        <section className="rounded-lg border p-4 text-sm text-muted-foreground">
          Internal cost is hidden because your access does not include <code>estimating.internal_cost.view</code>. Hidden cost is not treated as zero.
        </section>
      )}

      <section className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
        {data.limitations.map((limitation) => <p key={limitation}>{limitation}</p>)}
        <p className="mt-2 break-all text-xs">Evidence fingerprint: {data.evidence_fingerprint}</p>
      </section>
    </div>
  );
}

function versionOptions(assembly?: AssemblyResource) {
  return [
    { value: ACTIVE, label: 'Effective ACTIVE version' },
    ...(assembly?.versions ?? []).map((version) => ({
      value: String(version.id),
      label: `Version ${version.version_no} — ${version.status}`,
    })),
  ];
}

function methodLabel(method: string) {
  return ({ EXACT_MATCH: 'exact match', REQUIRED_ONLY_MATCH: 'required dimensions only', DEFAULT: 'default fallback' } as Record<string, string>)[method] ?? method;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="space-y-2"><Label>{label}</Label>{children}</div>;
}

function Figure({ label, value }: { label: string; value: string }) {
  return <div><div className="text-xs text-muted-foreground">{label}</div><div className="font-medium">{value}</div></div>;
}
