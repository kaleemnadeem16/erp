import { useMemo, useState } from "react";
import {
  useMembersIndex,
  useOrganisationUnitsDestroy,
  useOrganisationUnitsIndex,
  useOrganisationUnitsMembers,
  useOrganisationUnitsResources,
  useOrganisationUnitsStore,
  useOrganisationUnitsUpdate,
  useWarehousesIndex,
  type OrganisationUnitResource,
} from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { getApiErrorMessage } from "../../lib/api-errors";
import { usePermission } from "../../hooks/use-permission";

type Unit = OrganisationUnitResource;

/** Units in tree order (a parent, then its children), each with its depth, so the flat API list reads as a tree. */
function inTreeOrder(units: Unit[]): Array<{ unit: Unit; depth: number }> {
  const byParent = new Map<number | null, Unit[]>();
  for (const unit of units) {
    const key = unit.parent_id ?? null;
    byParent.set(key, [...(byParent.get(key) ?? []), unit]);
  }
  const ordered: Array<{ unit: Unit; depth: number }> = [];
  const visit = (parent: number | null, depth: number) => {
    for (const unit of byParent.get(parent) ?? []) {
      ordered.push({ unit, depth });
      visit(unit.id, depth + 1);
    }
  };
  visit(null, 0);
  // A unit whose parent is missing from the list (should not happen) is still shown rather than lost.
  for (const unit of units)
    if (!ordered.some((o) => o.unit.id === unit.id))
      ordered.push({ unit, depth: 0 });
  return ordered;
}

/**
 * Settings → Organisation (ADR 0089): the company's own tree of sites, departments and teams, who belongs to each, and which
 * warehouses each owns. Nothing here changes anyone's access by itself: it matters only for access an administrator limits to
 * "only my units" on the Members screen. A company that never adds a unit is unaffected.
 */
