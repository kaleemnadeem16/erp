# Off-site destinations

`backup.sh` calls every **executable `*.sh`** in this directory after a successful local backup, passing the path of
the new dump as the only argument (`$1`). The checksum and manifest sit next to it (`$1.sha256`, `${1%.dump}.json`).

Contract:
- exit 0 = the copy is safely stored; non-zero = it failed. A failure is logged and shown in the metrics; the other
  destinations still run and the local copy is **never** deleted because a destination failed;
- read your own settings from environment variables (put them in `backup.env`, mode 600, never in git);
- the dump holds every table, including password hashes: **encrypt it before it leaves the host** (see the example).

Files here that do not end in `.sh` or are not executable are ignored, so nothing runs by accident.
To add Google Drive or any cloud storage: copy `rclone.sh.example` to `rclone.sh`, `chmod +x`, configure the remote
with `rclone config`, set the variables it names, and run `backup.sh` once.
