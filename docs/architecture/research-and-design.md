[← Architecture](README.md)

# Research before ERP feature design

ERP features encode business controls, not only screens and tables. A plausible implementation can
still be structurally too small if it was designed from the current code alone. Conversely,
re-researching a settled topic wastes time and can produce a second, conflicting interpretation.
This document defines the evidence gate between an idea and an implementation.

## Reuse first

Before browsing, search the relevant domain document, accepted ADRs, open questions, phase plan and
module README. Existing research is sufficient when it:

- answers the same business question and scale boundary;
- uses authoritative sources or clearly records why primary sources were unavailable;
- records meaningful alternatives and the chosen project-specific consequence; and
- has not been invalidated by a later ADR, changed requirement or materially changed product.

When those conditions hold, link the existing record and implement it. Do not perform ceremonial
research merely to reproduce the same comparison. A new screen or endpoint that exposes an already
decided model normally needs no new market research.

## When fresh research is required

Research before committing a model or workflow when at least one is true:

- the capability is new to the project and has durable business semantics;
- the change introduces a lifecycle, approval, correction, audit, security or accounting rule;
- mature ERPs plausibly solve the problem in materially different ways;
- a schema choice would make future organisational, multi-company or integration scale expensive;
- the existing documentation names the topic but does not answer the implementation question; or
- an agent would otherwise be choosing behaviour from intuition.

Purely local mechanics—copy changes, a bug whose intended behaviour is already documented, an
index supporting an existing query, or wiring a decided API into a screen—do not trigger fresh ERP
comparison.

## Bounded research method

1. Write the exact question before searching. “Research user management” is too broad; “how do
   mature ERPs invite an internal user, bundle privileges and prevent privilege escalation?” is
   actionable.
2. Compare two to four relevant mature systems. Prefer official product documentation, standards,
   legislation and vendor architecture guides. Use community posts only to fill a documented gap,
   never as the sole basis for a consequential rule.
3. Capture convergence and divergence. Product names alone are not evidence; identify the shared
   invariant, meaningful alternatives, scale assumptions and failure modes.
4. Translate rather than copy. Our modular monolith, company scope, additive permissions and
   progressive-capability strategy remain constraints even when another ERP uses different names
   or a larger hierarchy.
5. Stop when the evidence distinguishes the realistic alternatives and exposes the scale seam.
   Research is not an attempt to catalogue every ERP or duplicate vendor manuals.

For high-stakes legal, tax, payroll, accounting or security behaviour, verify current authoritative
guidance at implementation time even when an older comparison exists.

## Record once, where later work will find it

Durable findings belong with the decision they support:

- an ADR contains the question, synthesis, alternatives and direct sources;
- the domain doc contains the resulting model and links to the ADR;
- the phase doc contains the delivery tasks and acceptance criteria, not the research transcript;
- `OPEN-QUESTIONS.md` holds only unresolved choices; and
- `STATUS.md` records completion, not design detail.

A research basis records the access date, products or standards compared, direct links, common
pattern, important differences, applicability to this ERP and remaining uncertainty. Avoid long
quotes and screenshots that age badly. The goal is for a later agent to determine quickly whether
the evidence still answers its question.

## Implementation gate

Do not implement an irreversible or expensive-to-migrate business shape until one of these is
true:

1. the implementation links to sufficient existing research and its governing decision; or
2. fresh research has been synthesised into a proposed/accepted ADR and the related domain/phase
   documents identify the resulting scope.

If delivery must deliberately precede a decision, build only a reversible foundation and label it
as reserved—not an implied business contract. A prototype or vendor pattern does not silently
become project policy.

## Review checklist

- Is the researched question precise?
- Was adequate existing research reused?
- Are fresh sources primary and directly linked where possible?
- Does the record explain differences, not just list products?
- Does the decision say what scales later and what is deliberately deferred?
- Are rejected alternatives and unresolved questions visible?
- Can the next agent implement without repeating the search or guessing missing semantics?

## Related

- [Documentation map](../README.md)
- [Authorisation](authorization.md)
- [Extensibility](extensibility.md)
- [ADR template](../templates/adr.md)
- [Phase template](../templates/phase.md)