export function OrganisationSettingsScreen() {
  const mayManage = usePermission("administration.organisation.manage");
  const unitsQuery = useOrganisationUnitsIndex({ query: { retry: false } });
  const units: Unit[] =
    unitsQuery.data?.status === 200 ? unitsQuery.data.data.data : [];
  const rows = useMemo(() => inTreeOrder(units), [units]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = units.find((u) => u.id === selectedId) ?? null;

  const store = useOrganisationUnitsStore();
  const [adding, setAdding] = useState(false);
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [parent, setParent] = useState("");
  const [error, setError] = useState<string | null>(null);

  function refresh() {
    void unitsQuery.refetch();
  }

  function create() {
    setError(null);
    store.mutate(
      {
        data: {
          code: code.trim(),
          name: name.trim(),
          parent_id: parent === "" ? null : Number(parent),
        },
      },
      {
        onSuccess: (result) => {
          setAdding(false);
          setCode("");
          setName("");
          setParent("");
          if (result.status === 201) setSelectedId(result.data.data.id);
          refresh();
        },
        onError: (e) => setError(getApiErrorMessage(e)),
      },
    );
  }

  return (
    <div className="space-y-6 p-4 pb-24 md:p-6">
      <PageHeader
        title="Organisation"
        description="Your sites, departments and teams, who belongs to each, and the warehouses each owns. Access follows this only where you limit it to “only my units”."
        actions={
          mayManage ? (
            <Button type="button" onClick={() => setAdding((v) => !v)}>
              {adding ? "Cancel" : "New unit"}
            </Button>
          ) : undefined
        }
      />
      {error && (
        <div
          role="alert"
          className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
          {error}
        </div>
      )}

      {adding && mayManage && (
        <section
          className="grid gap-3 rounded-lg border p-4 sm:grid-cols-3"
          aria-label="New unit"
        >
          <div className="space-y-1">
            <Label htmlFor="unit-code">Code</Label>
            <Input
              id="unit-code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="khi"
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="unit-name">Name</Label>
            <Input
              id="unit-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Karachi site"
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="unit-parent">Inside</Label>
            <select
              id="unit-parent"
              className="h-10 w-full rounded-md border bg-background px-3 text-sm"
              value={parent}
              onChange={(e) => setParent(e.target.value)}
            >
              <option value="">Top level</option>
              {rows.map(({ unit, depth }) => (
                <option key={unit.id} value={unit.id}>
                  {"  ".repeat(depth)}
                  {unit.name}
                </option>
              ))}
            </select>
          </div>
          <div className="sm:col-span-3">
            <Button
              type="button"
              disabled={
                store.isPending || code.trim() === "" || name.trim() === ""
              }
              onClick={create}
            >
              {store.isPending ? "Adding…" : "Add unit"}
            </Button>
          </div>
        </section>
      )}

      {unitsQuery.isError ? (
        <p className="text-sm text-muted-foreground">
          Couldn’t load the organisation.
        </p>
      ) : units.length === 0 ? (
        <p className="rounded-lg border p-4 text-sm text-muted-foreground">
          No units yet. Everyone has company-wide access to what their roles
          allow, which is right for a company that works as one. Add units when
          people should only reach their own site or warehouse.
        </p>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
          <ul
            className="divide-y rounded-lg border"
            aria-label="Organisation units"
          >
            {rows.map(({ unit, depth }) => (
              <li key={unit.id}>
                <button
                  type="button"
                  onClick={() => setSelectedId(unit.id)}
                  aria-pressed={unit.id === selectedId}
                  className={`flex w-full items-center justify-between gap-3 p-3 text-left text-sm hover:bg-muted/50 ${unit.id === selectedId ? "bg-muted" : ""}`}
                  style={{ paddingLeft: `${0.75 + depth * 1.25}rem` }}
                >
                  <span className="min-w-0">
                    <span className="font-medium">{unit.name}</span>{" "}
                    <span className="text-xs text-muted-foreground">
                      {unit.code}
                    </span>
                    {unit.status === "ARCHIVED" && (
                      <Badge variant="secondary" className="ml-2">
                        Archived
                      </Badge>
                    )}
                  </span>
                  <span className="shrink-0 text-xs text-muted-foreground">
                    {unit.members.length} people · {unit.resources.length}{" "}
                    warehouses
                  </span>
                </button>
              </li>
            ))}
          </ul>
          {selected ? (
            <UnitPanel
              key={selected.id}
              unit={selected}
              units={units}
              mayManage={mayManage}
              onChanged={refresh}
              onDeleted={() => {
                setSelectedId(null);
                refresh();
              }}
            />
          ) : (
            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
              Choose a unit to see who belongs to it and which warehouses it
              owns.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function UnitPanel({
  unit,
  units,
  mayManage,
  onChanged,
  onDeleted,
}: {
  unit: Unit;
  units: Unit[];
  mayManage: boolean;
  onChanged: () => void;
  onDeleted: () => void;
}) {
  const mayListMembers = usePermission("administration.member.view");
  const membersQuery = useMembersIndex(
    { per_page: 100 },
    { query: { enabled: mayListMembers, retry: false } },
  );
  const people =
    membersQuery.data?.status === 200 ? membersQuery.data.data.data : [];
  const warehousesQuery = useWarehousesIndex({ per_page: 100 });
  const warehouses =
    warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const update = useOrganisationUnitsUpdate();
  const setMembers = useOrganisationUnitsMembers();
  const setResources = useOrganisationUnitsResources();
  const destroy = useOrganisationUnitsDestroy();

  const [name, setName] = useState(unit.name);
  const [status, setStatus] = useState(unit.status);
  const [memberIds, setMemberIds] = useState<number[]>(
    unit.members.map((m) => m.id),
  );
  const [warehouseIds, setWarehouseIds] = useState<number[]>(
    unit.resources.filter((r) => r.type === "warehouse").map((r) => r.id),
  );
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const fail = (e: unknown) => {
    setError(getApiErrorMessage(e));
    setNotice(null);
  };
  const done = (message: string) => {
    setError(null);
    setNotice(message);
    onChanged();
  };
  const toggle = (list: number[], id: number, on: boolean) =>
    on ? [...list, id] : list.filter((x) => x !== id);
  const takenElsewhere = (warehouseId: number) =>
    units.find(
      (u) =>
        u.id !== unit.id &&
        u.resources.some((r) => r.type === "warehouse" && r.id === warehouseId),
    );

  return (
    <section
      className="space-y-5 rounded-lg border p-4"
      aria-label={`Unit ${unit.name}`}
    >
      {error && (
        <div
          role="alert"
          className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
          {error}
        </div>
      )}
      {notice && <p className="text-sm text-muted-foreground">{notice}</p>}

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          <Label htmlFor="panel-name">Name</Label>
          <Input
            id="panel-name"
            value={name}
            disabled={!mayManage}
            onChange={(e) => setName(e.target.value)}
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor="panel-status">Status</Label>
          <select
            id="panel-status"
            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            value={status}
            disabled={!mayManage}
            onChange={(e) => setStatus(e.target.value)}
          >
            <option value="ACTIVE">Active</option>
            <option value="ARCHIVED">Archived (gives no access)</option>
          </select>
        </div>
        {mayManage && (
          <div className="sm:col-span-2">
            <Button
              type="button"
              variant="outline"
              disabled={
                update.isPending ||
                (name === unit.name && status === unit.status)
              }
              onClick={() =>
                update.mutate(
                  {
                    organisationUnit: unit.id,
                    data: { name: name.trim(), status: status as never },
                  },
                  { onSuccess: () => done("Saved."), onError: fail },
                )
              }
            >
              Save unit
            </Button>
          </div>
        )}
      </div>

      <div className="space-y-2">
        <h3 className="font-semibold">People in this unit</h3>
        <p className="text-sm text-muted-foreground">
          Belonging gives no access by itself. It decides what someone reaches
          when an assignment is limited to “only my units”; a site includes its
          sub-units.
        </p>
        {!mayListMembers ? (
          <p className="text-sm text-muted-foreground">
            {unit.members.map((m) => m.name).join(", ") || "Nobody yet."} You
            need access to the member list to change this.
          </p>
        ) : (
          <ul className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-2">
            {people.map((person) => (
              <li key={person.id}>
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={memberIds.includes(person.id)}
                    disabled={!mayManage}
                    onCheckedChange={(on) =>
                      setMemberIds(toggle(memberIds, person.id, on === true))
                    }
                  />
                  {person.name}{" "}
                  <span className="text-xs text-muted-foreground">
                    {person.email}
                  </span>
                </label>
              </li>
            ))}
          </ul>
        )}
        {mayManage && mayListMembers && (
          <Button
            type="button"
            variant="outline"
            disabled={setMembers.isPending}
            onClick={() =>
              setMembers.mutate(
                {
                  organisationUnit: unit.id,
                  data: { user_ids: memberIds },
                },
                { onSuccess: () => done("People saved."), onError: fail },
              )
            }
          >
            Save people
          </Button>
        )}
      </div>

      <div className="space-y-2">
        <h3 className="font-semibold">Warehouses this unit owns</h3>
        <p className="text-sm text-muted-foreground">
          A warehouse belongs to one unit. People limited to their units reach
          the stock and counts of these warehouses only; a warehouse owned by no
          unit is reached by unrestricted people only.
        </p>
        <ul className="space-y-1 rounded-md border p-2">
          {warehouses.map((warehouse) => {
            const owner = takenElsewhere(warehouse.id);
            return (
              <li key={warehouse.id}>
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={warehouseIds.includes(warehouse.id)}
                    disabled={!mayManage || owner !== undefined}
                    onCheckedChange={(on) =>
                      setWarehouseIds(
                        toggle(warehouseIds, warehouse.id, on === true),
                      )
                    }
                  />
                  {warehouse.name}
                  {owner && (
                    <span className="text-xs text-muted-foreground">
                      (belongs to {owner.name})
                    </span>
                  )}
                </label>
              </li>
            );
          })}
        </ul>
        {mayManage && (
          <Button
            type="button"
            variant="outline"
            disabled={setResources.isPending}
            onClick={() =>
              setResources.mutate(
                {
                  organisationUnit: unit.id,
                  data: {
                    resources: warehouseIds.map((id) => ({
                      type: "warehouse",
                      id,
                    })),
                  },
                },
                { onSuccess: () => done("Warehouses saved."), onError: fail },
              )
            }
          >
            Save warehouses
          </Button>
        )}
      </div>

      {mayManage && (
        <div className="border-t pt-3">
          <Button
            type="button"
            variant="outline"
            disabled={destroy.isPending}
            onClick={() =>
              destroy.mutate(
                { organisationUnit: unit.id },
                { onSuccess: onDeleted, onError: fail },
              )
            }
          >
            Delete unit
          </Button>
          <p className="mt-1 text-xs text-muted-foreground">
            Only an empty unit can be deleted; archive one that has sub-units,
            people or warehouses.
          </p>
        </div>
      )}
    </section>
  );
}
