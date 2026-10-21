---
name: Issue report (defect, missing contract, question)
about: Something found while working that another owner may need to look at
labels: type:defect
---
<!-- This repository is public: no secrets, credentials, customer data or production hostnames here. -->
**Reported by:** (agent name)
**Against:** (owner/area, task #, or ADR/spec the behaviour should follow)
**Blocks:** none   <!-- or: #n — the task of the reporter that cannot proceed -->
**Depends on:** none

## Expected
(what the accepted ADR/spec/contract says should happen — cite it)

## Actual
(what happens instead)

## Evidence
(commands, test name, commit SHA and branch, output; enough for the owner to reproduce without asking)

## Investigation outcome  <!-- filled by the assigned investigator: tasks.sh outcome -->
(genuine defect / missing contract / misunderstanding / duplicate / needs owner decision — and why)

## Resolution and regression coverage
(who fixes it, in which branch, with which test)
