import { Link } from "react-router";

import { PageHeader } from "../../components/page/page-header";
import { canAccessScreen } from "../../routes/screen-access";
import { useAuthStore } from "../../stores/auth-store";
import {
  SETTINGS_GROUPS,
  SETTINGS_KIND_LABEL,
  type SettingsEntry,
} from "./settings-catalogue";

/**
 * Every Settings page in one place, grouped by business area. It shows only what the signed-in person may open (same screen rule and extra duty the
 * top-bar menu applies) and links to the module-owned screens; it holds no settings of its own.
 */
export function SettingsIndexScreen() {
  const user = useAuthStore((state) => state.user);
  const permissions = new Set(
    Array.isArray(user?.permissions)
      ? user.permissions.filter(
          (permission): permission is string => typeof permission === "string",
        )
      : [],
  );
  const mayOpen = (entry: SettingsEntry) =>
    canAccessScreen(entry.to, permissions) &&
    (!entry.permission || permissions.has(entry.permission));

  const groups = SETTINGS_GROUPS.map((group) => ({
    ...group,
    entries: group.entries.filter(mayOpen),
  })).filter((group) => group.entries.length > 0);

  return (
    <div className="space-y-6" data-testid="settings-index">
      <PageHeader
        title="Settings"
        description="Company policies, fixed system rules and your own preferences. Pages you may not open are not listed."
      />
      {groups.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No settings are available to you.
        </p>
      )}
      {groups.map((group) => (
        <section
          key={group.label}
          aria-labelledby={`settings-group-${group.label}`}
          className="space-y-3"
        >
          <div>
            <h2
              id={`settings-group-${group.label}`}
              className="text-base font-semibold"
            >
              {group.label}
            </h2>
            <p className="text-sm text-muted-foreground">{group.description}</p>
          </div>
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {group.entries.map((entry) => (
              <li key={entry.to}>
                <Link
                  to={entry.to}
                  data-testid={`settings-link-${entry.to.replace("/settings/", "")}`}
                  className="flex h-full gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <entry.icon
                    className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                  />
                  <span className="min-w-0 space-y-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-medium">{entry.label}</span>
                      <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                        {SETTINGS_KIND_LABEL[entry.kind]}
                      </span>
                    </span>
                    <span className="block text-sm text-muted-foreground">
                      {entry.description}
                    </span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
