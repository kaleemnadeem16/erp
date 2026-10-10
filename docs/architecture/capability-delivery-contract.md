[← Architecture](README.md)

# Capability delivery contract

Apply when designing a configurable capability or exposing one in a screen. This operationalises
[progressive capability modes](extensibility.md#progressive-capability-modes); it does not introduce
a new flag engine, subscription scheme or global small/large-company switch. Choose capabilities
by business need: a small company may require sophisticated controls, and a large one may have
a simple workflow.

## Classify the decision

| Concern | Owner and mechanism | Effect |
|---|---|---|
| Essential behaviour | Shared domain contract | Safe baseline workflow; never an optional security/integrity rule |
| Business mode or policy | Governed company/module configuration | Changes execution, requirements or authoritative facts |
| Occasional input override | Form advanced disclosure | Reveals an optional input with an explicit default; does not activate a company policy |
| Presentation preference | User view/column preference | Changes layout without changing business execution or access |
| Access | Existing capability and record scope | Controls what the person may read/do within the enabled business behaviour |

Keep domain support, company enablement, disclosure/preferences and permissions separate. Prefer
a small number of cohesive module modes over unrelated flags. Use the existing base permission
unless a new sensitivity or duty warrants a narrower one. Company-wide enablement is the current
scope; narrower enablement needs a separately settled organisational design, not scattered UI flags.

## Research and specification

For a new mode, or an unresolved scale/activation decision, follow
[research-and-design.md](research-and-design.md). Compare two to four relevant mature systems from
primary sources, or reuse sufficient existing research. Ask specifically: what complexity is
introduced, who needs it, how is it activated safely, and what remains understandable in the
simple experience? Compare prerequisites, dependencies, permissions, audit, existing-record
handling, downgrade constraints and usability at scale. Record access dates, alternatives,
applicability and uncertainties with the governing ADR/domain decision. Do not copy a vendor's
company-size labels or introduce speculative enterprise infrastructure.

Before implementation, record for each mode:

- Safe default, business need and baseline/advanced workflow; which data/configuration activates it.
- Prerequisites and dependencies, setup sequence, validation, and what happens if setup is incomplete.
- Who can enable/change it, required reason, audit/history and effective timing; distinguish
  consequential policy changes from harmless layout preferences.
- Existing-record handling, backfill/migration if needed, and whether changes apply to new drafts,
  existing drafts or future operations. Preserve issued snapshots and authoritative history.
- Whether disabling/downgrading is reversible, the incompatible-data checks and the recovery path.
  Refuse unsafe changes; never discard or reinterpret in-use configuration silently.
- The UI coverage matrix for forms, details, columns/cards, filters, actions, summaries and relevant
  print/export projections in each mode; explicitly identify future/deferred exposure.

Enablement/setup must be understandable to an authorised administrator: explain what changes,
what is required and any irreversible step. An enabled capability's intended operational workflow
must be complete across its affected screens, not merely an extra checkbox in Settings.

## Presentation without loss of meaning

Companies that never enable an optional capability do not see irrelevant controls, columns or
messages. Within an enabled capability, reveal occasional inputs progressively through the current
shared disclosure pattern; essential decisions and actionable warnings remain visible.

Existing advanced values automatically reveal the relevant form group. Detail always explains
advanced facts used by that record, subject to read permissions, even when a user's normal view is
simple. A preference must not conceal the effect of an active policy, pretend recorded values are
defaults, or change execution. Do not reset saved policy/configuration when closing a disclosure;
any intentional reset of editable draft overrides is explicit and follows the form's documented
default/reset contract. Permission-protected facts stay protected on every projection.

Resolve mode/configuration through shared typed contracts and feature hooks (the existing
`useItemPackageMode` pattern); reuse company/module settings, permission hooks and generated client.
The backend enforces the same mode, permissions and requirements for every caller. Avoid separate
simple/enterprise domain implementations and scattered per-screen flags. Use typed settings for
business policy and bounded preferences for presentation, following Extensibility's storage rules.

## Verification and handoff

Cover default/off behaviour, enabled behaviour, incomplete prerequisites, permitted/restricted
users, saved advanced values, historical records and allowed/refused downgrade. Check complete UI
coverage and consistent enforcement through API/Action paths where applicable. Include representative
data beyond the first lookup page and mixed statuses; existing large-dataset rules still apply.
Prove enabling a mode does not alter unrelated companies or silently rewrite historical facts.

Document which modes and surfaces are delivered, deliberately deferred and unverified. Backend
ahead of UI remains legitimate when the separation is explicit; an enabled operational workflow
must not silently omit part of the exposure agreed for that slice.

## Related

- [Extensibility](extensibility.md) · [Research and design](research-and-design.md)
- [Screen delivery](screen-delivery-contract.md) · [UI delivery](user-interface.md)
- [Authorisation](authorization.md) · [Company settings](company-settings.md)
- [Completion checklist](feature-completion-checklist.md)
