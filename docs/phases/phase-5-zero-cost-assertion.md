[← Phase index](README.md)

# Explicit zero labour-cost assertion — separate research recommendation

**2026-10-16 · PROPOSED, within Q52; no zero-assertion feature or authority approved.**
Owner explicitly requests separate evidence/authority research. This is not ordinary coverage
declaration, an empty source list, a missing rate, an invoice waiver or payroll treatment.

## Problem and distinctions

A small owner says 'I incurred no labour expense on this visit.' That could be a truthful scoped
statement, but 'no cash paid yet', 'no hours recorded', 'rate missing' or 'customer supplied something'
does not establish zero incurred labour cost. We must not hide deferred wages/invoices/commitments,
unvalued recognised usage, disputes or deliberately excluded source categories.

Distinguish a supported source with an explicit valid zero rate/amount, a category-level assertion
of no incurred expense, and an unsupported system empty-list zero. Only the first is already
compatible with0110's known-zero certainty; the category assertion needs its own approved protocol.
Operational cost basis is not opportunity cost, cash paid, payroll-final cost or total Job cost.

## Bounded primary comparison — accessed2026-10-16

- [Business Central project usage](https://learn.microsoft.com/en-gb/dynamics365/business-central/projects-how-record-job-usage)
  records quantities/costs and creates ledger effects from posting; it does not establish that no
  posted sources means all real expenses are zero.
- [ERPNext project expenses](https://docs.frappe.io/erpnext/project-expense-claims) updates project
  expense totals from claims, demonstrating a captured-source aggregate, not complete expense discovery.
- [Odoo19 timesheet source](https://github.com/odoo/odoo/blob/19.0/addons/hr_timesheet/models/hr_timesheet.py)
  uses employee cost and includes an hourly-cost fallback to zero. This implementation difference
  is precisely not copied: our accepted missing-cost certainty forbids treating absent rates as zero.

Convergence: amounts come from recorded/posted sources. Difference: cost fallback and workflow
packaging vary. These sources do not supply a universal category-zero assertion or its permissions.
Our recommended authority/evidence threshold is an explicit project-specific inference from0146,
0110 and R9, requiring owner choice; not a vendor guarantee or accounting/legal advice.

## Options and recommendation

| Option | Benefit / failure case |
|---|---|
| No category zero assertion; keep unassessed | Safest narrow first release, but cannot express a legitimate no-expense declaration |
| Ordinary coverage declaration automatically yields zero | Easy, but grants monetary certainty without appropriate evidence; reject |
| Explicit separately eligible zero assertion | Honest small-business assertion with limited basis; needs reason/evidence and source conflict checks |
| Independent approval for every zero | Stronger checking but disproportionate as universal policy; does not itself prove facts |

Recommend the third as a separately reviewed later capability, not an initial prerequisite. Require
explicit zero-assertion operation eligibility beyond mere expense recording or ordinary coverage
declaration. The same eligible person may perform it; no universal maker/checker. A MANAGED policy
can require review through existing Approvals. Exact permission name/holders are not proposed grants.

Minimum evidence: Job/category/scope/through-date/valuation basis, accountable actor/recording time,
explicit statement of no incurred labour expense, reason/supporting source or authorised internal
declaration, current source generation and unresolved-source checks. Do not invent workers/hours.
Known pending liabilities, omitted expense, unpriced usage, contradictory records or unsupported
visibility/basis refuse the assertion rather than force every unknown to zero. If claiming an
unpriced usage source is truly zero, establish its legitimate supported valuation first.

Proposed result: 'DECLARED ZERO labour expense within [scope/date/basis]', preserving the human
basis; no fake qty1/zero-cost expense source. Never label it CONFIRMED without authoritative bounded
reconciliation. It cannot assert other Job categories are zero or bypass source/Finance authority.
Current totals based on it must disclose declaration basis, not general final cost certainty.

New contradictory/material evidence invalidates current zero use and triggers reassessment while
retaining originals. Historical changes use append-only correction, not source deletion or Job
restart. Self-declaration is not proof of wage/payment obligations being extinguished.

## Failure probes and owner choice

No rows, missing rate, unpaid invoice, outstanding labour source, filtered source scope or a mere
coverage declaration cannot produce zero. Legitimate zero evidence with separately approved authority
could produce declared zero only. New relevant evidence removes current zero validity. A source-level
known zero does not itself prove complete category coverage.

Owner choice: keep explicit zero assertion out of first release until separately approved; if wanted,
approve/revise separate operation eligibility, required reason/source checks, declared-only result and
applicable managed review. Ordinary coverage/completion/direct-cost paths continue without it.

## Related

- [Refined resolution](phase-5-coverage-material-resolution.md) · [Q52](../OPEN-QUESTIONS.md#q52--simple-labour-cost-coverage-declaration)
- [Coverage](phase-5-cost-coverage-review.md) · [Labour](../domain/job-labour-recognition.md)
- [0110 certainty](../domain/material-cost-resolution.md) · [R9](../domain/sales-duty-matrix.md)
