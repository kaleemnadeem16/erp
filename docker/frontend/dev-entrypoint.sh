#!/bin/sh
set -eu

# Named volumes start as root-owned; workspace caches may remain root-owned from
# older containers. This initializer exits before the non-root service starts.
host_uid="${HOST_UID:-1000}"
host_gid="${HOST_GID:-1000}"

for directory in \
    /app/node_modules \
    /app/apps/*/node_modules \
    /app/packages/*/node_modules \
    /app/packages/core-api/src/generated; do
    if [ -e "$directory" ]; then
        chown -R "$host_uid:$host_gid" "$directory"
    fi
done
