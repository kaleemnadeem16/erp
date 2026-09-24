import { OverheadScopeType } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout } from '../../components/detail/detail-page-layout';
import { OverheadPolicyPanel } from '../overhead-policies/overhead-policy-panel';

/**
 * The company-wide overhead default (ADR 0049's COMPANY scope tier) — the
 * only tier a small company ever needs to touch. Assembly Group and
 * Assembly-level overrides live on their own edit screens instead of here,
 * since they only make sense in the context of the group/assembly they
 * apply to.
 */
export function EstimatingSettingsScreen() {
  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Estimating settings"
        description="The company default overhead policy — applied to any assembly with no group- or assembly-level override of its own."
      />
      <DetailPageLayout>
        <OverheadPolicyPanel scopeType={OverheadScopeType.COMPANY} scopeId={null} />
      </DetailPageLayout>
    </div>
  );
}
