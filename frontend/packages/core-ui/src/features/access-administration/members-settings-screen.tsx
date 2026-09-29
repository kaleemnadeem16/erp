import { useEffect, useMemo, useState } from "react";
import { Pencil, Plus } from "lucide-react";
import type { PaginationState, SortingState } from "@tanstack/react-table";
import {
  useMembersAccess,
  useMembersHistory,
  useMembersIndex,
  useMembersReactivate,
  useMembersResendInvitation,
  useMembersSendPasswordReset,
  useMembersStore,
  useMembersSuspend,
  useMembersUpdate,
  usePermissionsIndex,
  useRolesIndex,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { DataTable, toTanStackColumns } from "../../components/data-table";
import type { DataColumn } from "../../components/data-table/types";
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
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "../../components/ui/sheet";
import { PageHeader } from "../../components/page/page-header";
import { Textarea } from "../../components/ui/textarea";
import { getApiErrorMessage } from "../../lib/api-errors";
import { usePermission } from "../../hooks/use-permission";
import { useAuthStore } from "../../stores/auth-store";
import { MemberStatusBadge } from "./member-status-badge";
import { PermissionSelection } from "./permission-selection";

interface Role {
  id: number;
  name: string;
  description?: string | null;
  permissions: string[];
}
interface Member {
  id: number;
  name: string;
  email: string;
  status: string;
  created_at?: string | null;
  updated_at?: string | null;
  invited_at?: string | null;
  suspended_at?: string | null;
  roles: Role[];
  direct_permissions: string[];
  effective_permissions: string[];
}
interface HistoryEntry {
  id: number;
  description: string;
  actor: string | null;
  reason: string | null;
  created_at: string | null;
}
type StatusAction = "suspend" | "reactivate";
const dateTime = (value?: string | null) =>
  value ? new Date(value).toLocaleString() : "—";

function RoleChoices({
  roles,
  value,
  onChange,
  disabled = false,
}: {
  roles: Role[];
  value: number[];
  onChange: (ids: number[]) => void;
  disabled?: boolean;
}) {
  return roles.length ? (
    <div className="grid gap-2 sm:grid-cols-2">
      {roles.map((role) => (
        <label
          key={role.id}
          className="flex items-start gap-2 rounded-md border p-3 text-sm"
        >
          <Checkbox
            checked={value.includes(role.id)}
            disabled={disabled}
            onCheckedChange={(checked) =>
              onChange(
                checked === true
                  ? [...new Set([...value, role.id])]
                  : value.filter((id) => id !== role.id),
              )
            }
          />
          <span>
            <span className="block font-medium">{role.name}</span>
            <span className="text-xs text-muted-foreground">
              {role.description}
            </span>
          </span>
        </label>
      ))}
    </div>
  ) : (
    <p className="text-sm text-muted-foreground">
      No roles yet. Create one in Roles & Permissions first.
    </p>
  );
}

export function MembersSettingsScreen() {
  const currentUser = useAuthStore((state) => state.user);
  const mayManage = usePermission("administration.member.manage");
  const mayAssign = usePermission("administration.access.assign");
  const mayViewRoles = usePermission("administration.role.view");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("ALL");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [editing, setEditing] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const membersQuery = useMembersIndex(
    {
      search: search || undefined,
      status:
        status === "ALL"
          ? undefined
          : (status as "ACTIVE" | "INVITED" | "SUSPENDED"),
      page,
      per_page: perPage,
    },
    { query: { retry: false } },
  );
  const rolesQuery = useRolesIndex({
    query: { enabled: mayViewRoles, retry: false },
  });
  const permissionsQuery = usePermissionsIndex({
    query: { enabled: mayViewRoles, retry: false },
  });
  const historyQuery = useMembersHistory(selectedId ?? 0, {
    query: { enabled: selectedId !== null, retry: false },
  });
  const createMember = useMembersStore();
  const updateMember = useMembersUpdate();
  const updateAccess = useMembersAccess();
  const suspendMember = useMembersSuspend();
  const reactivateMember = useMembersReactivate();
  const resendInvitation = useMembersResendInvitation();
  const sendPasswordReset = useMembersSendPasswordReset();

  const members = (
    membersQuery.data?.status === 200 ? membersQuery.data.data.data : []
  ) as Member[];
  const meta =
    membersQuery.data?.status === 200 ? membersQuery.data.data.meta : null;
  const roles = (
    rolesQuery.data?.status === 200 ? rolesQuery.data.data.data : []
  ) as Role[];
  const permissionGroups = (
    permissionsQuery.data?.status === 200 ? permissionsQuery.data.data.data : {}
  ) as Record<string, string[]>;
  const history = (
    historyQuery.data?.status === 200 ? historyQuery.data.data.data : []
  ) as HistoryEntry[];
  const selected = members.find((member) => member.id === selectedId);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [roleIds, setRoleIds] = useState<number[]>([]);
  const [directPermissions, setDirectPermissions] = useState<string[]>([]);
  const [showCustom, setShowCustom] = useState(false);
  const [accessReason, setAccessReason] = useState("");
  const [createName, setCreateName] = useState("");
  const [createEmail, setCreateEmail] = useState("");
  const [createRoleIds, setCreateRoleIds] = useState<number[]>([]);
  const [createDirectPermissions, setCreateDirectPermissions] = useState<
    string[]
  >([]);
  const [createCustom, setCreateCustom] = useState(false);
  const [createReason, setCreateReason] = useState("");
  const [statusAction, setStatusAction] = useState<StatusAction | null>(null);
  const [statusReason, setStatusReason] = useState("");

  useEffect(() => {
    if (!selected) return;
    setName(selected.name);
    setEmail(selected.email);
    setRoleIds(selected.roles.map((role) => role.id));
    setDirectPermissions(selected.direct_permissions);
    setShowCustom(selected.direct_permissions.length > 0);
    setAccessReason("");
    setError(null);
  }, [selected?.id, selected?.name, selected?.email, selected?.updated_at]);

  const columns = useMemo<DataColumn<Member>[]>(
    () => [
      {
        key: "name",
        header: "Name",
        value: (member) => member.name,
        cardRole: "title",
      },
      {
        key: "email",
        header: "Email",
        value: (member) => member.email,
        cardRole: "subtitle",
      },
      {
        key: "roles",
        header: "Roles",
        value: (member) =>
          member.roles.map((role) => role.name).join(", ") || "No role",
      },
      {
        key: "status",
        header: "Status",
        value: (member) => member.status,
        cell: (member) => <MemberStatusBadge status={member.status} />,
      },
      {
        key: "created_at",
        header: "Created",
        value: (member) => dateTime(member.created_at),
        screenVisible: false,
      },
    ],
    [],
  );
  const tableColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const pagination: PaginationState = {
    pageIndex: page - 1,
    pageSize: perPage,
  };
  const sorting: SortingState = [];
  const canEditAccess =
    mayAssign && selected?.id !== currentUser?.id && mayViewRoles;
  const rolePermissionCount = new Set(
    roles
      .filter((role) => roleIds.includes(role.id))
      .flatMap((role) => role.permissions),
  ).size;
  function refresh() {
    void membersQuery.refetch();
    if (selectedId !== null) void historyQuery.refetch();
  }
  function fail(requestError: unknown) {
    setError(getApiErrorMessage(requestError));
    setNotice(null);
  }
  function openMember(member: Member, edit = false) {
    setSelectedId(member.id);
    setEditing(edit);
    setError(null);
    setNotice(null);
  }
  function submitCreate() {
    setError(null);
    createMember.mutate(
      {
        data: {
          name: createName.trim(),
          email: createEmail.trim(),
          role_ids: createRoleIds,
          direct_permissions: createDirectPermissions,
          reason:
            createRoleIds.length || createDirectPermissions.length
              ? createReason
              : null,
        },
      },
      {
        onSuccess: () => {
          setCreateOpen(false);
          setCreateName("");
          setCreateEmail("");
          setCreateRoleIds([]);
          setCreateDirectPermissions([]);
          setCreateCustom(false);
          setCreateReason("");
          setNotice("User created and activation link sent.");
          refresh();
        },
        onError: fail,
      },
    );
  }
  function submitDetails() {
    if (!selected) return;
    setError(null);
    updateMember.mutate(
      { member: selected.id, data: { name: name.trim(), email: email.trim() } },
      {
        onSuccess: () => {
          setNotice("User details saved.");
          refresh();
        },
        onError: fail,
      },
    );
  }
  function submitAccess() {
    if (!selected) return;
    setError(null);
    updateAccess.mutate(
      {
        member: selected.id,
        data: {
          role_ids: roleIds,
          direct_permissions: directPermissions,
          reason: accessReason,
        },
      },
      {
        onSuccess: () => {
          setAccessReason("");
          setNotice("Access saved.");
          refresh();
        },
        onError: fail,
      },
    );
  }
  function submitStatusAction() {
    if (!selected || !statusAction) return;
    const mutation =
      statusAction === "suspend" ? suspendMember : reactivateMember;
    setError(null);
    mutation.mutate(
      { member: selected.id, data: { reason: statusReason } },
      {
        onSuccess: () => {
          setStatusAction(null);
          setStatusReason("");
          setNotice("User status updated.");
          refresh();
        },
        onError: fail,
      },
    );
  }

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Members"
        description="Create users, assign roles and manage company access."
        primaryAction={
          mayManage
            ? {
                label: "Create user",
                icon: <Plus className="size-4" />,
                onClick: () => setCreateOpen(true),
              }
            : undefined
        }
      />
      {notice && (
        <div
          role="status"
          className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900"
        >
          {notice}
        </div>
      )}
      {error && (
        <div
          role="alert"
          className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
          {error}
        </div>
      )}
      <div className="flex flex-wrap gap-2">
        <Input
          className="max-w-sm"
          aria-label="Search users by name or email"
          placeholder="Search name or email"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
            setSelectedId(null);
          }}
        />
        <select
          aria-label="Filter users by status"
          className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
            setSelectedId(null);
          }}
        >
          <option value="ALL">All statuses</option>
          <option value="ACTIVE">Active</option>
          <option value="INVITED">Invited</option>
          <option value="SUSPENDED">Suspended</option>
        </select>
      </div>
      <DataTable
        columns={tableColumns}
        data={members}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next =
            typeof updater === "function" ? updater(pagination) : updater;
          setSelectedId(null);
          if (next.pageSize !== perPage) {
            setPerPage(next.pageSize);
            setPage(1);
          } else setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={() => {}}
        onRowClick={(member) => openMember(member)}
        rowActions={
          mayManage
            ? (member) => (
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={`Edit ${member.name}`}
                  onClick={(event) => {
                    event.stopPropagation();
                    openMember(member, true);
                  }}
                >
                  <Pencil className="size-4" />
                </Button>
              )
            : undefined
        }
        isLoading={membersQuery.isLoading}
        isError={membersQuery.isError}
        error={membersQuery.error}
        onRetry={() => void membersQuery.refetch()}
        errorMessage="Could not load users."
        emptyMessage="No users match these filters."
      />

      <Sheet
        open={selectedId !== null}
        onOpenChange={(open) => {
          if (!open) {
            setSelectedId(null);
            setEditing(false);
          }
        }}
      >
        <SheetContent
          side="right"
          className="w-full overflow-y-auto sm:max-w-2xl"
        >
          {selected && (
            <div className="space-y-5 pb-6">
              <SheetHeader>
                <SheetTitle>
                  {editing ? `Edit ${selected.name}` : selected.name}
                </SheetTitle>
                <p className="text-sm text-muted-foreground">
                  {selected.email}
                </p>
              </SheetHeader>
              <div className="flex flex-wrap items-center gap-2">
                <MemberStatusBadge status={selected.status} />
                {mayManage && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setEditing(!editing)}
                  >
                    {editing ? "View details" : "Edit user"}
                  </Button>
                )}
              </div>
              {notice && (
                <p role="status" className="text-sm text-emerald-700">
                  {notice}
                </p>
              )}
              {error && (
                <p role="alert" className="text-sm text-destructive">
                  {error}
                </p>
              )}
              {editing ? (
                <>
                  <section className="space-y-3 rounded-md border p-4">
                    <h3 className="font-semibold">Profile</h3>
                    <div className="space-y-1">
                      <Label htmlFor="member-name">Name</Label>
                      <Input
                        id="member-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                      />
                    </div>
                    <div className="space-y-1">
                      <Label htmlFor="member-email">Email</Label>
                      <Input
                        id="member-email"
                        type="email"
                        value={email}
                        onChange={(event) => setEmail(event.target.value)}
                      />
                    </div>
                    <Button
                      disabled={
                        !name.trim() || !email.trim() || updateMember.isPending
                      }
                      onClick={submitDetails}
                    >
                      {updateMember.isPending ? "Saving…" : "Save profile"}
                    </Button>
                  </section>
                  {mayViewRoles && (
                    <section className="space-y-3 rounded-md border p-4">
                      <h3 className="font-semibold">Roles</h3>
                      <p className="text-sm text-muted-foreground">
                        Assign job-function roles first. Permissions from all
                        selected roles add together.
                      </p>
                      <RoleChoices
                        roles={roles}
                        value={roleIds}
                        onChange={setRoleIds}
                        disabled={!canEditAccess}
                      />
                      <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                          checked={showCustom}
                          disabled={!canEditAccess}
                          onCheckedChange={(checked) =>
                            setShowCustom(checked === true)
                          }
                        />
                        {directPermissions.length
                          ? "Show custom permissions"
                          : "Give custom permissions"}
                      </label>
                      {showCustom && (
                        <div className="space-y-2 rounded-md border p-3">
                          <p className="text-xs text-muted-foreground">
                            Exceptional direct additions only. They cannot
                            remove permissions granted by a role.
                          </p>
                          <PermissionSelection
                            groups={permissionGroups}
                            value={directPermissions}
                            onChange={setDirectPermissions}
                            disabled={!canEditAccess}
                          />
                          <p className="text-xs text-muted-foreground">
                            Selected roles grant {rolePermissionCount}{" "}
                            permissions.
                          </p>
                        </div>
                      )}
                      {canEditAccess && (
                        <div className="space-y-2">
                          <Label htmlFor="access-reason">
                            Reason for access change
                          </Label>
                          <Textarea
                            id="access-reason"
                            value={accessReason}
                            onChange={(event) =>
                              setAccessReason(event.target.value)
                            }
                          />
                          <Button
                            disabled={
                              !accessReason.trim() || updateAccess.isPending
                            }
                            onClick={submitAccess}
                          >
                            {updateAccess.isPending ? "Saving…" : "Save access"}
                          </Button>
                        </div>
                      )}
                    </section>
                  )}
                </>
              ) : (
                <>
                  <section className="space-y-2 rounded-md border p-4">
                    <h3 className="font-semibold">User details</h3>
                    <dl className="grid grid-cols-[8rem_1fr] gap-2 text-sm">
                      <dt className="text-muted-foreground">Name</dt>
                      <dd>{selected.name}</dd>
                      <dt className="text-muted-foreground">Email</dt>
                      <dd>{selected.email}</dd>
                      <dt className="text-muted-foreground">Created</dt>
                      <dd>{dateTime(selected.created_at)}</dd>
                      <dt className="text-muted-foreground">Last updated</dt>
                      <dd>{dateTime(selected.updated_at)}</dd>
                      <dt className="text-muted-foreground">Invited</dt>
                      <dd>{dateTime(selected.invited_at)}</dd>
                      <dt className="text-muted-foreground">Suspended</dt>
                      <dd>{dateTime(selected.suspended_at)}</dd>
                    </dl>
                  </section>
                  <section className="space-y-2 rounded-md border p-4">
                    <h3 className="font-semibold">Access</h3>
                    <p className="text-sm">
                      {selected.roles.length
                        ? selected.roles.map((role) => role.name).join(", ")
                        : "No roles assigned"}
                    </p>
                    {selected.direct_permissions.length > 0 && (
                      <p className="text-xs text-muted-foreground">
                        {selected.direct_permissions.length} custom
                        permission(s)
                      </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                      {selected.effective_permissions.length} effective
                      permission(s)
                    </p>
                  </section>
                  <section className="space-y-2 rounded-md border p-4">
                    <h3 className="font-semibold">History</h3>
                    {historyQuery.isLoading ? (
                      <p className="text-sm text-muted-foreground">
                        Loading history…
                      </p>
                    ) : historyQuery.isError ? (
                      <p className="text-sm text-destructive">
                        Could not load history.
                      </p>
                    ) : history.length === 0 ? (
                      <p className="text-sm text-muted-foreground">
                        No account changes recorded yet.
                      </p>
                    ) : (
                      <ol className="space-y-3">
                        {history.map((entry) => (
                          <li
                            key={entry.id}
                            className="border-l-2 pl-3 text-sm"
                          >
                            <span className="font-medium">
                              {entry.description}
                            </span>
                            <div className="text-xs text-muted-foreground">
                              {dateTime(entry.created_at)} ·{" "}
                              {entry.actor ?? "System"}
                            </div>
                            {entry.reason && <p>{entry.reason}</p>}
                          </li>
                        ))}
                      </ol>
                    )}
                  </section>
                </>
              )}
              {mayManage && selected.id !== currentUser?.id && (
                <section className="space-y-2 rounded-md border p-4">
                  <h3 className="font-semibold">Account actions</h3>
                  <div className="flex flex-wrap gap-2">
                    {selected.status === "INVITED" && (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={resendInvitation.isPending}
                        onClick={() =>
                          resendInvitation.mutate(
                            { member: selected.id },
                            {
                              onSuccess: () => {
                                setNotice("Invitation sent again.");
                                refresh();
                              },
                              onError: fail,
                            },
                          )
                        }
                      >
                        Resend invitation
                      </Button>
                    )}
                    {selected.status === "ACTIVE" && (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={sendPasswordReset.isPending}
                        onClick={() =>
                          sendPasswordReset.mutate(
                            { member: selected.id },
                            {
                              onSuccess: () => {
                                setNotice("Password reset link sent.");
                                refresh();
                              },
                              onError: fail,
                            },
                          )
                        }
                      >
                        Send password reset link
                      </Button>
                    )}
                    {selected.status === "SUSPENDED" ? (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setStatusAction("reactivate")}
                      >
                        Reactivate
                      </Button>
                    ) : (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setStatusAction("suspend")}
                      >
                        Suspend
                      </Button>
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Administrators never see or choose another user’s password.
                  </p>
                </section>
              )}
            </div>
          )}
        </SheetContent>
      </Sheet>

      <Sheet open={createOpen} onOpenChange={setCreateOpen}>
        <SheetContent
          side="right"
          className="w-full overflow-y-auto sm:max-w-2xl"
        >
          <SheetHeader>
            <SheetTitle>Create user</SheetTitle>
            <p className="text-sm text-muted-foreground">
              Create the account and choose its initial roles. The user receives
              a private activation link to set their password.
            </p>
          </SheetHeader>
          <div className="space-y-4 pb-6">
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
            <div className="space-y-1">
              <Label htmlFor="create-name">Name</Label>
              <Input
                id="create-name"
                value={createName}
                onChange={(event) => setCreateName(event.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="create-email">Email</Label>
              <Input
                id="create-email"
                type="email"
                value={createEmail}
                onChange={(event) => setCreateEmail(event.target.value)}
              />
            </div>
            {mayAssign && mayViewRoles && (
              <>
                <div className="space-y-2">
                  <Label>Initial roles</Label>
                  <RoleChoices
                    roles={roles}
                    value={createRoleIds}
                    onChange={setCreateRoleIds}
                  />
                </div>
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={createCustom}
                    onCheckedChange={(checked) =>
                      setCreateCustom(checked === true)
                    }
                  />
                  Give custom permissions
                </label>
                {createCustom && (
                  <div className="rounded-md border p-3">
                    <PermissionSelection
                      groups={permissionGroups}
                      value={createDirectPermissions}
                      onChange={setCreateDirectPermissions}
                    />
                  </div>
                )}
                {(createRoleIds.length > 0 ||
                  createDirectPermissions.length > 0) && (
                  <div className="space-y-1">
                    <Label htmlFor="create-reason">
                      Reason for initial access
                    </Label>
                    <Textarea
                      id="create-reason"
                      value={createReason}
                      onChange={(event) => setCreateReason(event.target.value)}
                    />
                  </div>
                )}
              </>
            )}
            <Button
              disabled={
                !createName.trim() ||
                !createEmail.trim() ||
                ((createRoleIds.length > 0 ||
                  createDirectPermissions.length > 0) &&
                  !createReason.trim()) ||
                createMember.isPending
              }
              onClick={submitCreate}
            >
              {createMember.isPending
                ? "Creating…"
                : "Create user & send activation link"}
            </Button>
          </div>
        </SheetContent>
      </Sheet>

      <Dialog
        open={statusAction !== null}
        onOpenChange={(open) => !open && setStatusAction(null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {statusAction === "suspend" ? "Suspend user" : "Reactivate user"}
            </DialogTitle>
            <DialogDescription>
              {statusAction === "suspend"
                ? "The user immediately loses access. Their history is retained."
                : "The user can sign in again."}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="status-reason">Reason</Label>
            <Textarea
              id="status-reason"
              value={statusReason}
              onChange={(event) => setStatusReason(event.target.value)}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setStatusAction(null)}>
              Cancel
            </Button>
            <Button
              disabled={
                !statusReason.trim() ||
                suspendMember.isPending ||
                reactivateMember.isPending
              }
              onClick={submitStatusAction}
            >
              {statusAction === "suspend" ? "Suspend" : "Reactivate"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
