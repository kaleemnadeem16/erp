import { useMemo, useState } from 'react';
import { useCategoriesIndex, useItemsIndex, useMaterialVariancePoliciesIndex, type VarianceScopeType } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { useAuthStore } from '../../stores/auth-store';
import { ReasonsPanel } from './reasons-panel';
import { VariancePolicyPanel } from './variance-policy-panel';

const SCOPE_LABELS: Record<string, string> = { COMPANY: 'Whole company', CATEGORY: 'A category', ITEM: 'One item' };

/**
 * Company-configured control over planned material quantities (ADR 0054): the reasons a plan may differ
 * from the calculated standard, and the rules that decide whether a difference is just recorded, needs a
 * reason or is blocked. Rules resolve item → nearest category → company. Everything is the company's own
 * data: nothing here is built in.
 */
export function MaterialVarianceSettingsScreen() {
  const user = useAuthStore((state) => state.user);
  const permissions = Array.isArray(user?.permissions) ? user.permissions : [];
  const mayManage = permissions.includes('estimating.material_variance_policy.manage');
  const mayViewRules = mayManage || permissions.includes('estimating.policy-history.view');
  const mayViewReasons = mayManage || permissions.includes('estimating.material_preview.run');

  const [scopeType, setScopeType] = useState<VarianceScopeType>('COMPANY');
  const [scopeId, setScopeId] = useState('');

  const categories = useCategoriesIndex({ per_page: 100, sort: 'code' }, { query: { enabled: mayViewRules } });
  const items = useItemsIndex({ per_page: 100, sort: 'code' }, { query: { enabled: mayViewRules } });
  const categoryRows = categories.data?.status === 200 ? categories.data.data.data : [];
  const itemRows = items.data?.status === 200 ? items.data.data.data : [];
  const active = useMaterialVariancePoliciesIndex({ 'filter[status]': 'ACTIVE', per_page: 100 }, { query: { enabled: mayViewRules } });
  const activeRows = active.data?.status === 200 ? active.data.data.data : [];

  const options = scopeType === 'CATEGORY'
    ? categoryRows.map((row) => ({ value: String(row.id), label: `${row.code} — ${row.name}` }))
    : itemRows.map((row) => ({ value: String(row.id), label: `${row.code} — ${row.name}` }));
  const scopeLabel = useMemo(() => {
    if (scopeType === 'COMPANY') return 'whole company';
    return options.find((option) => option.value === scopeId)?.label ?? '';
  }, [scopeType, scopeId, options]);
  const chosen = scopeType === 'COMPANY' || scopeId !== '';

  function describeScope(type: string, id: number | null): string {
    if (type === 'COMPANY') return 'Whole company';
    const row = type === 'CATEGORY' ? categoryRows.find((c) => c.id === id) : itemRows.find((i) => i.id === id);

    return `${type === 'CATEGORY' ? 'Category' : 'Item'} ${row?.code ?? `#${id}`}`;
  }

  if (!mayViewRules && !mayViewReasons) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <PageHeader title="Material variance" description="Reasons and rules for planned material quantities." />
        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
          You do not have permission to view this. Ask an administrator for <code>estimating.material_variance_policy.manage</code>.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Material variance"
        description="What happens when a planned material quantity differs from the calculated standard: which reasons people can give, and which differences are just recorded, need a reason or are blocked."
      />
      <DetailPageLayout>
        {mayViewReasons && <ReasonsPanel mayManage={mayManage} />}

        {mayViewRules && (
          <>
            <DetailSection title="Where rules are set" description="Every active rule set. Choose one to see or change it." fullWidth>
              <div className="space-y-2 text-sm sm:col-span-2">
                {activeRows.length === 0 ? (
                  <p className="text-muted-foreground">No rules set yet: any planned change needs a reason.</p>
                ) : (
                  <ul className="divide-y rounded-md border">
                    {activeRows.map((row) => (
                      <li key={row.id} className="flex items-center justify-between gap-2 p-2">
                        <span>
                          {describeScope(row.scope_type, row.scope_id)}{' '}
                          <span className="text-xs text-muted-foreground">· version {row.id} since {row.effective_from}</span>
                        </span>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => { setScopeType(row.scope_type as VarianceScopeType); setScopeId(row.scope_id === null ? '' : String(row.scope_id)); }}
                        >
                          Show rules
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </DetailSection>

            <DetailSection title="Choose a scope" description="A more specific scope overrides a broader one: an item's rules beat its category's, which beat the company's." fullWidth>
              <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="variance-scope">Rules apply to</Label>
                  <Select value={scopeType} onValueChange={(value) => { setScopeType(value as VarianceScopeType); setScopeId(''); }}>
                    <SelectTrigger id="variance-scope" aria-label="Rules apply to"><SelectValue /></SelectTrigger>
                    <SelectContent>{Object.entries(SCOPE_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
                {scopeType !== 'COMPANY' && (
                  <div className="space-y-2">
                    <Label>{scopeType === 'CATEGORY' ? 'Category' : 'Item'}</Label>
                    <Combobox
                      aria-label={scopeType === 'CATEGORY' ? 'Category' : 'Item'}
                      value={scopeId}
                      onValueChange={setScopeId}
                      options={options}
                      placeholder={scopeType === 'CATEGORY' ? 'Select category' : 'Select item'}
                      searchPlaceholder="Search…"
                      emptyText="Nothing found."
                    />
                  </div>
                )}
              </div>
            </DetailSection>

            {chosen && (
              <VariancePolicyPanel
                key={`${scopeType}-${scopeId}`}
                scopeType={scopeType}
                scopeId={scopeType === 'COMPANY' ? null : Number(scopeId)}
                scopeLabel={scopeLabel}
                mayManage={mayManage}
                onChanged={() => void active.refetch()}
              />
            )}
          </>
        )}
      </DetailPageLayout>
    </div>
  );
}
