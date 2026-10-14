[← Decisions](README.md)

# 0130 — Back up locally first, through a read-only role, with pluggable off-site copies

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** [backup-and-restore.md](../architecture/backup-and-restore.md), `deploy/backup/`, the shared PostgreSQL server on dbvm

## Context

Production holds real data and had **no scheduled backup**: dbvm's only backup timer is the operating system's package
database, `/srv/backups` held one-off project copies from August, and the only ERP dumps were taken by hand before
deployments. The PostgreSQL server is shared with other applications, so a backup must not need broad privileges on it
or restart it. Off-site storage (Google Drive now, cloud storage later) is wanted but not decided.

## Decision

1. **A nightly logical dump (`pg_dump -Fc`) taken from mainvm** through a dedicated **read-only role** limited to the `erp` database,
   kept locally with a daily/weekly/monthly retention (14/8/12), each with a checksum and a manifest.
2. **A weekly restore test** into a throwaway, network-less PostgreSQL container: a backup counts only once it has been restored.
3. **Failure is loud:** no partial files, non-zero exit, a failed systemd unit, and Prometheus metrics that drive the dashboard.
4. **Off-site copies are plug-ins**: any executable `*.sh` in `destinations.d/` receives the dump path. The contract requires
   encrypting before upload. The first real destination (Google Drive via rclone) is a later, separate step.

## Consequences

- **Positive:** a restorable ERP backup exists now at no cost and without touching shared infrastructure beyond one read-only role; adding off-site storage later is one file; the same scripts can protect the other databases.
- **Negative:** recovery point is up to 24 hours; backups live on mainvm only until the off-site step is done (they protect against mistakes, not against losing mainvm and dbvm together); `.env` and other files are not covered yet.
- **Requires:** a Grafana alert once a notification channel exists; the off-site destination and an encryption key held off-host; backing up `.env` (`APP_KEY`) separately.

## Alternatives considered

**WAL archiving with point-in-time recovery (pgBackRest, wal-g)** — the stronger answer for a growing database, but it changes the shared server's configuration (`archive_mode` needs a restart) and affects every application on it; revisit when the data justifies it.
**Cloud volume snapshots** — not verified here, not restorable per database, and cannot be test-restored automatically; a useful extra, not a substitute.
**Using the application's own database role** — works, but gives a backup job write access to production data. A read-only role is a small cost.
**Uploading straight to Google Drive without a local copy** — a failed upload would mean no backup at all, and a restore would depend on a third party's availability.
**Running `pg_dump` on dbvm** — would need host-level access to the shared server and keeps the credentials and the scripts away from the application's deployment.

## Related

[backup-and-restore.md](../architecture/backup-and-restore.md) · [ADR 0128](0128-observe-with-the-existing-grafana-loki-stack.md) · [ADR 0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)
