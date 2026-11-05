import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useFormulationsResolve } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { CustomerCombobox, ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { RecipeStatusBadge } from '../estimating/estimating-status';

const NONE = 'NONE';

/**
 * Preview of which formulation a piece of work would use for an output item: the customer's own recipe if it has one, otherwise the company default — and
 * never an archived one (`GET /formulations/resolve`). Lets someone check that a recipe is live before a document depends on it.
 */
export function FormulationResolutionTester({ onClose }: { onClose?: () => void }) {
  const navigate = useNavigate();
  const mayManage = usePermission('estimating.formulation.manage');
  const [itemId, setItemId] = useState('');
  const [customerId, setCustomerId] = useState(NONE);
  const [submitted, setSubmitted] = useState<{ itemId: number; customerId: number | null } | null>(null);

  const result = useFormulationsResolve(
    { output_item_id: submitted?.itemId ?? 0, customer_id: submitted?.customerId ?? undefined },
    { query: { enabled: submitted !== null, retry: false } },
  );
  const formulation = result.data?.status === 200 ? result.data.data.data : undefined;

  return (
    <DetailSection title="Test resolution" description="Preview which formulation would be used to produce an output item, for a customer or for the company default.">
      <div className="grid gap-3 sm:grid-cols-2 sm:col-span-2">
        <div className="space-y-1.5">
          <label className="text-sm font-medium" htmlFor="resolve-item">
            Output item
          </label>
          <ItemCombobox id="resolve-item" label="Output item (resolution test)" placeholder="Select output item" className="h-11 w-full md:h-10" value={itemId} onValueChange={setItemId} />
        </div>
        <div className="space-y-1.5">
          <label className="text-sm font-medium" htmlFor="resolve-customer">
            Customer
          </label>
          <CustomerCombobox
            id="resolve-customer"
            label="Customer (resolution test)"
            placeholder="Company default (no customer)"
            className="h-11 w-full md:h-10"
            leadingOptions={[{ value: NONE, label: 'Company default (no customer)' }]}
            value={customerId}
            onValueChange={setCustomerId}
          />
        </div>
      </div>
      <div className="flex flex-wrap gap-2 sm:col-span-2">
        <Button type="button" className="h-11 md:h-9" disabled={!itemId} onClick={() => setSubmitted({ itemId: Number(itemId), customerId: customerId === NONE ? null : Number(customerId) })}>
          Resolve
        </Button>
        {onClose && (
          <Button type="button" variant="ghost" className="h-11 md:h-9" onClick={onClose}>
            Close
          </Button>
        )}
      </div>
      <div className="space-y-2 sm:col-span-2" aria-live="polite">
        {result.isFetching && submitted && <p className="text-sm text-muted-foreground">Resolving…</p>}
        {formulation && !result.isFetching && (
          <div className="rounded-md border p-3" data-testid="formulation-resolution">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-medium">
                {formulation.code} — {formulation.name}
              </p>
              <RecipeStatusBadge status={formulation.status} />
            </div>
            <p className="text-sm text-muted-foreground">
              {formulation.customer_id ? 'This customer has a recipe of its own for the item.' : 'The company default recipe applies.'} Output {formulation.output_qty} per batch.
            </p>
            {mayManage && (
              <Button type="button" variant="link" className="h-auto p-0 text-sm" onClick={() => navigate({ pathname: `/formulations/${formulation.id}/edit`, search: window.location.search })}>
                Open this formulation
              </Button>
            )}
          </div>
        )}
        {result.isError && submitted && !result.isFetching && (
          <p role="alert" className="text-sm text-destructive">
            {getApiErrorMessage(result.error) ?? 'No formulation resolves for this item.'}
          </p>
        )}
      </div>
    </DetailSection>
  );
}
