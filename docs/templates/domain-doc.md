[← Domain index](README.md)

# Topic name

One paragraph: what this models and why it exists. If a reader loads only this file, they
should understand the concept without needing the others.

## Where it sits

How this relates to neighbouring concepts — ideally a small diagram or a one-line chain
showing what feeds into it and what it feeds.

## Schema

```
table_name
  id, company_id
  column           type / enum values      -- inline note where useful
  ...
```

Annotated pseudo-DDL, not migration code. It stays readable and doesn't drift with framework
versions. Note nullable columns and their meaning — nullability usually encodes a rule.

## Behaviour

Rules that aren't visible in the schema: resolution order, invariants, what happens on state
change, what is immutable.

## Authorisation and data scope

Permissions for read/write/transition/override/history; which records and sensitive fields are
visible; and any maker/checker or multi-stage approval requirements. Explicitly say when no
separate permission is intended.

## Integrity and refusals

For any write that changes business facts, say how it is idempotent (the request key), what it locks and in what order, what makes it stale, which invariants the database enforces, and which refusals carry a stable
`reason_code` ([domain-integrity.md](../architecture/domain-integrity.md), [api-conventions.md](../architecture/api-conventions.md#refusal-reason-codes)). Write "not applicable" if the topic is read-only.

## Landmines

Things that are easy to get wrong and expensive to fix. Be specific about the failure mode, not
just the rule — "X breaks when Y" is more useful than "always do X".

## Related

- [Neighbouring domain doc](other.md)
- [ADR that decided this](../decisions/NNNN-slug.md)

---

<!--
RULES
- One topic per file. Over ~300 lines means it covers more than one — split it.
- Every file links back to the index and to related topics. No orphans.
- Update this file in the same commit as any schema or behaviour change.
-->
