[← Architecture](README.md)

# Backup and restore

What is backed up, where it lives, how long it is kept, how it is proven, and how to get data back. Decision and
alternatives: [ADR 0130](../decisions/0130-back-up-locally-first-through-a-read-only-role-with-pluggable-off-site-copies.md).

## What exists today (2026-10-14)

| Item | State |
|---|---|
| Nightly dump of the `erp` database | **Live.** 02:30 Pakistan time (21:30 UTC) on mainvm, systemd `erp-backup.timer` |
| Weekly restore test | **Live.** Sunday 03:30 Pakistan time, `erp-backup-restore-test.timer` |
| Local copies | `/srv/apps/erp/backups/` on mainvm, owner-only (`700`/`600`) |
| Off-site copy | **Not yet.** The hook exists ([destinations.d](../../deploy/backup/destinations.d/README.md)); Google Drive or cloud storage plugs in there |
| Application files, `.env`, certificates, Grafana/Loki data | **Not yet** (see below) |

Recovery point: up to **24 hours** of data can be lost (the last nightly dump). Point-in-time recovery needs WAL
archiving on the shared PostgreSQL server and is a later option, not part of this.

## How a backup works

[`deploy/backup/backup.sh`](../../deploy/backup/backup.sh), run by the timer (or by hand):

1. Takes a lock so two runs never overlap.
2. Runs `pg_dump -Fc --no-owner --no-privileges` in a throwaway `postgres:16-alpine` container, connecting to dbvm as the
   **`erp_backup`** role. The password reaches the container through the environment, never a command line.
3. Rejects a broken dump: empty, or fewer than 50 catalogue objects.
4. Writes `erp-<UTC timestamp>.dump`, a `.sha256` and a `.json` manifest (size, objects, table count, checksum).
5. Records the outcome in a state file and in Prometheus metrics (a failure never erases the last success).
6. Applies retention, then offers the dump to every destination hook.

A failed run exits non-zero, leaves no partial file, and shows as a failed systemd unit and a red panel.

**Retention** ([`prune.sh`](../../deploy/backup/prune.sh), tested by `test-prune.sh`): the newest 14 dumps, plus the newest of each of
the last 8 ISO weeks, plus the newest of each of the last 12 months. The newest dump is never deleted. Only files named
`erp-<timestamp>.dump` (and their companions) are ever touched, so hand-made dumps with other names are left alone.

## The backup role

`erp_backup` ([create-backup-role.sql](../../deploy/backup/create-backup-role.sql)): login only, not a superuser, cannot create databases or roles,
`CONNECTION LIMIT 2`, `default_transaction_read_only`, 30-minute statement timeout, `SELECT` on every table and sequence in
`erp`'s `public` schema, and default privileges so tables created by future migrations are covered automatically.
It has no write privileges and no access to the other applications' tables. The credentials are in
`/srv/apps/erp/backup.env` (mode 600), never in git.

## Proving a backup: the restore test

[`restore-test.sh`](../../deploy/backup/restore-test.sh): verifies the dump's checksum, starts a throwaway PostgreSQL container with
**no network**, restores the dump with `--exit-on-error`, then checks the table count against the manifest, that
`migrations` and `companies` are not empty, and reports users. It never touches the real database or the shared server.
A tampered file fails on the checksum; a bad restore fails on the first error. Result on 2026-10-14: 143 tables,
129 migrations, 1 company, 26 users restored from a fresh dump.

## Watching it

Prometheus metrics (`erp_backup_*`, from node-exporter's textfile collector) feed the **Backups** row of the
[ERP dashboard](observability.md): age of the last good backup (green within 26 h), last run result, age of the last
restore test (green within 9 days), dumps kept, size and duration. Wire a Grafana alert on
`time() - erp_backup_last_success_timestamp_seconds > 93600` once a notification channel exists.
By hand: `journalctl -u erp-backup`, `systemctl list-timers 'erp-backup*'`, `ls -la /srv/apps/erp/backups`.

## Getting data back

Always restore into a **new** database first, look, and only then decide what to do with the real one.

1. Pick the dump: `ls /srv/apps/erp/backups`, and check it: `cd /srv/apps/erp/backups && sha256sum -c erp-<stamp>.dump.sha256`.
2. **A few rows or a recent mistake:** restore into a side database and copy what you need.
   On dbvm: `docker exec postgres createdb -U postgres erp_restore`, then stream the dump in:
   `docker exec -i postgres pg_restore -U postgres -d erp_restore --no-owner --no-privileges < erp-<stamp>.dump`.
   Query it, export the rows, drop `erp_restore` when done.
3. **Whole database lost or corrupted:** stop the application (`docker compose stop app worker scheduler` in `/srv/apps/erp`),
   create an empty `erp` database owned by `erp_app`, restore with `--no-owner --role=erp_app`, re-run the grants in
   `create-backup-role.sql`, start the application, and check `php artisan migrate:status` shows nothing pending.
   Rehearse this against a scratch database before you ever need it.
4. Whatever you do, take a fresh dump of the current state first if the database still answers.

## Adding an off-site destination

Drop an executable `*.sh` into `/srv/apps/erp/backup/destinations.d/` that takes the dump path as `$1` and exits 0 when
the copy is safely stored. [`rclone.sh.example`](../../deploy/backup/destinations.d/rclone.sh.example) shows Google Drive (or any rclone remote)
with `age` encryption: the dump contains every table, including password hashes, so **encrypt before it leaves the
host and keep the private key off this host**. A failing destination is logged and shown in the metrics but never stops
the others and never removes the local copy.

## Not backed up yet (plan before off-site goes live)

- **`/srv/apps/erp/.env`** — holds `APP_KEY`; without it, encrypted values in the database cannot be read. Back it up
  encrypted, separately from the database.
- `deploy.env`, `backup.env`, Traefik's `acme.json` and compose files, Grafana's volume (dashboards are also in git).
- Uploaded files, if the application starts storing any.
- The other applications on dbvm (CRM, invoiceflow, n8n) — their databases have **no scheduled backup either**; this
  system can be pointed at them with another env file.

## Installing it on another host

Copy `deploy/backup/` to the host, create the role (`create-backup-role.sql`, password on stdin only), create
`backup.env` (mode 600) with `BACKUP_DB_HOST`, `BACKUP_DB_PORT`, `BACKUP_DB_NAME`, `BACKUP_DB_USER`, `BACKUP_DB_PASSWORD`,
run `sudo ./install.sh`, then run `backup.sh` and `restore-test.sh` once by hand. node-exporter needs
`--collector.textfile.directory=/textfile` and a read-only mount of `/var/lib/node_exporter/textfile`.

## Related

[observability.md](observability.md) · [deployment.md](deployment.md) · [environments.md](environments.md) · [ADR 0130](../decisions/0130-back-up-locally-first-through-a-read-only-role-with-pluggable-off-site-copies.md)
