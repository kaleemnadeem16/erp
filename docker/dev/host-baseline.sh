#!/bin/sh
# One line of host/WSL health + test timings, appended to /tmp/erp-test-locks/baseline.csv (override: ERP_BASELINE_FILE). Read-only apart from
# running one tiny focused test on your own test database. Run it a few times a day during normal work to see whether host paging is
# still hurting before anyone changes .wslconfig:
#
#   docker/dev/host-baseline.sh [label]       # e.g. "idle", "two agents testing"
#
# Columns: time, label, WSL available MB, WSL swap used MB, WSL memory/io pressure (avg10), load1, Windows free GB, Windows Pages/sec
# (reads + writes, includes ordinary file reads: NOT a pressure gauge alone), Windows Page Writes/sec and Pages Output/sec (the pagefile
# signal: sustained values above 0 mean memory is being pushed out), pagefile % used, vmmemWSL resident/private MB, runner start-up s
# (docker/backend/run.sh php -v), focused test wall s (3 tests).
. "$(dirname "$0")/../backend/test-lib.sh"
OUT="${ERP_BASELINE_FILE:-$ERP_LOCK_DIR/baseline.csv}"
[ -f "$OUT" ] || echo "time_utc,label,wsl_avail_mb,wsl_swap_mb,psi_mem,psi_io,load1,win_free_gb,win_pages_s,win_page_writes_s,win_pages_output_s,pagefile_pct,vmmem_res_mb,vmmem_priv_mb,runner_start_s,focused_test_s" > "$OUT"
avail=$(free -m | awk '/Mem:/ {print $7}'); swap=$(free -m | awk '/Swap:/ {print $3}')
pm=$(awk '/some/ {sub("avg10=","",$2); print $2}' /proc/pressure/memory); pi=$(awk '/some/ {sub("avg10=","",$2); print $2}' /proc/pressure/io)
load=$(cut -d' ' -f1 /proc/loadavg)
win=$(timeout 60 powershell.exe -NoProfile -Command '$o=Get-CimInstance Win32_OperatingSystem; $c="\Memory\Pages/sec","\Memory\Page Writes/sec","\Memory\Pages Output/sec","\Paging File(_Total)\% Usage"; $s=Get-Counter $c -SampleInterval 1 -MaxSamples 3; $m=@{}; foreach ($x in $s) { foreach ($y in $x.CounterSamples) { $m[$y.Path.Split("\")[-1]]+=$y.CookedValue/3 } }; $w=Get-Process vmmemWSL -ErrorAction SilentlyContinue; [string]::Format([Globalization.CultureInfo]::InvariantCulture,"{0:F1},{1:F0},{2:F0},{3:F0},{4:F0},{5},{6}",($o.FreePhysicalMemory/1MB),$m["pages/sec"],$m["page writes/sec"],$m["pages output/sec"],$m["% usage"],[int]($w.WorkingSet64/1MB),[int]($w.PrivateMemorySize64/1MB))' 2>/dev/null | tr -d '\r' | tail -1)
[ -n "$win" ] || win=",,,,,,"
t0=$(date +%s.%N); ERP_SKIP_VENDOR_CHECK=1 "$ERP_ROOT/docker/backend/run.sh" php -v >/dev/null 2>&1; t1=$(date +%s.%N)
rs=$(echo "$t1 - $t0" | bc)
t0=$(date +%s.%N); "$ERP_ROOT/docker/backend/run-tests-tmpfs.sh" --filter=TestingEnvironmentIsolation >/dev/null 2>&1; t1=$(date +%s.%N)
ft=$(echo "$t1 - $t0" | bc)
line=$(printf '%s,%s,%s,%s,%s,%s,%s,%s,%.1f,%.1f' "$(date -u +%FT%TZ)" "$(printf '%s' "${1:-}" | tr -d ',\n')" "$avail" "$swap" "$pm" "$pi" "$load" "$win" "$rs" "$ft")
echo "$line" >> "$OUT"; echo "$line"
