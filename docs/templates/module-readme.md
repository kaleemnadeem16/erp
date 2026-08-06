# Module Name

One or two sentences: what this module owns.

**Governed by:** [docs/domain/<file>.md](../../../docs/domain/) — the domain rules this implements.

## Owns

- The concepts and tables this module is responsible for

## Does not own

- Things people reasonably assume live here but don't, with a pointer to where they do

This section prevents more wasted searching than the one above it.

## Key actions

| Action | Purpose |
|---|---|
| `CreateThing` | … |
| `ConfirmThing` | Transactional. Emits `ThingConfirmed`. |

Not every action — the ones someone would look for.

## Events

**Emits**

| Event | When |
|---|---|
| `ThingConfirmed` | … |

**Listens for**

| Event | From | Reaction |
|---|---|---|
| `OtherThingHappened` | Sales | … |

This table is the module's real interface to the rest of the system
([code-structure.md](../../../docs/architecture/code-structure.md) — modules do not call each
other directly).

## Non-obvious

Where the surprising logic lives and why it is surprising. Concurrency, ordering constraints,
anything where the straightforward reading is wrong.

Keep this short. If it grows long, the code probably needs simplifying rather than explaining.

## Related

- [Domain doc](../../../docs/domain/)
- [Relevant ADRs](../../../docs/decisions/)

---

<!--
RULES (see docs/decisions/0022-code-documentation-strategy.md)
- Update when the module's RESPONSIBILITIES change — not when a file is added.
- Do not list files. File-level detail belongs in a header docblock, or the file needs renaming.
- Target ~30 lines. Longer means the module is doing too much.
-->
