import { useMemo, useState } from 'react';
import {
  useLocationsIndex,
  useWarehouseLocationRolesDestroy,
  useWarehouseLocationRolesIndex,
  useWarehouseLocationRolesStore,
  useWarehouseLocationRolesUpdate,
  type WarehouseLocationRole,
} from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { formatLocation } from '../../lib/format-location';

/** The operational roles a warehouse's locations can play (ADR 0041). Value = what the API stores; label = what a person reads. */
export const LOCATION_ROLES: ReadonlyArray<{ value: WarehouseLocationRole; label: string }> = [
  { value: 'RECEIVING', label: 'Receiving' },
  { value: 'QUARANTINE', label: 'Quarantine' },
  { value: 'DISPATCH', label: 'Dispatch' },
  { value: 'RETURNS', label: 'Returns' },
  { value: 'TRANSIT', label: 'In transit' },
  { value: 'PRODUCTION', label: 'Production' },
];

/**
 * Which of this warehouse's locations plays each operational role — one location per role (the API refuses a second). Shown only in STRUCTURED mode, where
 * roles exist (ADR 0041: a SIMPLE company sees nothing role-related); changing one needs `inventory.location.manage`, which the API also checks. The location
 * picker offers only this warehouse's own locations (the API requires that too).
 */
export function LocationRolesPanel({ warehouseId, structured, mayManage }: { warehouseId: number; structured: boolean; mayManage: boolean }) {
  const query = useWarehouseLocationRolesIndex({ 'filter[warehouse_id]': warehouseId, include: 'location', per_page: 20 }, { query: { enabled: structured } });
  const assignments = useMemo(() => (query.data?.status === 200 ? query.data.data.data : []), [query.data]);
  const locationsQuery = useLocationsIndex({ 'filter[warehouse_id]': warehouseId, per_page: 100, sort: 'sort_order', include: 'warehouse' }, { query: { enabled: structured && mayManage } });
  const locations = locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [];
  const store = useWarehouseLocationRolesStore();
  const update = useWarehouseLocationRolesUpdate();
  const destroy = useWarehouseLocationRolesDestroy();
  const [error, setError] = useState<string | null>(null);
  const [pendingRole, setPendingRole] = useState<string | null>(null);

  if (!structured) return null;

  async function run(role: string, action: () => Promise<unknown>) {
    setError(null);
    setPendingRole(role);
    try {
      await action();
      await query.refetch();
    } catch (failure) {
      setError(getApiErrorMessage(failure) ?? 'The role could not be changed.');
    } finally {
      setPendingRole(null);
    }
  }

  return (
    <DetailSection title="Location roles" description="Which location plays each operational role in this warehouse. One location per role." fullWidth>
      <div className="space-y-3 sm:col-span-2" data-testid="location-roles">
        {error && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}
        {query.isError ? (
          <ListError message="Couldn't load the location roles." error={query.error} onRetry={() => void query.refetch()} />
        ) : query.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {LOCATION_ROLES.map((role) => {
              const current = assignments.find((assignment) => assignment.role === role.value);
              const busy = pendingRole === role.value;

              return (
                <li key={role.value} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" data-testid={`location-role-${role.value}`}>
                  <div className="min-w-0">
                    <p className="font-medium">{role.label}</p>
                    <p className="text-muted-foreground">{current ? (current.location ? formatLocation(current.location) : `Location #${current.location_id}`) : 'Not assigned'}</p>
                  </div>
                  {mayManage && (
                    <div className="flex flex-wrap items-center gap-2">
                      <Combobox
                        {...lookupState(locationsQuery, 'locations')}
                        className="h-11 w-56 md:h-9"
                        aria-label={`${role.label} location`}
                        disabled={busy}
                        value={current ? String(current.location_id) : ''}
                        onValueChange={(value) => {
                          if (!value || (current && String(current.location_id) === value)) return;
                          void run(role.value, () =>
                            current
                              ? update.mutateAsync({ warehouseLocationRole: current.id, data: { location_id: Number(value) } })
                              : store.mutateAsync({ data: { warehouse_id: warehouseId, location_id: Number(value), role: role.value } }),
                          );
                        }}
                        placeholder={current ? 'Change location…' : 'Assign a location…'}
                        searchPlaceholder="Search locations…"
                        emptyText="No locations found."
                        options={locations.map((location) => ({ value: String(location.id), label: formatLocation(location) ?? location.code }))}
                      />
                      {current && (
                        <Button type="button" variant="outline" size="sm" className="h-11 md:h-9" disabled={busy} aria-label={`Clear ${role.label} location`} onClick={() => void run(role.value, () => destroy.mutateAsync({ warehouseLocationRole: current.id }))}>
                          Clear
                        </Button>
                      )}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </DetailSection>
  );
}
