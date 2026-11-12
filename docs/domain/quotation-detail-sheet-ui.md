[← Domain index](README.md)

# Quotation detail and review: list-side presentation

**Historical isolated preview, consolidated 2026-11-12 (#110).** Statements below about missing
endpoints/generated clients describe its original baseline. Current registered screens keep their
newer live contracts and access guards; these preview components remain unregistered. All further
work is on `develop` in the main checkout under [AGENTS.md](../../AGENTS.md).


A viewer opens a permitted quotation from the list and sees its metadata; a preparer can independently open the financial review. The list remains mounted behind a read-only `RecordSheet`, while its URL-backed filters are preserved when opening, switching between detail and review, and closing. Detail requires quotation view; review requires quotation prepare independently. Closing by button, Escape or outside click returns to the filtered list without a write. Invalid, hidden and missing records use the existing non-disclosing unavailable state. No permission, financial certainty or totals are inferred from the list.

The sheet is a second presentation of the existing generated GET-backed detail/review renderer. A direct deep link keeps the existing full page; the list's future child routes may choose the side sheet when the authorised list endpoint and its generated client exist. Until then no new route or navigation is registered. The sheet uses the shared XL record layout, wraps actions, supports phone width and keeps loading/error/refresh behavior unchanged. It introduces no issue, approval, document or edit action. Future wiring must carry the same production-off development gate, independently check view/prepare, and test current record scope on the backend.


## Related

- [Domain index](README.md)
- [Quotation lifecycle](quotation-lifecycle.md)
- [Screen delivery contract](../architecture/screen-delivery-contract.md)
