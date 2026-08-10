[← Decisions](README.md)

# 0026 — English-only UI, no RTL support

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** frontend (all phases with UI)

## Context

[Q5](../OPEN-QUESTIONS.md) asked whether Urdu, or any right-to-left language, is needed. RTL is
far cheaper to design in from the start (logical CSS properties, mirrored layout, RTL-aware
component testing) than to retrofit once components assume left-to-right, so it needed answering
before heavy UI work in Phase 4.

## Decision

English-only. No RTL investment now — standard LTR CSS and component patterns throughout.

## Consequences

- **Positive:** simpler styling, no RTL testing burden, no logical-property discipline to
  enforce across the frontend.
- **Negative:** if RTL is ever needed later, it means revisiting layout and styling that assumed
  LTR — a real but currently unneeded cost.
- **Requires:** nothing further; this closes the question.

## Alternatives considered

**Design RTL-ready "just in case"** — rejected. No stated need, and the project's own
extensibility principle ([extensibility.md](../architecture/extensibility.md)) is to use the
cheapest rung that works, not build for a hypothetical requirement.

## Related

- Q5 — Localisation and RTL *(resolved, removed from open questions)*
