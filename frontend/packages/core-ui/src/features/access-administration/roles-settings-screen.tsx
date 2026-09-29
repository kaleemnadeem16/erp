import { useEffect, useState } from "react";
import { Plus, ShieldCheck, Trash2 } from "lucide-react";
import {
  usePermissionsIndex,
  useRolesDestroy,
  useRolesIndex,
  useRolesStore,
  useRolesUpdate,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { PageHeader } from "../../components/page/page-header";
import { Skeleton } from "../../components/ui/skeleton";
import { Textarea } from "../../components/ui/textarea";
import { usePermission } from "../../hooks/use-permission";
import { getApiErrorMessage } from "../../lib/api-errors";
import { cn } from "../../lib/utils";
import { PermissionSelection } from "./permission-selection";

interface Role {
  id: number;
  name: string;
  description?: string | null;
  permissions: string[];
  members_count?: number;
}

export function RolesSettingsScreen() {
  const mayManage = usePermission("administration.role.manage");
  const rolesQuery = useRolesIndex({ query: { retry: false } });
  const permissionsQuery = usePermissionsIndex({ query: { retry: false } });
  const createRole = useRolesStore();
  const updateRole = useRolesUpdate();
  const deleteRole = useRolesDestroy();

  const roles = (rolesQuery.data?.status === 200 ? rolesQuery.data.data.data : []) as Role[];
  const permissionGroups = (permissionsQuery.data?.status === 200
    ? permissionsQuery.data.data.data
    : {}) as Record<string, string[]>;

  const [selectedId, setSelectedId] = useState<number | "new" | null>(null);
  const selected = selectedId === "new" ? undefined : roles.find((role) => role.id === selectedId) ?? roles[0];
  const isNew = selectedId === "new" || (roles.length === 0 && selectedId === null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [permissions, setPermissions] = useState<string[]>([]);
  const [reason, setReason] = useState("");
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteReason, setDeleteReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isNew) {
      setName("");
      setDescription("");
      setPermissions([]);
      setReason("");
      return;
    }
    if (!selected) return;
    setSelectedId(selected.id);
    setName(selected.name);
    setDescription(selected.description ?? "");
    setPermissions(selected.permissions);
    setReason("");
    setError(null);
  }, [isNew, selected?.id, selected?.name, selected?.description]);

  function refresh() {
    rolesQuery.refetch();
  }

  function submitRole() {
    const data = { name, description: description || null, permissions, reason };
    const options = {
      onSuccess: () => {
        setSelectedId(null);
        refresh();
      },
      onError: (requestError: unknown) => setError(getApiErrorMessage(requestError)),
    };
    setError(null);
    if (isNew) createRole.mutate({ data }, options);
    else if (selected) updateRole.mutate({ role: selected.id, data }, options);
  }

  function submitDelete() {
    if (!selected) return;
    setError(null);
    deleteRole.mutate(
      { role: selected.id, data: { reason: deleteReason } },
      {
        onSuccess: () => {
          setDeleteOpen(false);
          setDeleteReason("");
          setSelectedId(null);
          refresh();
        },
        onError: (requestError) => setError(getApiErrorMessage(requestError)),
      },
    );
  }

  if (rolesQuery.isLoading || permissionsQuery.isLoading) {
    return <div className="space-y-4 p-4 md:p-6"><Skeleton className="h-9 w-72" /><Skeleton className="h-96 w-full" /></div>;
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Roles & permissions"
        description="Build reusable job-function roles from the company’s registered capabilities."
        primaryAction={mayManage ? { label: "New role", icon: Plus, onClick: () => setSelectedId("new") } : undefined}
      />

      {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <section className="overflow-hidden rounded-lg border border-border bg-card">
          <div className="border-b border-border px-4 py-3 text-sm font-semibold">Company roles</div>
          {roles.length === 0 ? (
            <div className="p-6 text-center"><ShieldCheck className="mx-auto size-8 text-muted-foreground" /><p className="mt-2 text-sm text-muted-foreground">No roles have been created.</p>{mayManage && <Button className="mt-4" size="sm" onClick={() => setSelectedId("new")}><Plus className="size-4" /> Create first role</Button>}</div>
          ) : (
            <div className="divide-y divide-border">
              {roles.map((role) => (
                <button key={role.id} type="button" onClick={() => setSelectedId(role.id)} className={cn("w-full p-4 text-left hover:bg-accent", !isNew && selected?.id === role.id && "bg-accent")}>
                  <span className="block truncate text-sm font-medium">{role.name}</span>
                  <span className="mt-1 block text-xs text-muted-foreground">{role.members_count ?? 0} member{role.members_count === 1 ? "" : "s"} · {role.permissions.length} permission{role.permissions.length === 1 ? "" : "s"}</span>
                </button>
              ))}
            </div>
          )}
        </section>

        {(selected || isNew) && (
          <section className="rounded-lg border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
              <div><h2 className="font-semibold">{isNew ? "New role" : selected?.name}</h2><p className="mt-1 text-sm text-muted-foreground">A role grants every selected permission to each assigned member.</p></div>
              {mayManage && selected && !isNew && <Button variant="outline" size="sm" onClick={() => setDeleteOpen(true)}><Trash2 className="size-4" /> Delete</Button>}
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <div className="space-y-2"><Label htmlFor="role-name">Name</Label><Input id="role-name" value={name} disabled={!mayManage} placeholder="e.g. Purchasing Manager" onChange={(event) => setName(event.target.value)} /></div>
              <div className="space-y-2"><Label htmlFor="role-description">Description</Label><Input id="role-description" value={description} disabled={!mayManage} placeholder="What this role is for" onChange={(event) => setDescription(event.target.value)} /></div>
            </div>

            <div className="mt-5 space-y-2">
              <Label>Permissions</Label>
              <PermissionSelection groups={permissionGroups} value={permissions} onChange={setPermissions} disabled={!mayManage} />
            </div>

            {mayManage && (
              <div className="mt-5 space-y-2">
                <Label htmlFor="role-reason">Reason for {isNew ? "creating" : "changing"} this role</Label>
                <Textarea id="role-reason" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Explain the business need for this access." />
                <div className="flex justify-end"><Button disabled={!name.trim() || !reason.trim() || createRole.isPending || updateRole.isPending} onClick={submitRole}>{createRole.isPending || updateRole.isPending ? "Saving…" : isNew ? "Create role" : "Save role"}</Button></div>
              </div>
            )}
          </section>
        )}
      </div>

      <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Delete role</DialogTitle><DialogDescription>This is only allowed when no members are assigned. Existing audit history is retained.</DialogDescription></DialogHeader>
          <div className="space-y-2"><Label htmlFor="delete-role-reason">Reason</Label><Textarea id="delete-role-reason" value={deleteReason} onChange={(event) => setDeleteReason(event.target.value)} /></div>
          <DialogFooter><Button variant="outline" onClick={() => setDeleteOpen(false)}>Cancel</Button><Button variant="destructive" disabled={!deleteReason.trim() || deleteRole.isPending} onClick={submitDelete}>{deleteRole.isPending ? "Deleting…" : "Delete role"}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
