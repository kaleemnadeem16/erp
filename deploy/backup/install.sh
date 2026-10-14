#!/usr/bin/env bash
# Install the backup system on mainvm. Run from a copy of this directory:  sudo ./install.sh
# Copies the scripts to /srv/apps/erp/backup, installs the systemd units, creates the metrics directory and enables the timers.
# It does NOT create the database role or backup.env (those hold secrets): see docs/architecture/backup-and-restore.md.
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST="${BACKUP_INSTALL_DIR:-/srv/apps/erp/backup}"
OWNER="${BACKUP_OWNER:-ubuntu}"

install -d -m 755 -o "$OWNER" -g "$OWNER" "$DEST" "$DEST/destinations.d"
for f in backup.sh restore-test.sh prune.sh test-prune.sh lib.sh; do install -m 755 -o "$OWNER" -g "$OWNER" "$SRC/$f" "$DEST/$f"; done
install -m 644 -o "$OWNER" -g "$OWNER" "$SRC/destinations.d/README.md" "$DEST/destinations.d/README.md"
install -m 644 -o "$OWNER" -g "$OWNER" "$SRC/destinations.d/rclone.sh.example" "$DEST/destinations.d/rclone.sh.example"
install -d -m 755 -o "$OWNER" -g "$OWNER" /var/lib/node_exporter/textfile
for u in erp-backup.service erp-backup.timer erp-backup-restore-test.service erp-backup-restore-test.timer; do
  install -m 644 "$SRC/systemd/$u" "/etc/systemd/system/$u"
done
systemctl daemon-reload
systemctl enable --now erp-backup.timer erp-backup-restore-test.timer
systemctl list-timers 'erp-backup*' --no-pager
