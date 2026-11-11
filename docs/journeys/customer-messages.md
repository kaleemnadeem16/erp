[← Journeys](README.md)

# Record what the customer sent

- **Implementation status:** implemented (acceptance slice 1); gated off outside local development, both duties STAGED (nobody holds them)
- **Audience:** sales administrator who records what customers send; a sales reader who checks the record
- **Starting point:** a customer has sent a purchase order, email, signature or verbal confirmation about an issued quotation
- **Successful end state:** the message is on record as an observation, with who recorded it and when. Nothing is accepted
- **Last verified:** 2026-11-11 (feature and browser tests; no production use)

## Purpose

Customers answer quotations in many ways. This records what arrived, truthfully and append-only, so a later acceptance can point at it. **Recording a message is not accepting an offer:** it does not
hold the offer, reserve stock or stop the offer expiring or being withdrawn, and the screens say so. Accepting is a [separate backend operation](record-full-customer-acceptance.md) with its own inactive duty; its screen is not delivered.

## Before you start

Your administrator must give you `sales.customer-decision.view` to see cases and `sales.customer-decision.evidence.record` to add to them; the screens offer recording only to someone who holds both.
Until a holder recipe is reviewed the duties are staged and the menu entry does not appear. Demo data (a purchase order with one observation and its correction) exists only in the demo company.

## Steps

1. Open **Customer Messages** (Sales menu). Search by the customer's reference if the list is long.
2. **Record a customer message:** choose the customer and enter the reference they used (for example their purchase order number). A reference that already exists is never merged: open that case.
3. On the case page choose **Add an observation:** what arrived, how the sender's authority was checked, when it happened (a day, a time, a period, or *not known*: unknown stays unknown) and a plain
   description of what was sent. To fix a mistake, add a new observation that corrects the earlier one; the earlier one stays visible, marked as corrected.
4. If someone else added to the case meanwhile, the page says it changed, reloads, and you add your observation again. A lost connection is safe to retry: the same request is never recorded twice.

## What the system did

A case and its observations, each stamped with the recorder and the database clock; nothing else changed. Quotations, opportunities, stock and prices are untouched.

## Related

- [Domain: Sales customer decisions](../domain/sales-customer-decisions.md)
- [Design brief: customer acceptance and handover](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
