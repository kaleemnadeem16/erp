#!/usr/bin/env bash
# Regression test for `tasks.sh claim` ownership rules. Offline: a fake `gh` (below) keeps one issue's labels and body in a temp dir, so
# nothing touches GitHub. Run: docker/dev/tests/tasks-claim.test.sh   (exit 0 = all passed)
#   1. a Ready task pre-assigned to the claiming agent can be claimed (regression: "already has an owner: codex");
#   2. a Ready task pre-assigned to someone else is still refused (ownership protection);
#   3. an unowned Ready task is claimed, and the claim leaves exactly one owner label and status:in-progress;
#   4. gated tasks are still refused.
set -u
HERE=$(cd "$(dirname "$0")" && pwd); TASKS="$HERE/../tasks.sh"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT; mkdir -p "$T/bin" "$T/locks"
cat > "$T/bin/gh" <<'PYEOF'
#!/usr/bin/env python3
# Minimal fake of the gh calls claim makes. State: $FAKE_GH_DIR/labels (one per line), body, comments.
import os, sys
d = os.environ["FAKE_GH_DIR"]; a = sys.argv[1:]
rd = lambda n: open(os.path.join(d, n)).read() if os.path.exists(os.path.join(d, n)) else ""
labels = [l for l in rd("labels").split("\n") if l]
def save(): open(os.path.join(d, "labels"), "w").write("\n".join(labels) + "\n")
if a[:2] == ["issue", "view"]:
    f = a[a.index("--json") + 1]
    print({"state": "OPEN", "labels": " ".join(labels), "body": rd("body")}[f].rstrip("\n"))
elif a[:2] == ["issue", "edit"]:
    i = 3
    while i < len(a):
        if a[i] == "--add-label":
            for l in a[i+1].split(","):
                if l and l not in labels: labels.append(l)
        elif a[i] == "--remove-label":
            labels = [l for l in labels if l not in a[i+1].split(",")]
        elif a[i] == "--body-file": open(os.path.join(d, "body"), "w").write(open(a[i+1]).read())
        i += 2
    save()
elif a[:2] == ["issue", "comment"]: open(os.path.join(d, "comments"), "a").write(a[a.index("--body") + 1] + "\n")
# `issue list` (workstream clash check) and `label create` print nothing and succeed.
PYEOF
chmod +x "$T/bin/gh"
fails=0
run() { # name expect(ok|refused) labels...  ; agent is "codex"
  local name="$1" expect="$2"; shift 2
  export FAKE_GH_DIR="$T/state"; rm -rf "$FAKE_GH_DIR"; mkdir -p "$FAKE_GH_DIR"
  printf '%s\n' "$@" > "$FAKE_GH_DIR/labels"
  printf '**Workstream:** phase5\n**Depends on:** none\n**Branch / workspace:** (set when claimed)\n' > "$FAKE_GH_DIR/body"
  out=$(PATH="$T/bin:$PATH" ERP_TEST_LOCK_DIR="$T/locks" "$TASKS" claim 19 --branch codex/test --as codex 2>&1); rc=$?
  if [ "$expect" = ok ] && [ $rc -eq 0 ]; then
    own=$(grep -c '^owner:' "$FAKE_GH_DIR/labels"); grep -qx 'status:in-progress' "$FAKE_GH_DIR/labels" && [ "$own" -eq 1 ] && grep -qx 'owner:codex' "$FAKE_GH_DIR/labels" \
      && echo "PASS $name" || { echo "FAIL $name: labels wrong: $(tr '\n' ' ' < "$FAKE_GH_DIR/labels")"; fails=$((fails+1)); }
  elif [ "$expect" = refused ] && [ $rc -ne 0 ]; then
    [ "$(grep -c '^owner:' "$FAKE_GH_DIR/labels")" -le 1 ] && ! grep -qx 'status:in-progress' "$FAKE_GH_DIR/labels" && echo "PASS $name" || { echo "FAIL $name: refused but state changed"; fails=$((fails+1)); }
  else echo "FAIL $name: expected $expect, rc=$rc: $out"; fails=$((fails+1)); fi
}
run "pre-assigned to the claimer"        ok      status:ready type:task ws:phase5 owner:codex
run "pre-assigned to someone else"       refused status:ready type:task ws:phase5 owner:agent-a
run "unowned ready task"                 ok      status:ready type:task ws:phase5
run "gated by owner decision"            refused status:ready type:task ws:phase5 owner:codex needs:owner-decision
run "not ready (blocked)"                refused status:blocked type:task ws:phase5 owner:codex
[ $fails -eq 0 ] && echo "all passed" || { echo "$fails failed"; exit 1; }
