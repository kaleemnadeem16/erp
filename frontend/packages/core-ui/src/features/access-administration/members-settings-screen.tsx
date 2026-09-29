import { useEffect, useMemo, useState } from "react";
import { MailPlus, RefreshCw, UserRoundCheck, UserRoundX } from "lucide-react";
import {
  useMembersAccess,
  useMembersIndex,
  useMembersReactivate,
  useMembersResendInvitation,
  useMembersStore,
  useMembersSuspend,
  useMembersUpdate,
  usePermissionsIndex,
  useRolesIndex,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
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
import { getApiErrorMessage } from "../../lib/api-errors";
import { cn } from "../../lib/utils";
import { usePermission } from "../../hooks/use-permission";
import { useAuthStore } from "../../stores/auth-store";
import { MemberStatusBadge } from "./member-status-badge";
import { PermissionSelection } from "./permission-selection";

interface RoleSummary {
  id: number;
  name: string;
}

interface Member {
  id: number;
  name: string;
  email: string;
  status: string;
  invited_at?: string | null;
  suspended_at?: string | null;
  roles: RoleSummary[];
  direct_permissions: string[];
  effective_permissions: string[];
}

interface Role extends RoleSummary {
  description?: string | null;
  permissions: string[];
  members_count?: number;
}

type StatusAction = "suspend" | "reactivate";

export function MembersSettingsScreen() {
  const currentUser = useAuthStore((state) => state.user);
  const mayManage = usePermission("administration.member.manage");
  const mayAssign = usePermission("administration.access.assign");
  const mayViewRoles = usePermission("administration.role.view");

  const membersQuery = useMembersIndex({ query: { retry: false } });
  const rolesQuery = useRolesIndex({ query: { enabled: mayViewRoles, retry: false } });
  const permissionsQuery = usePermissionsIndex({ query: { enabled: mayViewRoles, retry: false } });
  const inviteMember = useMembersStore();
  const updateMember = useMembersUpdate();
  const updateAccess = useMembersAccess();
  const suspendMember = useMembersSuspend();
  const reactivateMember = useMembersReactivate();
  const resendInvitation = useMembersResendInvitation();

  const members = (membersQuery.data?.status === 200 ? membersQuery.data.data.data : []) as Member[];
  const roles = (rolesQuery.data?.status === 200 ? rolesQuery.data.data.data : []) as Role[];
  const permissionGroups = (permissionsQuery.data?.status === 200
    ? permissionsQuery.data.data.data
    : {}) as Record<string, string[]>;

  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = members.find((member) => member.id === selectedId) ?? members[0];
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteName, setInviteName] = useState("");
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRoleIds, setInviteRoleIds] = useState<number[]>([]);
  const [inviteReason, setInviteReason] = useState("");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [roleIds, setRoleIds] = useState<number[]>([]);
  const [directPermissions, setDirectPermissions] = useState<string[]>([]);
  const [accessReason, setAccessReason] = useState("");
  const [statusAction, setStatusAction] = useState<StatusAction | null>(null);
  const [statusReason, setStatusReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!selected) return;
    setSelectedId(selected.id);
    setName(selected.name);
    setEmail(selected.email);
    setRoleIds(selected.roles.map((role) => role.id));
    setDirectPermissions(selected.direct_permissions);
    setAccessReason("");
    setError(null);
  }, [selected?.id, selected?.name, selected?.email]);

  const effectiveFromRoles = useMemo(
    () => new Set(roles.filter((role) => roleIds.includes(role.id)).flatMap((role) => role.permissions)),
    [roleIds, roles],
  );

  function refresh() {
    membersQuery.refetch();
  }

  function toggleRole(id: number, checked: boolean, target: "invite" | "access") {
    const values = target === "invite" ? inviteRoleIds : roleIds;
    const next = checked ? [...new Set([...values, id])] : values.filter((value) => value !== id);
    if (target === "invite") setInviteRoleIds(next);
    else setRoleIds(next);
  }

  function submitInvite() {
    setError(null);
    inviteMember.mutate(
      {
        data: {
          name: inviteName,
          email: inviteEmail,
          role_ids: inviteRoleIds,
          direct_permissions: [],
          reason: inviteRoleIds.length > 0 ? inviteReason : null,
        },
      },
      {
        onSuccess: () => {
          setInviteOpen(false);
          setInviteName("");
          setInviteEmail("");
          setInviteRoleIds([]);
          setInviteReason("");
          refresh();
        },
        onError: (requestError) => setError(getApiErrorMessage(requestError)),
      },
    );
  }

  function submitMetadata() {
    if (!selected) return;
    setError(null);
    updateMember.mutate(
      { member: selected.id, data: { name, email } },
      { onSuccess: refresh, onError: (requestError) => setError(getApiErrorMessage(requestError)) },
    );
  }

  function submitAccess() {
    if (!selected) return;
    setError(null);
    updateAccess.mutate(
      {
        member: selected.id,
        data: { role_ids: roleIds, direct_permissions: directPermissions, reason: accessReason },
      },
      {
        onSuccess: () => {
          setAccessReason("");
          refresh();
        },
        onError: (requestError) => setError(getApiErrorMessage(requestError)),
      },
    );
  }

  function submitStatusAction() {
    if (!selected || !statusAction) return;
    const mutation = statusAction === "suspend" ? suspendMember : reactivateMember;
    setError(null);
    mutation.mutate(
      { member: selected.id, data: { reason: statusReason } },
      {
        onSuccess: () => {
          setStatusAction(null);
          setStatusReason("");
          refresh();
        },
        onError: (requestError) => setError(getApiErrorMessage(requestError)),
      },
    );
  }

  if (membersQuery.isLoading) {
    return <div className="space-y-4 p-4 md:p-6"><Skeleton className="h-9 w-64" /><Skeleton className="h-96 w-full" /></div>;
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Members"
        description="Invite people, maintain their account status, and control company access."
        primaryAction={mayManage ? { label: "Invite member", icon: MailPlus, onClick: () => setInviteOpen(true) } : undefined}
      />

      {error && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <section className="overflow-hidden rounded-lg border border-border bg-card">
          <div className="border-b border-border px-4 py-3 text-sm font-semibold">Company members</div>
          {members.length === 0 ? (
            <p className="p-6 text-center text-sm text-muted-foreground">No members found.</p>
          ) : (
            <div className="divide-y divide-border">
              {members.map((member) => (
                <button
                  key={member.id}
                  type="button"
                  onClick={() => setSelectedId(member.id)}
                  className={cn("w-full p-4 text-left hover:bg-accent", selected?.id === member.id && "bg-accent")}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-sm font-medium">{member.name}</span>
                    <MemberStatusBadge status={member.status} />
                  </div>
                  <div className="mt-1 truncate text-xs text-muted-foreground">{member.email}</div>
                </button>
              ))}
            </div>
          )}
        </section>

        {selected && (
          <div className="space-y-4">
            <section className="rounded-lg border border-border bg-card p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2"><h2 className="font-semibold">Member details</h2><MemberStatusBadge status={selected.status} /></div>
                  <p className="mt-1 text-sm text-muted-foreground">Identity and invitation lifecycle for this company account.</p>
                </div>
                {mayManage && selected.id !== currentUser?.id && (
                  <div className="flex flex-wrap gap-2">
                    {selected.status === "INVITED" && (
                      <Button variant="outline" size="sm" disabled={resendInvitation.isPending} onClick={() => resendInvitation.mutate({ member: selected.id }, { onSuccess: refresh, onError: (requestError) => setError(getApiErrorMessage(requestError)) })}>
                        <RefreshCw className="size-4" /> Resend invitation
                      </Button>
                    )}
                    {selected.status === "SUSPENDED" ? (
                      <Button variant="outline" size="sm" onClick={() => setStatusAction("reactivate")}><UserRoundCheck className="size-4" /> Reactivate</Button>
                    ) : (
                      <Button variant="outline" size="sm" onClick={() => setStatusAction("suspend")}><UserRoundX className="size-4" /> Suspend</Button>
                    )}
                  </div>
                )}
              </div>
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div className="space-y-2"><Label htmlFor="member-name">Name</Label><Input id="member-name" value={name} disabled={!mayManage} onChange={(event) => setName(event.target.value)} /></div>
                <div className="space-y-2"><Label htmlFor="member-email">Email</Label><Input id="member-email" type="email" value={email} disabled={!mayManage} onChange={(event) => setEmail(event.target.value)} /></div>
              </div>
              {mayManage && <div className="mt-4 flex justify-end"><Button disabled={!name.trim() || !email.trim() || updateMember.isPending} onClick={submitMetadata}>{updateMember.isPending ? "Saving…" : "Save member"}</Button></div>}
            </section>

            <section className="rounded-lg border border-border bg-card p-4">
              <h2 className="font-semibold">Access</h2>
              <p className="mt-1 text-sm text-muted-foreground">Roles are the normal assignment path. Direct permissions are visible exceptions.</p>
              <div className="mt-4 space-y-3">
                <Label>Roles</Label>
                {!mayViewRoles ? <p className="text-sm text-muted-foreground">You do not have permission to inspect roles.</p> : roles.length === 0 ? <p className="text-sm text-muted-foreground">No roles have been created.</p> : (
                  <div className="grid gap-2 sm:grid-cols-2">
                    {roles.map((role) => (
                      <label key={role.id} className="flex items-start gap-2 rounded-md border border-border p-3">
                        <Checkbox checked={roleIds.includes(role.id)} disabled={!mayAssign || selected.id === currentUser?.id} onCheckedChange={(checked) => toggleRole(role.id, checked === true, "access")} />
                        <span><span className="block text-sm font-medium">{role.name}</span>{role.description && <span className="block text-xs text-muted-foreground">{role.description}</span>}</span>
                      </label>
                    ))}
                  </div>
                )}
              </div>
              {mayViewRoles && Object.keys(permissionGroups).length > 0 && (
                <div className="mt-5 space-y-2">
                  <Label>Direct permissions</Label>
                  <PermissionSelection groups={permissionGroups} value={directPermissions} onChange={setDirectPermissions} disabled={!mayAssign || selected.id === currentUser?.id} />
                  {effectiveFromRoles.size > 0 && <p className="text-xs text-muted-foreground">The selected roles also grant {effectiveFromRoles.size} permission{effectiveFromRoles.size === 1 ? "" : "s"}.</p>}
                </div>
              )}
              {mayAssign && selected.id !== currentUser?.id && (
                <div className="mt-4 space-y-2">
                  <Label htmlFor="access-reason">Reason for access change</Label>
                  <Textarea id="access-reason" value={accessReason} onChange={(event) => setAccessReason(event.target.value)} placeholder="Why is this access needed?" />
                  <div className="flex justify-end"><Button disabled={!accessReason.trim() || updateAccess.isPending} onClick={submitAccess}>{updateAccess.isPending ? "Saving…" : "Save access"}</Button></div>
                </div>
              )}
            </section>
          </div>
        )}
      </div>

      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Invite member</DialogTitle><DialogDescription>We’ll email a one-time link so the member can choose their own password.</DialogDescription></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label htmlFor="invite-name">Name</Label><Input id="invite-name" value={inviteName} onChange={(event) => setInviteName(event.target.value)} /></div>
            <div className="space-y-2"><Label htmlFor="invite-email">Email</Label><Input id="invite-email" type="email" value={inviteEmail} onChange={(event) => setInviteEmail(event.target.value)} /></div>
            {mayAssign && mayViewRoles && roles.length > 0 && (
              <div className="space-y-2"><Label>Initial roles</Label><div className="grid gap-2 sm:grid-cols-2">{roles.map((role) => <label key={role.id} className="flex items-center gap-2 rounded-md border border-border p-3 text-sm"><Checkbox checked={inviteRoleIds.includes(role.id)} onCheckedChange={(checked) => toggleRole(role.id, checked === true, "invite")} />{role.name}</label>)}</div></div>
            )}
            {inviteRoleIds.length > 0 && <div className="space-y-2"><Label htmlFor="invite-reason">Reason for initial access</Label><Textarea id="invite-reason" value={inviteReason} onChange={(event) => setInviteReason(event.target.value)} /></div>}
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setInviteOpen(false)}>Cancel</Button><Button disabled={!inviteName.trim() || !inviteEmail.trim() || (inviteRoleIds.length > 0 && !inviteReason.trim()) || inviteMember.isPending} onClick={submitInvite}>{inviteMember.isPending ? "Sending…" : "Send invitation"}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={statusAction !== null} onOpenChange={(open) => !open && setStatusAction(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{statusAction === "suspend" ? "Suspend member" : "Reactivate member"}</DialogTitle><DialogDescription>{statusAction === "suspend" ? "The member will immediately lose access. Their history is retained." : "The member will be able to sign in again."}</DialogDescription></DialogHeader>
          <div className="space-y-2"><Label htmlFor="status-reason">Reason</Label><Textarea id="status-reason" value={statusReason} onChange={(event) => setStatusReason(event.target.value)} /></div>
          <DialogFooter><Button variant="outline" onClick={() => setStatusAction(null)}>Cancel</Button><Button disabled={!statusReason.trim() || suspendMember.isPending || reactivateMember.isPending} onClick={submitStatusAction}>{statusAction === "suspend" ? "Suspend" : "Reactivate"}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
